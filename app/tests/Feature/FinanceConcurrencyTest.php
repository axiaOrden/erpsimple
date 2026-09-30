<?php

namespace Tests\Feature;

use App\Enums\DifferenceReason;
use App\Enums\MovementType;
use App\Models\CompanyMaster;
use App\Models\CustomerMaster;
use App\Models\EmployeeMaster;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\ProductMaster;
use App\Models\ProductUnitConversion;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Services\DeliveryService;
use App\Services\FinanceService;
use App\Services\InventoryService;
use App\Services\InvoiceService;
use App\Services\PodService;
use App\Services\SalesOrderService;
use App\Services\ShipmentService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * REAL MariaDB row-locking concurrency tests for Phase 8 money movement.
 *
 * Deliberately does NOT use DatabaseTransactions (same rationale as
 * DeliveryConcurrencyTest): fixtures use FC8.-prefixed ids and are purged in
 * setUp()/tearDown(). Two processes race the SAME business operation through
 * real FOR UPDATE locks; the invariants that must survive are:
 *
 *  1. Two concurrent allocations of one payment remainder can never allocate
 *     more than the payment amount (serialized on the payment row lock).
 *  2. Two concurrent billing-terminal generations of one Sales Order produce
 *     exactly ONE invoice (serialized on the SO row lock + uq_invoice_so).
 */
class FinanceConcurrencyTest extends TestCase
{
    private const PREFIX_COMPANY = 'FC8.';

    private int $childPid = 0;

    private ?ProductMaster $product = null;

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

    public function test_two_concurrent_allocations_cannot_exceed_one_payment_remainder(): void
    {
        [$companyId, $employee, $primary, $soldTo] = $this->fixtures();

        // Two outstanding invoices for the same debtor — each from its OWN
        // sales order (uq_invoice_so allows one final invoice per SO).
        $so1 = $this->confirmedOrder($employee, $primary, $soldTo);
        $so2 = $this->confirmedOrder($employee, $primary, $soldTo);
        $item1 = SalesOrderItem::where('sales_order_no', $so1->sales_order_no)->where('item_no', 1)->firstOrFail();
        $item2 = SalesOrderItem::where('sales_order_no', $so2->sales_order_no)->where('item_no', 1)->firstOrFail();
        $invoiceA = $this->insertInvoice($companyId, $soldTo->customer_id, $so1->sales_order_no, 'FC8-INV-A-1', '100.00', $item1);
        $invoiceB = $this->insertInvoice($companyId, $soldTo->customer_id, $so2->sales_order_no, 'FC8-INV-B-1', '100.00', $item2);

        // One CONFIRMED payment of 100.00 — the contested remainder.
        $finance = app(FinanceService::class);
        $payment = $finance->createPayment($companyId, $soldTo->customer_id, 'CASH', '100.00');
        $finance->confirmPayment($payment);

        // ---- Parent + child race the SAME allocation concurrently ----------
        $basePath = dirname(__DIR__, 2);
        $ready = tempnam(sys_get_temp_dir(), 'fc8r');
        $go = tempnam(sys_get_temp_dir(), 'fc8g');
        $childOut = tempnam(sys_get_temp_dir(), 'fc8o');

        $scriptResource = tmpfile();
        fwrite($scriptResource, $this->allocationChildScript());
        $scriptPath = stream_get_meta_data($scriptResource)['uri'];

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'pcntl_fork failed.');

        if ($pid === 0) {
            pcntl_exec(PHP_BINARY, [$scriptPath, $payment->payment_id.'', $ready, $go, $childOut, $basePath]);
            exit(127);
        }

        $this->childPid = $pid;

        // Child signals READY only AFTER it holds the payment row lock, so the
        // parent's allocation below is guaranteed to contend on it.
        $this->waitFor($ready, 'READY', 'child allocation never became ready.');

        file_put_contents($go, 'GO'); // release both racers at once

        $start = microtime(true);
        $parentAllocated = 0.0;

        try {
            $result = app(FinanceService::class)->allocatePayment($payment->fresh());
            $parentAllocated = (float) $result['allocations']->sum(fn ($a) => (float) $a['amount']);
        } catch (\Throwable $e) {
            $parentAllocated = -1.0; // rejected (e.g. fully allocated) — legal
        }

        $blockedFor = microtime(true) - $start;
        $this->assertGreaterThan(0.5, $blockedFor, 'Parent did not contend on the payment row lock — race is not real.');

        $this->waitFor($childOut, '{"', 'child allocation never produced output.');
        $child = json_decode((string) file_get_contents($childOut), true);
        $childAllocated = (float) ($child['total'] ?? -1.0);

        @pcntl_waitpid($pid, $status, WNOHANG);
        $this->childPid = 0;

        // THE invariant: history never exceeds the money that existed.
        $totalAllocated = (float) PaymentAllocation::where('payment_id', $payment->payment_id)->sum('allocated_amount');
        $this->assertLessThanOrEqual(100.001, $totalAllocated, 'Concurrent allocations over-allocated the payment remainder.');
        $this->assertEqualsWithDelta(100.0, $totalAllocated, 0.001, 'The remainder was fully used by exactly one racer.');

        $this->assertThat($totalAllocated, $this->logicalOr(
            $this->logicalAnd($this->equalTo(100.0), $this->logicalOr($this->equalTo($parentAllocated), $this->equalTo($childAllocated))),
        ));

        // Per-invoice outstanding can never be exceeded either.
        foreach ([$invoiceA, $invoiceB] as $invoiceNo) {
            $settled = (float) Invoice::where('invoice_no', $invoiceNo)->value('settled_amount');
            $this->assertLessThanOrEqual(100.001, $settled);
        }

        @unlink($ready);
        @unlink($go);
        @unlink($childOut);
    }

    public function test_concurrent_billing_terminal_generations_produce_exactly_one_invoice(): void
    {
        [$companyId, $employee, $primary, $soldTo] = $this->fixtures();

        // Real chain: the order must be FULLY SHIPPED with a POD outcome on
        // every item to be billing-terminal (never-shipped demand is not).
        app(InventoryService::class)->adjustPhysical(
            $employee, $primary->customer_id, $this->product->product_id, '100', 'PCS', MovementType::GOODS_RECEIPT,
        );

        $order = $this->confirmedOrder($employee, $primary, $soldTo);

        $delivery = app(DeliveryService::class)->createAndAllocate(
            $order->fresh(['items']),
            $employee,
            collect([['sales_order_item_no' => 1, 'qty' => '10', 'unit' => 'PCS']]),
        )['delivery'];

        $shipment = app(ShipmentService::class)->createShipment($employee, $companyId, $primary->customer_id);
        app(ShipmentService::class)->attachDelivery($shipment, $delivery->delivery_no);
        app(ShipmentService::class)->markReady($shipment);
        app(ShipmentService::class)->start($employee, $shipment);

        app(PodService::class)->confirmItem($employee, $delivery->delivery_no, 1, '10', 'PCS', DifferenceReason::NONE);

        // POD wiring already generated the final invoice; remove it (nothing
        // is allocated to it) so both racers face a real generation decision.
        $seeded = Invoice::where('sales_order_no', $order->sales_order_no)->firstOrFail()->invoice_no;
        DB::table('invoice_item')->where('invoice_no', $seeded)->delete();
        DB::table('invoice')->where('invoice_no', $seeded)->delete();

        $service = app(InvoiceService::class);
        $order = SalesOrder::whereKey($order->sales_order_no)->firstOrFail();

        // ---- Parent + child race generateIfTerminal concurrently -----------
        $basePath = dirname(__DIR__, 2);
        $ready = tempnam(sys_get_temp_dir(), 'fc8r');
        $go = tempnam(sys_get_temp_dir(), 'fc8g');
        $childOut = tempnam(sys_get_temp_dir(), 'fc8o');

        $scriptResource = tmpfile();
        fwrite($scriptResource, $this->generationChildScript());
        $scriptPath = stream_get_meta_data($scriptResource)['uri'];

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'pcntl_fork failed.');

        if ($pid === 0) {
            pcntl_exec(PHP_BINARY, [$scriptPath, $order->sales_order_no, $ready, $go, $childOut, $basePath]);
            exit(127);
        }

        $this->childPid = $pid;

        // Child signals READY only AFTER it holds the SO row lock.
        $this->waitFor($ready, 'READY', 'child generation never became ready.');

        file_put_contents($go, 'GO');

        $start = microtime(true);
        $parentInvoiceNo = $service->generateIfTerminal($order)?->invoice_no;
        $blockedFor = microtime(true) - $start;

        $this->assertGreaterThan(0.5, $blockedFor, 'Parent did not contend on the SO row lock — race is not real.');

        $this->waitFor($childOut, '{"', 'child generation never produced output.');
        $child = json_decode((string) file_get_contents($childOut), true);
        $childInvoiceNo = $child['invoice_no'] ?? null;

        @pcntl_waitpid($pid, $status, WNOHANG);
        $this->childPid = 0;

        $this->assertNotNull($parentInvoiceNo);
        $this->assertNotNull($childInvoiceNo);
        $this->assertSame($parentInvoiceNo, $childInvoiceNo, 'Both racers must converge on ONE invoice.');

        $this->assertSame(1, DB::table('invoice')->where('sales_order_no', $order->sales_order_no)->count(), 'uq_invoice_so/SO lock: no duplicate invoice.');
        $this->assertSame(1, DB::table('invoice_item')->where('invoice_no', $parentInvoiceNo)->count(), 'Exactly one set of lines.');

        @unlink($ready);
        @unlink($go);
        @unlink($childOut);
    }

    // ---- Harness ------------------------------------------------------------

    private function waitFor(string $file, string $prefix, string $message): void
    {
        $seen = false;

        for ($i = 0; $i < 120; $i++) {
            if (is_file($file) && str_starts_with((string) file_get_contents($file), $prefix)) {
                $seen = true;
                break;
            }
            usleep(50_000);
        }

        $this->assertTrue($seen, $message);
    }

    /** @return array{0: string, 1: EmployeeMaster, 2: CustomerMaster, 3: CustomerMaster} */
    private function fixtures(): array
    {
        $hex = bin2hex(random_bytes(4));
        $companyId = self::PREFIX_COMPANY.$hex;

        $company = CompanyMaster::factory()->create(['company_id' => $companyId]);
        $employee = EmployeeMaster::factory()->create([
            'employee_id' => 'FC8E.'.$hex,
            'company_id' => $companyId,
        ]);
        $primary = CustomerMaster::factory()->primary()->forEmployee($employee)->create();
        $soldTo = CustomerMaster::factory()->forEmployee($employee)->create();
        $this->product = ProductMaster::factory()->forCompany($company)
            ->create(['product_id' => 'FC8Q.'.$hex, 'basic_unit' => 'PCS']);
        ProductUnitConversion::create([
            'product_id' => $this->product->product_id,
            'alternative_unit' => 'CTN',
            'numerator' => '24',
            'denominator' => '1',
        ]);

        // A named price condition so confirm() resolves a price without input
        // (same shape FinanceTest uses — pricing ambiguity blocks confirm()).
        DB::table('price_condition')->insert([
            'condition_price_no' => 'FC8PC.'.$hex,
            'company_id' => $companyId,
            'sales_region' => null,
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'active' => true,
        ]);
        DB::table('price_condition_item')->insert([
            'condition_price_no' => 'FC8PC.'.$hex,
            'product_id' => $this->product->product_id,
            'price' => '1000.00',
            'currency' => 'NGN',
            'tax_type' => 'NONE',
        ]);

        return [$companyId, $employee, $primary, $soldTo];
    }

    private function confirmedOrder(EmployeeMaster $employee, CustomerMaster $primary, CustomerMaster $soldTo): SalesOrder
    {
        $order = app(SalesOrderService::class)->createDraft(
            $employee,
            $primary->customer_id,
            $primary->customer_id,
            $soldTo->customer_id,
            collect([[
                'product_id' => $this->product->product_id,
                'qty' => '10',
                'unit' => 'PCS',
                'unit_price' => null,
                'price_override_reason' => null,
            ]]),
        )['order'];

        app(SalesOrderService::class)->confirm($order);

        return $order->fresh();
    }

    /**
     * Insert an FK-valid outstanding invoice (finance concurrency does not
     * need the full POD chain — the SO/item rows come from the real service).
     */
    private function insertInvoice(string $companyId, string $customerId, string $soNo, string $invoiceNo, string $amount, SalesOrderItem $soItem): string
    {
        DB::table('invoice')->insert([
            'invoice_no' => $invoiceNo,
            'company_id' => $companyId,
            'customer_id' => $customerId,
            'sales_order_no' => $soNo,
            'invoice_date' => now(),
            'due_date' => today(),
            'currency' => 'NGN',
            'gross_amount' => $amount,
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'invoice_amount' => $amount,
            'credit_amount' => '0.00',
            'settled_amount' => '0.00',
            'payment_status' => 'UNPAID',
            'payment_term' => 'IMMEDIATE',
        ]);

        DB::table('invoice_item')->insert([
            'invoice_no' => $invoiceNo,
            'item_no' => 1,
            'product_id' => $soItem->product_id,
            'is_free_item' => false,
            'quantity' => '10.000',
            'invoice_unit' => 'PCS',
            'unit_price' => '1000.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'subtotal_amount' => $amount,
            'sales_order_no' => $soItem->sales_order_no,
            'sales_order_item_no' => $soItem->item_no,
        ]);

        return $invoiceNo;
    }

    /**
     * Child racer: own Laravel app + PDO connection. Takes the row lock
     * FIRST (so READY means the lock is held), waits for GO, holds the lock
     * ~2s so the parent genuinely contends, releases, then runs the REAL
     * service and writes a JSON result.
     */
    private function allocationChildScript(): string
    {
        return <<<'PHP'
<?php
require $argv[5].'/vendor/autoload.php';
$app = require $argv[5].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$pdo = Illuminate\Support\Facades\DB::connection()->getPdo();
$pdo->beginTransaction();
$stmt = $pdo->prepare('select payment_id from payment where payment_id = ? for update');
$stmt->execute([(int) $argv[1]]);
$stmt->fetch(PDO::FETCH_ASSOC);

file_put_contents($argv[2], 'READY');
while (! str_starts_with((string) @file_get_contents($argv[3]), 'GO')) {
    usleep(20_000);
}
sleep(2); // hold the payment row lock so the parent truly blocks on it
$pdo->commit();

try {
    $payment = App\Models\Payment::findOrFail((int) $argv[1]);
    $result = $app->make(App\Services\FinanceService::class)->allocatePayment($payment);
    $total = 0.0;
    foreach ($result['allocations'] as $a) {
        $total += (float) $a['amount'];
    }
    file_put_contents($argv[4], json_encode(['total' => $total]));
} catch (Throwable $e) {
    file_put_contents($argv[4], json_encode(['total' => -1, 'error' => $e->getMessage()]));
}
PHP;
    }

    /** Child racer for the generation race: runs InvoiceService::generateIfTerminal. */
    private function generationChildScript(): string
    {
        return <<<'PHP'
<?php
require $argv[5].'/vendor/autoload.php';
$app = require $argv[5].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$pdo = Illuminate\Support\Facades\DB::connection()->getPdo();
$pdo->beginTransaction();
$stmt = $pdo->prepare('select sales_order_no from sales_order where sales_order_no = ? for update');
$stmt->execute([$argv[1]]);
$stmt->fetch(PDO::FETCH_ASSOC);

file_put_contents($argv[2], 'READY');
while (! str_starts_with((string) @file_get_contents($argv[3]), 'GO')) {
    usleep(20_000);
}
sleep(2); // hold the SO row lock so the parent truly blocks on it
$pdo->commit();

try {
    $order = App\Models\SalesOrder::whereKey($argv[1])->firstOrFail();
    $invoice = $app->make(App\Services\InvoiceService::class)->generateIfTerminal($order);
    file_put_contents($argv[4], json_encode(['invoice_no' => $invoice?->invoice_no]));
} catch (Throwable $e) {
    file_put_contents($argv[4], json_encode(['invoice_no' => null, 'error' => $e->getMessage()]));
}
PHP;
    }

    /** Remove every fixture row this class may have created (children first). */
    private function purgeResidue(): void
    {
        $companyLike = self::PREFIX_COMPANY.'%';
        $customerLike = 'FC8%';
        $employeeLike = 'FC8E.%';
        $productLike = 'FC8Q.%';

        // Money rows first, keyed on company_id (customers use factory ids).
        DB::table('credit_allocation')->whereIn('invoice_no', function ($q) use ($companyLike) {
            $q->select('invoice_no')->from('invoice')->where('company_id', 'like', $companyLike);
        })->delete();
        DB::table('credit_allocation')->whereIn('credit_no', function ($q) use ($companyLike) {
            $q->select('credit_no')->from('customer_credit')->where('company_id', 'like', $companyLike);
        })->delete();
        DB::table('payment_allocation')->whereIn('invoice_no', function ($q) use ($companyLike) {
            $q->select('invoice_no')->from('invoice')->where('company_id', 'like', $companyLike);
        })->delete();
        DB::table('payment_allocation')->whereIn('payment_id', function ($q) use ($companyLike) {
            $q->select('payment_id')->from('payment')->where('company_id', 'like', $companyLike);
        })->delete();
        DB::table('payment')->where('company_id', 'like', $companyLike)->delete();
        DB::table('customer_credit')->where('company_id', 'like', $companyLike)->delete();

        // Invoices (FK → SO), then the delivery chain, then the orders.
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
        DB::table('inventory')->where('customer_id', 'like', $customerLike)->delete();

        // Customers are global (no company_id): collect the fixture customers
        // through their employee link BEFORE dismantling it.
        $customerIds = DB::table('customer_employee')
            ->where('employee_id', 'like', $employeeLike)
            ->pluck('customer_id')
            ->all();
        DB::table('inventory')->whereIn('customer_id', $customerIds)->delete();

        DB::table('price_condition_item')->where('condition_price_no', 'like', 'FC8PC.%')->delete();
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
