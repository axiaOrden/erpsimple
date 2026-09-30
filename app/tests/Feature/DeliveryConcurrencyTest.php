<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Models\CompanyMaster;
use App\Models\CustomerMaster;
use App\Models\Delivery;
use App\Models\EmployeeMaster;
use App\Models\Inventory;
use App\Models\ProductMaster;
use App\Services\DeliveryService;
use App\Services\InventoryService;
use App\Services\SalesOrderService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * REAL MariaDB row-locking concurrency test.
 *
 * Deliberately does NOT use DatabaseTransactions: a wrapping test transaction
 * would isolate this connection from any other and make FOR UPDATE locking
 * meaningless. Fixtures are written with CLC.-prefixed IDs and purged in
 * tearDown() instead.
 *
 * The child process bootstraps a SECOND full Laravel app instance with its
 * own PDO connection, opens a transaction and holds `SELECT ... FOR UPDATE`
 * on the inventory row (simulating a competing allocation in flight). The
 * parent then runs the real DeliveryService::createAndAllocate, which must
 * block until the child's lock is released and must apply its check against
 * the child's committed values — proving no application-side pre-check race.
 */
class DeliveryConcurrencyTest extends TestCase
{
    private const PREFIX_COMPANY = 'CLC.';

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

    public function test_competing_allocation_cannot_overdraw_unrestricted_stock(): void
    {
        $hex = bin2hex(random_bytes(4));
        $companyId = self::PREFIX_COMPANY.$hex;
        $employeeId = 'CLCE.'.$hex;

        $company = CompanyMaster::factory()->create(['company_id' => $companyId]);
        $employee = EmployeeMaster::factory()->create([
            'employee_id' => $employeeId,
            'company_id' => $companyId,
        ]);
        $primary = CustomerMaster::factory()->primary()->forEmployee($employee)
            ->create(['customer_id' => 'CLCP.'.$hex]);
        $soldTo = CustomerMaster::factory()->forEmployee($employee)
            ->create(['customer_id' => 'CLCD.'.$hex]);
        $product = ProductMaster::factory()->forCompany($company)
            ->create(['product_id' => 'CLCQ.'.$hex, 'basic_unit' => 'PCS']);

        // Authoritative stock: 100 PCS unrestricted.
        app(InventoryService::class)->adjustPhysical(
            $employee, $primary->customer_id, $product->product_id, '100', 'PCS', MovementType::GOODS_RECEIPT,
        );

        // Confirmed demand: 100 PCS.
        $order = app(SalesOrderService::class)->createDraft(
            $employee,
            $primary->customer_id,
            $primary->customer_id,
            $soldTo->customer_id,
            collect([[
                'product_id' => $product->product_id,
                'qty' => '100',
                'unit' => 'PCS',
                'unit_price' => null,
                'price_override_reason' => null,
            ]]),
        )['order'];
        app(SalesOrderService::class)->confirm($order);

        // ---- Child: a competing transaction holds the row lock FOR UPDATE ----
        $signal = tempnam(sys_get_temp_dir(), 'conc');
        $scriptResource = tmpfile();
        fwrite($scriptResource, $this->childScript());
        $scriptPath = stream_get_meta_data($scriptResource)['uri'];
        $basePath = dirname(__DIR__, 2);

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'pcntl_fork failed.');

        if ($pid === 0) {
            // Child process: hold the lock, then let the parent proceed.
            pcntl_exec(PHP_BINARY, [$scriptPath, $primary->customer_id, $product->product_id, $signal, $basePath]);
            exit(127); // exec failed
        }

        $this->childPid = $pid;

        // Wait until the child actually HOLDS the FOR UPDATE lock.
        $locked = false;

        for ($i = 0; $i < 100; $i++) {
            if (is_file($signal) && str_starts_with((string) file_get_contents($signal), 'LOCKED')) {
                $locked = true;
                break;
            }
            usleep(50_000);
        }

        $this->assertTrue($locked, 'Child never acquired the inventory row lock.');

        // Parent: allocate with the REAL service while the row is locked
        // elsewhere. Must block until the child releases, then see the
        // child's rolled-back (unchanged) state and allocate normally.
        $start = microtime(true);

        $result = app(DeliveryService::class)->createAndAllocate(
            $order->fresh(['items']),
            $employee,
            collect([['sales_order_item_no' => 1, 'qty' => '40', 'unit' => 'PCS']]),
        );

        $blockedFor = microtime(true) - $start;
        $this->assertGreaterThan(
            1.0,
            $blockedFor,
            'Allocation returned instantly — it did NOT wait on the row lock; locking is not real.',
        );
        $this->assertSame('ALLOCATED', $result['delivery']->delivery_status->value);

        $inv = Inventory::where('customer_id', $primary->customer_id)
            ->where('product_id', $product->product_id)
            ->firstOrFail();
        $this->assertSame('60.000', (string) $inv->unrestricted_qty);
        $this->assertSame('40.000', (string) $inv->restricted_qty);

        // Sanitary lock release; child may already be gone.
        @pcntl_waitpid($pid, $status, WNOHANG);
        $this->childPid = 0;
        @unlink($signal);

        $this->assertSame(1, Delivery::where('company_id', $companyId)->count());
        $this->assertSame('OPEN_DELIVERY', $order->fresh()->order_status->value);
    }

    public function test_serial_allocations_are_exact_under_no_contention(): void
    {
        // Harness canary: without any locking counterpart, allocation behaves
        // exactly as in the transactional suite (residue purge works, no leakage).
        $hex = bin2hex(random_bytes(4));
        $companyId = self::PREFIX_COMPANY.$hex;
        $employeeId = 'CLCE.'.$hex;

        $company = CompanyMaster::factory()->create(['company_id' => $companyId]);
        $employee = EmployeeMaster::factory()->create(['employee_id' => $employeeId, 'company_id' => $companyId]);
        $primary = CustomerMaster::factory()->primary()->forEmployee($employee)
            ->create(['customer_id' => 'CLCP.'.$hex]);
        $soldTo = CustomerMaster::factory()->forEmployee($employee)
            ->create(['customer_id' => 'CLCD.'.$hex]);
        $product = ProductMaster::factory()->forCompany($company)
            ->create(['product_id' => 'CLCQ.'.$hex, 'basic_unit' => 'PCS']);

        app(InventoryService::class)->adjustPhysical(
            $employee, $primary->customer_id, $product->product_id, '100', 'PCS', MovementType::GOODS_RECEIPT,
        );

        $order = app(SalesOrderService::class)->createDraft(
            $employee,
            $primary->customer_id,
            $primary->customer_id,
            $soldTo->customer_id,
            collect([[
                'product_id' => $product->product_id,
                'qty' => '100',
                'unit' => 'PCS',
                'unit_price' => null,
                'price_override_reason' => null,
            ]]),
        )['order'];
        app(SalesOrderService::class)->confirm($order);

        app(DeliveryService::class)->createAndAllocate(
            $order->fresh(['items']),
            $employee,
            collect([['sales_order_item_no' => 1, 'qty' => '60', 'unit' => 'PCS']]),
        );

        $inv = Inventory::where('customer_id', $primary->customer_id)
            ->where('product_id', $product->product_id)
            ->firstOrFail();
        $this->assertSame('40.000', (string) $inv->unrestricted_qty);
        $this->assertSame('60.000', (string) $inv->restricted_qty);
        $this->assertSame('100.000', $inv->onHandQty());
    }

    /**
     * Standalone child bootstrap: own Laravel app, own PDO connection,
     * FOR UPDATE lock on the inventory row held for ~5 seconds.
     */
    private function childScript(): string
    {
        return <<<'PHP'
<?php
require $argv[4].'/vendor/autoload.php';
$app = require $argv[4].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$pdo = Illuminate\Support\Facades\DB::connection('mariadb')->getPdo();
$pdo->beginTransaction();
$stmt = $pdo->prepare(
    'select unrestricted_qty, restricted_qty from inventory where customer_id = ? and product_id = ? for update'
);
$stmt->execute([$argv[1], $argv[2]]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
file_put_contents($argv[3], 'LOCKED '.$row['unrestricted_qty'].'/'.$row['restricted_qty']);
sleep(5); // hold the lock while the parent's allocation runs
$pdo->rollBack();
PHP;
    }

    /** Remove every fixture row this class may have created (children-first). */
    private function purgeResidue(): void
    {
        $companyLike = self::PREFIX_COMPANY.'%';
        $customerLike = 'CLC%';
        $employeeLike = 'CLCE.%';
        $userLike = 'clcu.%';

        DB::table('delivery_item')->whereIn('delivery_no', function ($q) use ($companyLike) {
            $q->select('delivery_no')->from('delivery')->where('company_id', 'like', $companyLike);
        })->delete();
        DB::table('delivery')->where('company_id', 'like', $companyLike)->delete();
        DB::table('sales_order_item')->whereIn('sales_order_no', function ($q) use ($companyLike) {
            $q->select('sales_order_no')->from('sales_order')->where('company_id', 'like', $companyLike);
        })->delete();
        DB::table('sales_order')->where('company_id', 'like', $companyLike)->delete();
        DB::table('inventory_movement')->where('company_id', 'like', $companyLike)->delete();
        DB::table('stock_count_item')->whereIn('count_no', function ($q) use ($companyLike) {
            $q->select('count_no')->from('stock_count')->where('company_id', 'like', $companyLike);
        })->delete();
        DB::table('stock_count')->where('company_id', 'like', $companyLike)->delete();
        DB::table('inventory')->where('customer_id', 'like', $customerLike)->delete();
        DB::table('customer_employee')->whereIn('customer_id', function ($q) use ($customerLike) {
            $q->select('customer_id')->from('customer_master')->where('customer_id', 'like', $customerLike);
        })->delete();
        DB::table('customer_master')->where('customer_id', 'like', $customerLike)->delete();
        DB::table('app_user')->where('user_id', 'like', $userLike)->delete();
        DB::table('employee_product')->whereIn('product_id', function ($q) use ($companyLike) {
            $q->select('product_id')->from('product_master')->where('company_id', 'like', $companyLike);
        })->delete();
        DB::table('product_unit_conversion')->whereIn('product_id', function ($q) use ($companyLike) {
            $q->select('product_id')->from('product_master')->where('company_id', 'like', $companyLike);
        })->delete();
        DB::table('product_master')->where('company_id', 'like', $companyLike)->delete();
        DB::table('employee_master')->where('employee_id', 'like', $employeeLike)->delete();
        DB::table('company_master')->where('company_id', 'like', $companyLike)->delete();
    }
}
