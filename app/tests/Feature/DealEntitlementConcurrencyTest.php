<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\RejectionStatus;
use App\Models\CompanyMaster;
use App\Models\CustomerMaster;
use App\Models\DealCondition;
use App\Models\DealQualifier;
use App\Models\DealReward;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\EmployeeMaster;
use App\Models\PriceCondition;
use App\Models\PriceConditionItem;
use App\Models\ProductMaster;
use App\Models\SalesOrderItem;
use App\Services\DeliveryService;
use App\Services\InventoryService;
use App\Services\SalesOrderService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * REAL MariaDB row-locking concurrency coverage for the DEAL/free entitlement.
 *
 * Deliberately does NOT use DatabaseTransactions (a wrapping test transaction
 * would isolate this connection and make FOR UPDATE meaningless): fixtures use
 * CFE.-prefixed ids and are purged in tearDown().
 *
 * The child process opens its OWN connection, holds `SELECT ... FOR UPDATE` on
 * the sales_order row and COMMITS a competing free allocation before releasing
 * it. The parent then runs the real DeliveryService::createAndAllocate for a
 * second free line of the SAME parent/deal/reward product — it must block on
 * the order lock and then be refused, because the child's committed free
 * allocation already consumed the single earned deal entitlement. Two
 * deliveries can therefore never both consume one free reward.
 */
class DealEntitlementConcurrencyTest extends TestCase
{
    private const PREFIX_COMPANY = 'CFE.';

    private int $childPid = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purgeResidue();
    }

    protected function tearDown(): void
    {
        if ($this->childPid > 0) {
            if (function_exists('posix_kill')) {
                @posix_kill($this->childPid, SIGKILL);
            } else {
                @exec('kill -9 '.(int) $this->childPid);
            }
            pcntl_waitpid($this->childPid, $status, WNOHANG);
            $this->childPid = 0;
        }

        $this->purgeResidue();

        parent::tearDown();
    }

    public function test_two_deliveries_cannot_consume_the_same_free_entitlement(): void
    {
        $hex = bin2hex(random_bytes(4));
        $companyId = self::PREFIX_COMPANY.$hex;
        $dealNo = 'CFED.'.$hex;

        $company = CompanyMaster::factory()->create(['company_id' => $companyId]);
        $employee = EmployeeMaster::factory()->create([
            'employee_id' => 'CFEE.'.$hex,
            'company_id' => $companyId,
        ]);
        $primary = CustomerMaster::factory()->primary()->forEmployee($employee)
            ->create(['customer_id' => 'CFEP.'.$hex]);
        $soldTo = CustomerMaster::factory()->forEmployee($employee)
            ->create(['customer_id' => 'CFEC.'.$hex]);
        $product = ProductMaster::factory()->forCompany($company)
            ->create(['product_id' => 'CFEQ.'.$hex, 'basic_unit' => 'PCS']);

        PriceCondition::create([
            'condition_price_no' => 'PC-'.$companyId,
            'company_id' => $companyId,
            'sales_region' => null,
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'active' => true,
        ]);
        PriceConditionItem::create([
            'condition_price_no' => 'PC-'.$companyId,
            'product_id' => $product->product_id,
            'price' => '1000.00',
            'currency' => 'NGN',
            'tax_type' => 'NONE',
        ]);

        app(InventoryService::class)->adjustPhysical(
            $employee, $primary->customer_id, $product->product_id, '100', 'PCS', MovementType::GOODS_RECEIPT,
        );

        // Deal 12 PCS → 1 PCS, repeating per 12 PCS.
        DealCondition::create([
            'deal_no' => $dealNo,
            'company_id' => $companyId,
            'deal_description' => 'concurrency deal',
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'active' => true,
        ]);
        DealQualifier::create([
            'deal_no' => $dealNo,
            'product_id' => $product->product_id,
            'minimum_qty' => '12',
            'qualifier_unit' => 'PCS',
        ]);
        DealReward::create([
            'deal_no' => $dealNo,
            'product_id' => $product->product_id,
            'reward_qty' => '1',
            'reward_unit' => 'PCS',
            'for_each_qty' => '12',
            'for_each_unit' => 'PCS',
        ]);

        $orders = app(SalesOrderService::class);

        $order = $orders->createDraft(
            $employee,
            $primary->customer_id,
            $primary->customer_id,
            $soldTo->customer_id,
            collect([[
                'product_id' => $product->product_id,
                'qty' => '12',
                'unit' => 'PCS',
                'unit_price' => null,
                'price_override_reason' => null,
            ]]),
        )['order'];

        $this->assertSame([], $orders->confirm($order)['conflicts']);

        $order = $order->fresh(['items']);
        $parent = $order->items->firstWhere('item_no', 1);
        $free = $order->items->firstWhere('item_no', 2);

        $this->assertNotNull($free);
        $this->assertTrue((bool) $free->is_free_item);

        // A duplicate free line for the SAME parent/deal/reward product: the
        // two lines SHARE one earned entitlement, which is exactly the race.
        $duplicate = new SalesOrderItem([
            'product_id' => $product->product_id,
            'line_source' => $free->line_source,
            'is_free_item' => true,
            'parent_item_no' => 1,
            'order_qty' => '1.000',
            'order_unit' => 'PCS',
            'unit_price' => '0.00',
            'deal_no' => $dealNo,
            'rejection_status' => RejectionStatus::NONE,
        ]);
        $duplicate->sales_order_no = $order->sales_order_no;
        $duplicate->item_no = 3;
        $duplicate->save();

        // The paid parent earns exactly one free PCS.
        $deliveries = app(DeliveryService::class);
        $deliveries->createAndAllocate(
            $order->fresh(['items']),
            $employee,
            collect([['sales_order_item_no' => 1, 'qty' => '12', 'unit' => 'PCS', 'transit_qty' => '0']]),
        );

        // ---- Child: holds the ORDER lock and commits a competing free item ----
        $signal = tempnam(sys_get_temp_dir(), 'dealent');
        $scriptResource = tmpfile();
        fwrite($scriptResource, $this->childScript());
        $scriptPath = stream_get_meta_data($scriptResource)['uri'];
        $basePath = dirname(__DIR__, 2);

        $payload = json_encode([
            'sales_order_no' => $order->sales_order_no,
            'company_id' => $companyId,
            'source_customer_id' => $primary->customer_id,
            'customer_id' => $soldTo->customer_id,
            'created_by' => $employee->employee_id,
            'product_id' => $product->product_id,
            'delivery_no' => 'DEL-CFE-'.$hex,
        ]);

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'pcntl_fork failed.');

        if ($pid === 0) {
            pcntl_exec(PHP_BINARY, [$scriptPath, $signal, $basePath, $payload]);
            exit(127);
        }

        $this->childPid = $pid;

        $locked = false;

        for ($i = 0; $i < 100; $i++) {
            if (is_file($signal) && str_starts_with((string) file_get_contents($signal), 'LOCKED')) {
                $locked = true;
                break;
            }
            usleep(50_000);
        }

        $this->assertTrue($locked, 'The competing process never acquired the order row lock.');

        // ---- Parent: the racing allocation of the OTHER free line ------------
        $start = microtime(true);
        $refused = null;

        try {
            $deliveries->createAndAllocate(
                $order->fresh(['items']),
                $employee,
                collect([['sales_order_item_no' => 3, 'qty' => '1', 'unit' => 'PCS', 'transit_qty' => '0']]),
            );
            $this->fail('The racing free allocation was NOT refused.');
        } catch (HttpException $e) {
            $refused = $e;
        }

        $blockedFor = microtime(true) - $start;

        $this->assertSame(422, $refused->getStatusCode());
        $this->assertStringContainsString('deal entitlement', $refused->getMessage());
        $this->assertGreaterThan(
            1.0,
            $blockedFor,
            'The racing allocation returned instantly — it did NOT serialize on the order lock.',
        );

        @pcntl_waitpid($pid, $status, WNOHANG);
        $this->childPid = 0;
        @unlink($signal);

        // Exactly ONE free item exists: the entitlement was consumed once.
        $this->assertSame(1, DeliveryItem::where('sales_order_no', $order->sales_order_no)
            ->where('is_free_item', true)
            ->where('sales_order_item_no', 2)
            ->count());
        $this->assertSame(0, DeliveryItem::where('sales_order_no', $order->sales_order_no)
            ->where('sales_order_item_no', 3)
            ->count(), 'The losing allocation left no residue.');
        $this->assertSame(2, Delivery::where('company_id', $companyId)->count());

        // The order is untouched by the refused attempt.
        $this->assertSame('OPEN_DELIVERY', $order->fresh()->order_status->value);
    }

    /**
     * Standalone child bootstrap: own Laravel app, own PDO connection, FOR
     * UPDATE lock on the ORDER row, then a COMMITTED competing free delivery.
     */
    private function childScript(): string
    {
        return <<<'PHP'
<?php
require $argv[2].'/vendor/autoload.php';
$app = require $argv[2].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$payload = json_decode($argv[3], true);

Illuminate\Support\Facades\DB::beginTransaction();
Illuminate\Support\Facades\DB::table('sales_order')
    ->where('sales_order_no', $payload['sales_order_no'])
    ->lockForUpdate()
    ->get();

// Competing allocation: the free line's one earned PCS, committed while the
// other transaction is waiting on the same order row.
Illuminate\Support\Facades\DB::table('delivery')->insert([
    'delivery_no' => $payload['delivery_no'],
    'company_id' => $payload['company_id'],
    'sales_order_no' => $payload['sales_order_no'],
    'source_customer_id' => $payload['source_customer_id'],
    'customer_id' => $payload['customer_id'],
    'delivery_status' => 'ALLOCATED',
    'created_by' => $payload['created_by'],
    'allocated_at' => date('Y-m-d H:i:s'),
]);
Illuminate\Support\Facades\DB::table('delivery_item')->insert([
    'delivery_no' => $payload['delivery_no'],
    'item_no' => 1,
    'sales_order_no' => $payload['sales_order_no'],
    'sales_order_item_no' => 2,
    'product_id' => $payload['product_id'],
    'is_free_item' => 1,
    'allocated_qty' => '1.000',
    'delivery_unit' => 'PCS',
]);

file_put_contents($argv[1], 'LOCKED');
sleep(5); // hold the lock while the parent's allocation runs
Illuminate\Support\Facades\DB::commit();
PHP;
    }

    /** Remove every fixture row this class may have created (children-first). */
    private function purgeResidue(): void
    {
        $companyLike = self::PREFIX_COMPANY.'%';
        $customerLike = 'CFE%';
        $employeeLike = 'CFEE.%';

        DB::table('delivery_item')->whereIn('delivery_no', function ($q) use ($companyLike) {
            $q->select('delivery_no')->from('delivery')->where('company_id', 'like', $companyLike);
        })->delete();
        DB::table('delivery')->where('company_id', 'like', $companyLike)->delete();
        // The free line references its paid parent (self-referencing FK), so the
        // dependency is detached before the rows go.
        DB::table('sales_order_item')->whereIn('sales_order_no', function ($q) use ($companyLike) {
            $q->select('sales_order_no')->from('sales_order')->where('company_id', 'like', $companyLike);
        })->update(['parent_item_no' => null]);
        DB::table('sales_order_item')->whereIn('sales_order_no', function ($q) use ($companyLike) {
            $q->select('sales_order_no')->from('sales_order')->where('company_id', 'like', $companyLike);
        })->delete();
        DB::table('sales_order')->where('company_id', 'like', $companyLike)->delete();
        DB::table('inventory_movement')->where('company_id', 'like', $companyLike)->delete();
        DB::table('inventory')->where('customer_id', 'like', $customerLike)->delete();
        DB::table('customer_employee')->whereIn('customer_id', function ($q) use ($customerLike) {
            $q->select('customer_id')->from('customer_master')->where('customer_id', 'like', $customerLike);
        })->delete();
        DB::table('customer_master')->where('customer_id', 'like', $customerLike)->delete();
        DB::table('deal_reward')->where('deal_no', 'like', 'CFED.%')->delete();
        DB::table('deal_qualifier')->where('deal_no', 'like', 'CFED.%')->delete();
        DB::table('deal_condition')->where('deal_no', 'like', 'CFED.%')->delete();
        DB::table('price_condition_item')->whereIn('product_id', function ($q) use ($companyLike) {
            $q->select('product_id')->from('product_master')->where('company_id', 'like', $companyLike);
        })->delete();
        DB::table('price_condition')->where('condition_price_no', 'like', 'PC-CFE.%')->delete();
        DB::table('product_unit_conversion')->whereIn('product_id', function ($q) use ($companyLike) {
            $q->select('product_id')->from('product_master')->where('company_id', 'like', $companyLike);
        })->delete();
        DB::table('product_master')->where('company_id', 'like', $companyLike)->delete();
        DB::table('employee_master')->where('employee_id', 'like', $employeeLike)->delete();
        DB::table('company_master')->where('company_id', 'like', $companyLike)->delete();
    }
}
