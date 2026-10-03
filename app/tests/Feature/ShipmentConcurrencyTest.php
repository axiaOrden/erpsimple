<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Models\CompanyMaster;
use App\Models\CustomerMaster;
use App\Models\EmployeeMaster;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\ProductMaster;
use App\Services\DeliveryService;
use App\Services\InventoryService;
use App\Services\SalesOrderService;
use App\Services\ShipmentService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * REAL MariaDB locking tests for Shipment START (Phase 6).
 *
 * Like DeliveryConcurrencyTest: NO DatabaseTransactions (a wrapping test
 * transaction would make FOR UPDATE locking invisible). Fixtures use
 * CLC.-prefixed IDs and are purged in setUp/tearDown. A forked child
 * bootstraps its own Laravel app + PDO connection and manipulates/locks
 * rows under real transactions while the parent runs the real service.
 */
class ShipmentConcurrencyTest extends TestCase
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
            @pcntl_waitpid($this->childPid, $status, WNOHANG);
            $this->childPid = 0;
        }

        $this->purgeResidue();

        parent::tearDown();
    }

    /**
     * Race: START vs a concurrent issue consuming the same restricted stock.
     * The child REALLY decrements restricted (committed) while holding the
     * row lock; the parent's START must block and then re-validate against
     * the committed post-lock state — 30 remaining cannot fund an 80 issue.
     */
    public function test_start_revalidates_restricted_stock_under_row_lock(): void
    {
        $hex = bin2hex(random_bytes(4));
        [$company, $employee, $primary, $soldTo, $product] = $this->fixtures($hex);

        app(InventoryService::class)->adjustPhysical(
            $employee, $primary->customer_id, $product->product_id, '100', 'PCS', MovementType::GOODS_RECEIPT,
        );

        $delivery = $this->allocatedDelivery($employee, $primary, $soldTo, $product, '80');
        $shipment = $this->readyShipment($employee, $company, $primary, $delivery);

        // ---- Child: hold the inventory lock, decrement restricted, COMMIT ----
        $signal = tempnam(sys_get_temp_dir(), 'shipconc');
        $scriptResource = tmpfile();
        fwrite($scriptResource, $this->childScript());
        $scriptPath = stream_get_meta_data($scriptResource)['uri'];
        $basePath = dirname(__DIR__, 2);

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'pcntl_fork failed.');

        if ($pid === 0) {
            pcntl_exec(PHP_BINARY, [$scriptPath, 'inventory', $primary->customer_id, $product->product_id, $signal, $basePath]);
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
        $this->assertTrue($locked, 'Child never acquired the inventory row lock.');

        // Parent START: must block on the locked inventory row, then see the
        // child's committed restricted=30 and refuse to issue 80.
        $start = microtime(true);

        try {
            app(ShipmentService::class)->start($employee, $shipment);
            $this->fail('Expected START to fail against the post-lock restricted balance.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('Insufficient restricted stock', $e->getMessage());
        }

        $this->assertGreaterThan(1.0, microtime(true) - $start, 'START did NOT wait on the row lock.');

        // Nothing issued: shipment READY, delivery ALLOCATED, no movements.
        $this->assertSame('READY', $shipment->fresh()->shipment_status->value);
        $this->assertSame('ALLOCATED', $delivery->fresh()->delivery_status->value);
        $this->assertSame(0, InventoryMovement::where('movement_type', MovementType::GOODS_ISSUE)->count());
        // Restricted was 80 after allocation; the child's competing issue
        // took 70 — START must have consumed nothing further (80 > 10).
        $this->assertSame('10.000', (string) Inventory::where('customer_id', $primary->customer_id)
            ->where('product_id', $product->product_id)->first()->restricted_qty);

        $this->childPid = 0;
        @pcntl_waitpid($pid, $status, WNOHANG);
        @unlink($signal);
    }

    /**
     * Race: START vs START. Two competing starts serialize on the shipment
     * row — the loser waits for the winner's lock, then finds IN_TRANSIT and
     * refuses. Exactly one goods-issue set can ever exist.
     */
    public function test_two_competing_starts_serialize_on_the_shipment_row(): void
    {
        $hex = bin2hex(random_bytes(4));
        [$company, $employee, $primary, $soldTo, $product] = $this->fixtures($hex);

        app(InventoryService::class)->adjustPhysical(
            $employee, $primary->customer_id, $product->product_id, '100', 'PCS', MovementType::GOODS_RECEIPT,
        );

        $delivery = $this->allocatedDelivery($employee, $primary, $soldTo, $product, '80');
        $shipment = $this->readyShipment($employee, $company, $primary, $delivery);

        // ---- Child: hold the SHIPMENT row lock, then roll back ----
        $signal = tempnam(sys_get_temp_dir(), 'shipconc2');
        $scriptResource = tmpfile();
        fwrite($scriptResource, $this->childScript());
        $scriptPath = stream_get_meta_data($scriptResource)['uri'];
        $basePath = dirname(__DIR__, 2);

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'pcntl_fork failed.');

        if ($pid === 0) {
            pcntl_exec(PHP_BINARY, [$scriptPath, 'shipment', $shipment->shipment_no, '', $signal, $basePath]);
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
        $this->assertTrue($locked, 'Child never acquired the shipment row lock.');

        $start = microtime(true);

        $result = app(ShipmentService::class)->start($employee, $shipment);

        $this->assertGreaterThan(1.0, microtime(true) - $start, 'START did NOT serialize on the shipment row.');
        $this->assertSame('IN_TRANSIT', $result['shipment']->shipment_status->value);
        $this->assertSame('SHIPPED', $delivery->fresh()->delivery_status->value);
        $this->assertSame(1, InventoryMovement::where('movement_type', MovementType::GOODS_ISSUE)->count());
        // The full 80 restricted were issued by exactly one START (the
        // child only held/rolled back its lock — it changed nothing).
        $this->assertSame('0.000', (string) Inventory::where('customer_id', $primary->customer_id)
            ->where('product_id', $product->product_id)->first()->restricted_qty);
        $this->assertSame('20.000', (string) Inventory::where('customer_id', $primary->customer_id)
            ->where('product_id', $product->product_id)->first()->unrestricted_qty);

        $this->childPid = 0;
        @pcntl_waitpid($pid, $status, WNOHANG);
        @unlink($signal);
    }

    // ---- Fixtures ------------------------------------------------------------

    /** @return array{0: CompanyMaster, 1: EmployeeMaster, 2: CustomerMaster, 3: CustomerMaster, 4: ProductMaster} */
    private function fixtures(string $hex): array
    {
        $company = CompanyMaster::factory()->create(['company_id' => self::PREFIX_COMPANY.$hex]);
        $employee = EmployeeMaster::factory()->create([
            'employee_id' => 'CLCE.'.$hex,
            'company_id' => $company->company_id,
        ]);
        $primary = CustomerMaster::factory()->primary()->forEmployee($employee)
            ->create();
        $soldTo = CustomerMaster::factory()->forEmployee($employee)
            ->create();
        $product = ProductMaster::factory()->forCompany($company)
            ->create(['product_id' => 'CLCQ.'.$hex, 'basic_unit' => 'PCS']);

        return [$company, $employee, $primary, $soldTo, $product];
    }

    private function allocatedDelivery(EmployeeMaster $employee, CustomerMaster $primary, CustomerMaster $soldTo, ProductMaster $product, string $qty)
    {
        $order = app(SalesOrderService::class)->createDraft(
            $employee,
            $primary->customer_id,
            $primary->customer_id,
            $soldTo->customer_id,
            collect([[
                'product_id' => $product->product_id,
                'qty' => $qty,
                'unit' => 'PCS',
                'unit_price' => null,
                'price_override_reason' => null,
            ]]),
        )['order'];
        app(SalesOrderService::class)->confirm($order);

        return app(DeliveryService::class)->createAndAllocate(
            $order->fresh(['items']),
            $employee,
            collect([['sales_order_item_no' => 1, 'qty' => $qty, 'unit' => 'PCS']]),
        )['delivery'];
    }

    private function readyShipment(EmployeeMaster $employee, CompanyMaster $company, CustomerMaster $primary, $delivery)
    {
        $shipment = app(ShipmentService::class)->createShipment($employee, $company->company_id, $primary->customer_id);
        app(ShipmentService::class)->attachDelivery($shipment, $delivery->delivery_no);
        app(ShipmentService::class)->markReady($shipment);

        return $shipment->fresh(['deliveries.items']);
    }

    /**
     * Standalone child: own Laravel app + PDO connection.
     * mode=inventory: lock the inventory row, restricted -= 70, COMMIT.
     * mode=shipment: hold the shipment row lock ~3s, then roll back.
     */
    private function childScript(): string
    {
        return <<<'PHP'
<?php
require $argv[5].'/vendor/autoload.php';
$app = require $argv[5].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$pdo = Illuminate\Support\Facades\DB::connection('mariadb')->getPdo();
$pdo->beginTransaction();

if ($argv[1] === 'inventory') {
    $stmt = $pdo->prepare('select restricted_qty from inventory where customer_id = ? and product_id = ? for update');
    $stmt->execute([$argv[2], $argv[3]]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    file_put_contents($argv[4], 'LOCKED '.$row['restricted_qty']);
    sleep(3); // hold while the parent's START runs
    $upd = $pdo->prepare('update inventory set restricted_qty = restricted_qty - 70 where customer_id = ? and product_id = ?');
    $upd->execute([$argv[2], $argv[3]]);
    $pdo->commit(); // the competing issue really happened
} else {
    $stmt = $pdo->prepare('select shipment_status from shipment where shipment_no = ? for update');
    $stmt->execute([$argv[2]]);
    $stmt->fetch(PDO::FETCH_ASSOC);
    file_put_contents($argv[4], 'LOCKED');
    sleep(3);
    $pdo->rollBack();
}
PHP;
    }

    /** Remove every fixture row this class may have created (children-first). */
    private function purgeResidue(): void
    {
        $companyLike = self::PREFIX_COMPANY.'%';
        $employeeLike = 'CLCE.%';
        $userLike = 'clcu.%';
        $customerIds = DB::table('customer_employee')
            ->where('employee_id', 'like', $employeeLike)
            ->pluck('customer_id');

        DB::table('shipment_delivery')->whereIn('shipment_no', function ($q) use ($companyLike) {
            $q->select('shipment_no')->from('shipment')->where('company_id', 'like', $companyLike);
        })->delete();
        DB::table('shipment')->where('company_id', 'like', $companyLike)->delete();
        DB::table('delivery_item')->whereIn('delivery_no', function ($q) use ($companyLike) {
            $q->select('delivery_no')->from('delivery')->where('company_id', 'like', $companyLike);
        })->delete();
        DB::table('delivery')->where('company_id', 'like', $companyLike)->delete();
        DB::table('sales_order_item')->whereIn('sales_order_no', function ($q) use ($companyLike) {
            $q->select('sales_order_no')->from('sales_order')->where('company_id', 'like', $companyLike);
        })->delete();
        DB::table('sales_order')->where('company_id', 'like', $companyLike)->delete();
        DB::table('inventory_movement')->where('company_id', 'like', $companyLike)->delete();
        DB::table('inventory')->whereIn('customer_id', $customerIds)->delete();
        DB::table('customer_employee')->whereIn('customer_id', $customerIds)->delete();
        DB::table('customer_master')->whereIn('customer_id', $customerIds)->delete();
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
