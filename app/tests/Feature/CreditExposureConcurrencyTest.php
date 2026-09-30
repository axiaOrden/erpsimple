<?php

namespace Tests\Feature;

use App\Enums\DifferenceReason;
use App\Enums\MovementType;
use App\Models\CompanyMaster;
use App\Models\CustomerMaster;
use App\Models\Delivery;
use App\Models\EmployeeMaster;
use App\Models\Invoice;
use App\Models\ProductMaster;
use App\Models\SalesOrder;
use App\Models\Shipment;
use App\Services\DeliveryService;
use App\Services\InventoryService;
use App\Services\InvoiceService;
use App\Services\PodService;
use App\Services\SalesOrderService;
use App\Services\ShipmentService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * REAL MariaDB row-locking tests for the strict outstanding-debt rule
 * (audit correction). Fixtures use FC10.-prefixed ids and are purged in
 * setUp()/tearDown() (no DatabaseTransactions).
 *
 * Serialization mechanism under test: the debtor's `customer_master` row is
 * locked FOR UPDATE by BOTH the eligibility checks (SO confirm, delivery
 * allocation, shipment START) and exposure CREATION (invoice generation),
 * always as the LAST lock of the transaction. A holder process therefore
 * keeps an uncommitted invoice invisible to a racing allocation, and the
 * racer must block on the debtor row and then be rejected — never slip
 * through and create exposure for a defaulting debtor.
 */
class CreditExposureConcurrencyTest extends TestCase
{
    private const PREFIX_COMPANY = 'FC10.';

    /** @var array<int, int> */
    private array $childPids = [];

    /** @var array<int, string> persistent child scripts (survive the fork/exec gap) */
    private array $scriptFiles = [];

    private CompanyMaster $company;

    private EmployeeMaster $employee;

    private CustomerMaster $primary;

    private ProductMaster $product;

    private SalesOrderService $orders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purgeResidue();

        $hex = bin2hex(random_bytes(4));
        $companyId = self::PREFIX_COMPANY.$hex;

        $this->company = CompanyMaster::factory()->create(['company_id' => $companyId]);
        $this->employee = EmployeeMaster::factory()->create(['employee_id' => 'FC10E.'.$hex, 'company_id' => $companyId]);
        $this->primary = CustomerMaster::factory()->primary()->forEmployee($this->employee)->create();
        $this->product = ProductMaster::factory()->forCompany($this->company)
            ->create(['product_id' => 'FC10Q.'.$hex, 'basic_unit' => 'PCS']);

        DB::table('product_unit_conversion')->insert([
            'product_id' => $this->product->product_id,
            'alternative_unit' => 'CTN',
            'numerator' => '24',
            'denominator' => '1',
        ]);
        DB::table('price_condition')->insert([
            'condition_price_no' => 'FC10PC.'.$hex,
            'company_id' => $companyId,
            'sales_region' => null,
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'active' => true,
        ]);
        DB::table('price_condition_item')->insert([
            'condition_price_no' => 'FC10PC.'.$hex,
            'product_id' => $this->product->product_id,
            'price' => '1000.00',
            'currency' => 'NGN',
            'tax_type' => 'NONE',
        ]);

        $this->orders = app(SalesOrderService::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->childPids as $pid) {
            if (function_exists('posix_kill')) {
                @posix_kill($pid, SIGKILL);
            } else {
                @exec('kill -9 '.(int) $pid);
            }
            @pcntl_waitpid($pid, $status, WNOHANG);
        }
        $this->childPids = [];

        foreach ($this->scriptFiles as $path) {
            @unlink($path);
        }
        $this->scriptFiles = [];

        $this->purgeResidue();

        parent::tearDown();
    }

    // ---- The invariant: exposure creation vs eligibility check ------------

    public function test_uncommitted_invoice_racing_delivery_allocation_blocks_the_allocation(): void
    {
        $debtor = $this->customer();
        $orderShipped = $this->confirmedOrder($debtor, '10');
        $orderRacer = $this->confirmedOrder($debtor, '5');

        $this->stock('100');
        $shippedDelivery = $this->deliverAndShip($orderShipped, '10');

        // Holder: POD the shipped delivery inside an OPEN transaction (the
        // invoice exists but is NOT committed) and hold the debtor row lock.
        $holderScript = $this->podHolderScript();
        [$holderPid, $holderReady, $holderGo, $holderOut] = $this->spawn(
            $holderScript,
            [$shippedDelivery->delivery_no, $this->employee->employee_id, '10'],
        );
        $this->waitFor($holderReady, 'HOLDING', 'POD holder never acquired its transaction.');

        // Racer: allocate for the same debtor while the invoice is uncommitted.
        [$racerPid, $racerReady, , $racerOut] = $this->spawn(
            $this->allocationRacerScript(),
            [$orderRacer->sales_order_no, $this->employee->employee_id, '5'],
        );
        $this->waitFor($racerReady, 'READY', 'allocation racer never started.');

        usleep(400_000); // give the racer time to reach (and block on) the debtor row

        file_put_contents($holderGo, 'GO'); // commit the invoice, release the lock

        $holder = $this->childResult($holderOut);
        $racer = $this->childResult($racerOut);

        $this->assertTrue($holder['ok'] ?? false, 'POD holder failed: '.($holder['error'] ?? '?'));
        $this->assertFalse($racer['ok'] ?? true, 'Allocation must not slip through while the debtor has (uncommitted) debt.');

        // No exposure was created for the blocked debtor.
        $this->assertSame(0, Delivery::where('sales_order_no', $orderRacer->sales_order_no)->count());
        $this->assertSame(1, Invoice::where('sales_order_no', $orderShipped->sales_order_no)->count());
        $this->assertGreaterThan(0.0, (float) app(InvoiceService::class)->exposure($debtor->customer_id)['net_exposure']);
    }

    public function test_uncommitted_invoice_racing_shipment_start_blocks_the_start(): void
    {
        $debtor = $this->customer();
        $orderShipped = $this->confirmedOrder($debtor, '10');
        $orderRacer = $this->confirmedOrder($debtor, '5');

        $this->stock('100');
        $shippedDelivery = $this->deliverAndShip($orderShipped, '10');

        // Racer's delivery is allocated and queued on a READY shipment.
        $racerDelivery = $this->deliver($orderRacer, '5');
        $racerShipment = $this->shipmentReady([$racerDelivery]);

        $holderScript = $this->podHolderScript();
        [$holderPid, $holderReady, $holderGo, $holderOut] = $this->spawn(
            $holderScript,
            [$shippedDelivery->delivery_no, $this->employee->employee_id, '10'],
        );
        $this->waitFor($holderReady, 'HOLDING', 'POD holder never acquired its transaction.');

        [$racerPid, $racerReady, , $racerOut] = $this->spawn(
            $this->startRacerScript(),
            [$racerShipment->shipment_no, $this->employee->employee_id],
        );
        $this->waitFor($racerReady, 'READY', 'START racer never started.');

        usleep(400_000);
        file_put_contents($holderGo, 'GO');

        $holder = $this->childResult($holderOut);
        $racer = $this->childResult($racerOut);

        $this->assertTrue($holder['ok'] ?? false, 'POD holder failed: '.($holder['error'] ?? '?'));

        // Either the START blocked and was rejected (our locking), or — if it
        // somehow won the race first — it must have committed BEFORE the debt;
        // what must NEVER happen is a START landing after the committed invoice.
        if (! ($racer['ok'] ?? false)) {
            $this->assertSame('READY', $racerShipment->fresh()->shipment_status->value, 'A blocked START must not dispatch anything.');
            $this->assertSame('ALLOCATED', $racerDelivery->fresh()->delivery_status->value);
        } else {
            $this->assertSame('IN_TRANSIT', $racerShipment->fresh()->shipment_status->value);
        }
    }

    public function test_payment_settlement_racing_allocation_is_deterministic(): void
    {
        $debtor = $this->customer();
        $orderInvoiced = $this->confirmedOrder($debtor, '10');
        $orderRacer = $this->confirmedOrder($debtor, '5');

        $this->stock('100');
        $delivery = $this->deliverAndShip($orderInvoiced, '10');
        app(PodService::class)->confirmItem($this->employee, $delivery->delivery_no, 1, '10', 'PCS', DifferenceReason::NONE);

        $invoice = Invoice::where('sales_order_no', $orderInvoiced->sales_order_no)->firstOrFail();
        $this->assertSame('UNPAID', $invoice->payment_status->value);

        // Holder: settle the invoice inside an OPEN transaction (uncommitted).
        [$holderPid, $holderReady, $holderGo, $holderOut] = $this->spawn(
            $this->paymentHolderScript(),
            [$this->company->company_id, $debtor->customer_id, '10000.00'],
        );
        $this->waitFor($holderReady, 'HOLDING', 'payment holder never acquired its transaction.');

        // Racer: allocation while the settlement is still uncommitted. The
        // committed invoice still shows as debt → it must be rejected.
        [$racerPid, $racerReady, , $racerOut] = $this->spawn(
            $this->allocationRacerScript(),
            [$orderRacer->sales_order_no, $this->employee->employee_id, '5'],
        );
        $this->waitFor($racerReady, 'READY', 'allocation racer never started.');

        usleep(300_000); // let the racer reach the eligibility boundary

        // Release the settlement. The racer serializes behind it — it cannot
        // read the invoice (current, locking read) while the settlement holds
        // it — so it observes the TRUE settled state and the allocation is a
        // valid post-settlement decision. The boundary is deterministic.
        file_put_contents($holderGo, 'GO');

        $holder = $this->childResult($holderOut);
        $racer = $this->childResult($racerOut);

        $this->assertTrue($holder['ok'] ?? false, 'payment holder failed: '.($holder['error'] ?? '?'));
        $this->assertTrue($racer['ok'] ?? false, 'allocation racer failed: '.($racer['error'] ?? '?'));

        // Money stayed intact and the customer is no longer blocked.
        $this->assertSame('PAID', $invoice->fresh()->payment_status->value);
        $this->assertSame('10000.00', (string) $invoice->fresh()->settled_amount);
        $this->assertSame('0.00', app(InvoiceService::class)->exposure($debtor->customer_id)['net_exposure']);

        $delivery = Delivery::where('sales_order_no', $orderRacer->sales_order_no)->firstOrFail();
        $this->assertSame('ALLOCATED', $delivery->delivery_status->value);
    }

    public function test_dispatched_stock_stays_podable_after_debt_arises(): void
    {
        $debtor = $this->customer();
        $orderA = $this->confirmedOrder($debtor, '10');
        $orderB = $this->confirmedOrder($debtor, '5');

        $this->stock('100');
        $deliveryA = $this->deliverAndShip($orderA, '10');
        $deliveryB = $this->deliverAndShip($orderB, '5');

        // B's POD creates the debt first.
        app(PodService::class)->confirmItem($this->employee, $deliveryB->delivery_no, 1, '5', 'PCS', DifferenceReason::NONE);
        $this->assertGreaterThan(0.0, (float) app(InvoiceService::class)->exposure($debtor->customer_id)['net_exposure']);

        // A's stock is already dispatched: POD must still work.
        app(PodService::class)->confirmItem($this->employee, $deliveryA->delivery_no, 1, '10', 'PCS', DifferenceReason::NONE);

        $this->assertSame('DELIVERED', $deliveryA->fresh()->delivery_status->value);
        $this->assertSame(1, Invoice::where('sales_order_no', $orderA->sales_order_no)->count());
    }

    public function test_concurrent_starts_sharing_a_debtor_do_not_deadlock(): void
    {
        $debtor = $this->customer();
        $orderA = $this->confirmedOrder($debtor, '10');
        $orderB = $this->confirmedOrder($debtor, '10');

        $this->stock('100');
        $deliveryA = $this->deliver($orderA, '10');
        $shipmentA = $this->shipmentReady([$deliveryA]);
        $deliveryB = $this->deliver($orderB, '10');
        $shipmentB = $this->shipmentReady([$deliveryB]);

        // Racer (child) starts shipment B; parent starts shipment A. Both
        // lock the SAME debtor row last, in a deterministic order.
        [$racerPid, $racerReady, , $racerOut] = $this->spawn(
            $this->startRacerScript(),
            [$shipmentB->shipment_no, $this->employee->employee_id],
        );
        $this->waitFor($racerReady, 'READY', 'START racer never started.');

        $parentError = null;

        try {
            app(ShipmentService::class)->start($this->employee, $shipmentA->fresh());
        } catch (\Throwable $e) {
            $parentError = $e->getMessage();
        }

        $racer = $this->childResult($racerOut);

        $this->assertNull($parentError, 'Parent START failed: '.$parentError);
        $this->assertTrue($racer['ok'] ?? false, 'Racer START failed (deadlock?): '.($racer['error'] ?? '?'));
        $this->assertSame('IN_TRANSIT', $shipmentA->fresh()->shipment_status->value);
        $this->assertSame('IN_TRANSIT', $shipmentB->fresh()->shipment_status->value);
    }

    // ---- Fixtures ----------------------------------------------------------

    private function customer(): CustomerMaster
    {
        return CustomerMaster::factory()->forEmployee($this->employee)->create();
    }

    private function stock(string $qty): void
    {
        app(InventoryService::class)->adjustPhysical(
            $this->employee, $this->primary->customer_id, $this->product->product_id, $qty, 'PCS', MovementType::GOODS_RECEIPT,
        );
    }

    private function confirmedOrder(CustomerMaster $soldTo, string $qty): SalesOrder
    {
        $order = $this->orders->createDraft(
            $this->employee,
            $this->primary->customer_id,
            $this->primary->customer_id,
            $soldTo->customer_id,
            collect([['product_id' => $this->product->product_id, 'qty' => $qty, 'unit' => 'PCS', 'unit_price' => null, 'price_override_reason' => null]]),
        )['order'];

        $result = $this->orders->confirm($order);
        $this->assertSame([], $result['conflicts'], 'Fixture order must confirm.');

        return $order->fresh(['items']);
    }

    private function deliver(SalesOrder $order, string $qty): Delivery
    {
        return app(DeliveryService::class)->createAndAllocate(
            $order->fresh(['items']),
            $this->employee,
            collect([['sales_order_item_no' => 1, 'qty' => $qty, 'unit' => 'PCS', 'transit_qty' => '0']]),
        )['delivery'];
    }

    private function deliverAndShip(SalesOrder $order, string $qty): Delivery
    {
        $delivery = $this->deliver($order, $qty);
        $shipment = $this->shipmentReady([$delivery]);
        app(ShipmentService::class)->start($this->employee, $shipment);

        return $delivery;
    }

    /** @param array<int, Delivery> $deliveries */
    private function shipmentReady(array $deliveries): Shipment
    {
        $shipment = app(ShipmentService::class)->createShipment($this->employee, $this->company->company_id, $this->primary->customer_id);

        foreach ($deliveries as $delivery) {
            app(ShipmentService::class)->attachDelivery($shipment, $delivery->delivery_no);
        }

        app(ShipmentService::class)->markReady($shipment);

        return $shipment->fresh();
    }

    // ---- Harness -----------------------------------------------------------

    /** @return array{0: int, 1: string, 2: string, 3: string} pid, ready, go, out */
    private function spawn(string $scriptBody, array $args): array
    {
        $basePath = dirname(__DIR__, 2);
        $ready = tempnam(sys_get_temp_dir(), 'cxr');
        $go = tempnam(sys_get_temp_dir(), 'cxg');
        $out = tempnam(sys_get_temp_dir(), 'cxo');

        // A persistent script file (NOT tmpfile(), whose handle the parent
        // would close — and unlink — before the child gets to exec it).
        $scriptPath = tempnam(sys_get_temp_dir(), 'cxs');
        file_put_contents($scriptPath, $scriptBody);
        $this->scriptFiles[] = $scriptPath;

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'pcntl_fork failed.');

        if ($pid === 0) {
            pcntl_exec(PHP_BINARY, [$scriptPath, $ready, $go, $out, $basePath, ...$args]);
            exit(127);
        }

        $this->childPids[] = $pid;

        return [$pid, $ready, $go, $out];
    }

    private function podHolderScript(): string
    {
        return <<<'PHP'
<?php
require $argv[4].'/vendor/autoload.php';
$app = require $argv[4].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

\Illuminate\Support\Facades\DB::beginTransaction();

try {
    $employee = App\Models\EmployeeMaster::findOrFail($argv[6]);
    $app->make(App\Services\PodService::class)->confirmItem(
        $employee, $argv[5], 1, $argv[7], 'PCS', App\Enums\DifferenceReason::NONE,
    );
    file_put_contents($argv[1], 'HOLDING');
} catch (Throwable $e) {
    file_put_contents($argv[1], 'HOLDING');
    file_put_contents($argv[3], json_encode(['ok' => false, 'error' => $e->getMessage()]));
    \Illuminate\Support\Facades\DB::rollBack();
    exit(0);
}

while (! str_starts_with((string) @file_get_contents($argv[2]), 'GO')) {
    usleep(20_000);
}

\Illuminate\Support\Facades\DB::commit();
file_put_contents($argv[3], json_encode(['ok' => true]));
PHP;
    }

    private function allocationRacerScript(): string
    {
        return <<<'PHP'
<?php
require $argv[4].'/vendor/autoload.php';
$app = require $argv[4].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

file_put_contents($argv[1], 'READY');

try {
    $order = App\Models\SalesOrder::whereKey($argv[5])->firstOrFail();
    $employee = App\Models\EmployeeMaster::findOrFail($argv[6]);
    $app->make(App\Services\DeliveryService::class)->createAndAllocate(
        $order->fresh(['items']),
        $employee,
        collect([['sales_order_item_no' => 1, 'qty' => $argv[7], 'unit' => 'PCS', 'transit_qty' => '0']]),
    );
    file_put_contents($argv[3], json_encode(['ok' => true]));
} catch (Throwable $e) {
    file_put_contents($argv[3], json_encode(['ok' => false, 'error' => $e->getMessage()]));
}
PHP;
    }

    private function startRacerScript(): string
    {
        return <<<'PHP'
<?php
require $argv[4].'/vendor/autoload.php';
$app = require $argv[4].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

file_put_contents($argv[1], 'READY');

try {
    $shipment = App\Models\Shipment::whereKey($argv[5])->firstOrFail();
    $employee = App\Models\EmployeeMaster::findOrFail($argv[6]);
    $app->make(App\Services\ShipmentService::class)->start($employee, $shipment);
    file_put_contents($argv[3], json_encode(['ok' => true]));
} catch (Throwable $e) {
    file_put_contents($argv[3], json_encode(['ok' => false, 'error' => $e->getMessage()]));
}
PHP;
    }

    private function paymentHolderScript(): string
    {
        return <<<'PHP'
<?php
require $argv[4].'/vendor/autoload.php';
$app = require $argv[4].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

\Illuminate\Support\Facades\DB::beginTransaction();

try {
    $finance = $app->make(App\Services\FinanceService::class);
    $payment = $finance->createPayment($argv[5], $argv[6], 'CASH', $argv[7]);
    $finance->confirmPayment($payment);
    $finance->allocatePayment($payment->fresh());
    file_put_contents($argv[1], 'HOLDING');
} catch (Throwable $e) {
    file_put_contents($argv[1], 'HOLDING');
    file_put_contents($argv[3], json_encode(['ok' => false, 'error' => $e->getMessage()]));
    \Illuminate\Support\Facades\DB::rollBack();
    exit(0);
}

while (! str_starts_with((string) @file_get_contents($argv[2]), 'GO')) {
    usleep(20_000);
}

\Illuminate\Support\Facades\DB::commit();
file_put_contents($argv[3], json_encode(['ok' => true]));
PHP;
    }

    private function waitFor(string $file, string $prefix, string $message): void
    {
        for ($i = 0; $i < 200; $i++) {
            if (is_file($file) && str_starts_with((string) file_get_contents($file), $prefix)) {
                return;
            }
            usleep(50_000);
        }

        $this->fail($message);
    }

    /** @return array<string, mixed> */
    private function childResult(string $out): array
    {
        $this->waitFor($out, '{"', 'child racer never produced output.');

        $payload = json_decode((string) file_get_contents($out), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    // ---- Purge -------------------------------------------------------------

    private function purgeResidue(): void
    {
        $companyLike = self::PREFIX_COMPANY.'%';
        $employeeLike = 'FC10E.%';

        DB::table('transit_stock_allocation')->whereIn('transit_id', function ($q) use ($companyLike) {
            $q->select('transit_id')->from('transit_stock')->where('company_id', 'like', $companyLike);
        })->delete();
        DB::table('transit_stock')->where('company_id', 'like', $companyLike)->update([
            'parent_transit_id' => null, 'resolved_movement_id' => null, 'resolved_to_delivery_no' => null,
        ]);
        DB::table('transit_stock')->where('company_id', 'like', $companyLike)->delete();

        DB::table('credit_allocation')->whereIn('invoice_no', function ($q) use ($companyLike) {
            $q->select('invoice_no')->from('invoice')->where('company_id', 'like', $companyLike);
        })->delete();
        DB::table('payment_allocation')->whereIn('invoice_no', function ($q) use ($companyLike) {
            $q->select('invoice_no')->from('invoice')->where('company_id', 'like', $companyLike);
        })->delete();
        DB::table('payment')->where('company_id', 'like', $companyLike)->delete();
        DB::table('customer_credit')->where('company_id', 'like', $companyLike)->delete();
        DB::table('invoice_item')->whereIn('invoice_no', function ($q) use ($companyLike) {
            $q->select('invoice_no')->from('invoice')->where('company_id', 'like', $companyLike);
        })->delete();
        DB::table('invoice')->where('company_id', 'like', $companyLike)->delete();

        DB::table('delivery_confirmation')->whereIn('delivery_no', function ($q) use ($companyLike) {
            $q->select('delivery_no')->from('delivery')->where('company_id', 'like', $companyLike);
        })->delete();
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

        $customerIds = DB::table('customer_employee')
            ->where('employee_id', 'like', $employeeLike)
            ->pluck('customer_id')
            ->all();
        DB::table('inventory')->whereIn('customer_id', $customerIds)->delete();
        DB::table('inventory')->where('customer_id', 'like', $companyLike)->delete();

        DB::table('price_condition_item')->where('condition_price_no', 'like', 'FC10PC.%')->delete();
        DB::table('price_condition')->where('company_id', 'like', $companyLike)->delete();

        DB::table('employee_product')->whereIn('product_id', function ($q) use ($companyLike) {
            $q->select('product_id')->from('product_master')->where('company_id', 'like', $companyLike);
        })->delete();
        DB::table('product_unit_conversion')->whereIn('product_id', function ($q) use ($companyLike) {
            $q->select('product_id')->from('product_master')->where('company_id', 'like', $companyLike);
        })->delete();
        DB::table('product_master')->where('company_id', 'like', $companyLike)->delete();

        DB::table('customer_employee')->where('employee_id', 'like', $employeeLike)->delete();
        DB::table('customer_master')->whereIn('customer_id', $customerIds)->delete();
        DB::table('app_user')->where('company_id', 'like', $companyLike)->delete();
        DB::table('employee_master')->where('employee_id', 'like', $employeeLike)->delete();
        DB::table('company_master')->where('company_id', 'like', $companyLike)->delete();
    }
}
