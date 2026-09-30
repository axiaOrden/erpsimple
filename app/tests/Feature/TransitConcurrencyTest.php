<?php

namespace Tests\Feature;

use App\Enums\DifferenceDisposition;
use App\Enums\DifferenceReason;
use App\Enums\MovementType;
use App\Enums\TransitAllocationStatus;
use App\Enums\TransitStatus;
use App\Models\CompanyMaster;
use App\Models\CustomerMaster;
use App\Models\EmployeeMaster;
use App\Models\ProductMaster;
use App\Models\SalesOrder;
use App\Models\TransitStock;
use App\Models\TransitStockAllocation;
use App\Services\DeliveryService;
use App\Services\InventoryService;
use App\Services\PodService;
use App\Services\SalesOrderService;
use App\Services\ShipmentService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * REAL MariaDB row-locking concurrency tests for Phase 9 (same pattern as
 * Delivery/FinanceConcurrencyTest): fixtures use FC9.-prefixed ids, purged
 * in setUp()/tearDown(); two processes race the SAME business operation
 * through real FOR UPDATE locks. Invariants:
 *
 *  1. Two concurrent transit allocations cannot double-consume one balance.
 *  2. Concurrent release vs START of a mixed delivery: exactly one wins,
 *     and the resulting transit/stock state is consistent either way.
 *  3. Concurrent stock vs stock allocations serialize on source inventory —
 *     the combined delivery items never over-allocate a source.
 */
class TransitConcurrencyTest extends TestCase
{
    private const PREFIX_COMPANY = 'FC9.';

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

    public function test_concurrent_allocations_cannot_double_consume_transit(): void
    {
        [$companyId, $employee, $primary, $product, $order1, $order2] = $this->fixturesWithOrders();

        // A free balance of exactly 2 reusable transit, created through the
        // REAL chain (GI 10 → POD 8 accepted / 2 rejected WITH_EMPLOYEE).
        $root = $this->seedReusableViaPod($employee, $primary, $product);

        // Both racers request the FULL balance for their own order.
        [$go, $childOut] = $this->forkRacer(
            $this->allocationChildScript(),
            [$order2->sales_order_no, $employee->employee_id, '2', '2'],
        );

        $parentOk = true;
        $parentError = null;

        try {
            app(DeliveryService::class)->createAndAllocate(
                $order1->fresh(['items']),
                $employee,
                collect([['sales_order_item_no' => 1, 'qty' => '2', 'unit' => 'PCS', 'transit_qty' => '2']]),
            );
        } catch (\Throwable $e) {
            $parentOk = false; // legal: the child won the race
            $parentError = $e->getMessage();
        }

        $child = $this->childResult($childOut);

        // Diagnostics: a BOTH-racers-fail outcome means a setup bug, not a
        // race outcome — surface the errors instead of failing opaquely.
        if (! $parentOk && ! ($child['ok'] ?? false)) {
            $this->fail('Both racers failed — setup bug. Parent: '.$parentError.' | Child: '.($child['error'] ?? '?'));
        }

        // THE invariant: the 2-unit balance is consumed AT MOST once, in total
        // (all reservations descend from the seeded origin delivery item).
        $totalConsumed = (float) TransitStockAllocation::whereIn('transit_id', function ($q) use ($root) {
            $q->select('transit_id')->from('transit_stock')
                ->where('origin_delivery_no', $root->origin_delivery_no)
                ->where('origin_delivery_item_no', $root->origin_delivery_item_no);
        })
            ->whereIn('alloc_status', [TransitAllocationStatus::ACTIVE->value, TransitAllocationStatus::FINALIZED->value])
            ->sum('allocated_qty');

        $this->assertLessThanOrEqual(2.001, $totalConsumed, 'Concurrent allocations over-consumed the transit balance.');
        $this->assertEqualsWithDelta(2.0, $totalConsumed, 0.001, 'Exactly one racer consumed the balance.');
        $this->assertTrue($parentOk xor (bool) ($child['ok'] ?? false), 'Exactly one racer must win (each requested the full balance).');
    }

    public function test_release_and_start_race_exactly_one_wins(): void
    {
        [$companyId, $employee, $primary, $product, $order1] = $this->fixturesWithOrders();

        // 2 reusable transit via the real chain (also GI'd 10 from source).
        $this->seedReusableViaPod($employee, $primary, $product);

        // Mixed allocation: 8 stock + 2 transit, queued on a READY shipment.
        app(InventoryService::class)->adjustPhysical(
            $employee, $primary->customer_id, $product->product_id, '8', 'PCS', MovementType::GOODS_RECEIPT,
        );

        $delivery = app(DeliveryService::class)->createAndAllocate(
            $order1->fresh(['items']),
            $employee,
            collect([['sales_order_item_no' => 1, 'qty' => '10', 'unit' => 'PCS', 'transit_qty' => '2']]),
        )['delivery'];

        $shipment = app(ShipmentService::class)->createShipment($employee, $companyId, $primary->customer_id);
        app(ShipmentService::class)->attachDelivery($shipment, $delivery->delivery_no);
        app(ShipmentService::class)->markReady($shipment);

        // Racer 1 (child): releaseDelivery. Racer 2 (parent): START.
        [$go, $childOut] = $this->forkRacer(
            $this->releaseChildScript(),
            [$delivery->delivery_no],
        );

        $parentError = null;

        try {
            app(ShipmentService::class)->start($employee, $shipment->fresh());
        } catch (\Throwable $e) {
            $parentError = $e->getMessage();
        }

        $child = $this->childResult($childOut);

        $fresh = $delivery->fresh();

        if ($parentError === null) {
            // START won: physically irreversible — FINALIZED reservation.
            $this->assertSame('SHIPPED', $fresh->delivery_status->value);
            $finalized = TransitStockAllocation::where('delivery_no', $delivery->delivery_no)
                ->where('alloc_status', TransitAllocationStatus::FINALIZED->value)->first();
            $this->assertNotNull($finalized, 'START must finalize the transit reservation.');
            $this->assertSame(TransitStatus::REALLOCATED, $finalized->transit->transit_status);
        } else {
            // Release won: allocation reversed — RELEASED history, stock back.
            $this->assertTrue((bool) ($child['ok'] ?? false), 'Exactly one racer must win.');
            $this->assertSame('DRAFT', $fresh->delivery_status->value);
            $released = TransitStockAllocation::where('delivery_no', $delivery->delivery_no)
                ->where('alloc_status', TransitAllocationStatus::RELEASED->value)->first();
            $this->assertNotNull($released, 'Release must keep the reservation as RELEASED history.');
            $this->assertSame(TransitStatus::REUSABLE, $released->transit->transit_status);
        }
    }

    public function test_concurrent_source_allocations_cannot_overdraw_the_source(): void
    {
        [$companyId, $employee, $primary, $product, $order1, $order2] = $this->fixturesWithOrders();

        // Only 6 unrestricted at source; NO transit anywhere. Both racers
        // request 6 stock for their OWN order — together they would need 12
        // restricted from a 6-unit source. The inventory row lock must
        // serialize them; combined deliveries stay all-or-nothing.
        app(InventoryService::class)->adjustPhysical(
            $employee, $primary->customer_id, $product->product_id, '6', 'PCS', MovementType::GOODS_RECEIPT,
        );

        [$go, $childOut] = $this->forkRacer(
            $this->allocationChildScript(),
            [$order2->sales_order_no, $employee->employee_id, '6', '0'],
        );

        $parentOk = true;

        try {
            app(DeliveryService::class)->createAndAllocate(
                $order1->fresh(['items']),
                $employee,
                collect([['sales_order_item_no' => 1, 'qty' => '6', 'unit' => 'PCS', 'transit_qty' => '0']]),
            );
        } catch (\Throwable) {
            $parentOk = false;
        }

        $child = $this->childResult($childOut);

        // THE invariant: restricted stock at the source can never be negative
        // and never exceeds what was actually allocated (all-or-nothing
        // deliveries; no partial 4-unit leftovers).
        $inventory = DB::table('inventory')
            ->where('customer_id', $primary->customer_id)
            ->where('product_id', $product->product_id)
            ->first();

        $this->assertGreaterThanOrEqual(0.0, (float) $inventory->restricted_qty);
        $this->assertContains((float) $inventory->restricted_qty, [0.0, 6.0], 'A single all-or-nothing 6-unit allocation wins; the loser is rejected whole.');
        $this->assertTrue($parentOk xor (bool) ($child['ok'] ?? false), 'Exactly one racer must win the source-stock race.');
    }

    /** Create 2 REUSABLE transit via the real service chain (GI 10, POD 8+2). */
    private function seedReusableViaPod(EmployeeMaster $employee, CustomerMaster $primary, ProductMaster $product): TransitStock
    {
        app(InventoryService::class)->adjustPhysical(
            $employee, $primary->customer_id, $product->product_id, '10', 'PCS', MovementType::GOODS_RECEIPT,
        );

        $soldTo = CustomerMaster::factory()->forEmployee($employee)->create();
        $orders = app(SalesOrderService::class);
        $order = $orders->createDraft(
            $employee,
            $primary->customer_id,
            $primary->customer_id,
            $soldTo->customer_id,
            collect([['product_id' => $product->product_id, 'qty' => '10', 'unit' => 'PCS', 'unit_price' => null, 'price_override_reason' => null]]),
        )['order'];
        $orders->confirm($order);

        $delivery = app(DeliveryService::class)->createAndAllocate(
            $order->fresh(['items']),
            $employee,
            collect([['sales_order_item_no' => 1, 'qty' => '10', 'unit' => 'PCS']]),
        )['delivery'];

        $shipment = app(ShipmentService::class)->createShipment($employee, $delivery->company_id, $primary->customer_id);
        app(ShipmentService::class)->attachDelivery($shipment, $delivery->delivery_no);
        app(ShipmentService::class)->markReady($shipment);
        app(ShipmentService::class)->start($employee, $shipment);

        app(PodService::class)->confirmItem(
            $employee, $delivery->delivery_no, 1, '8', 'PCS',
            DifferenceReason::CUSTOMER_REJECTED, null, DifferenceDisposition::WITH_EMPLOYEE,
        );

        return TransitStock::where('origin_delivery_no', $delivery->delivery_no)->firstOrFail();
    }

    // ---- Harness ------------------------------------------------------------

    /**
     * Fork a child racer with its own Laravel app. Child: signals READY,
     * waits for GO, runs the script body, writes a JSON result.
     *
     * @param  array<int, string>  $args  passed to the script after the fixed argv
     * @return array{0: string, 1: string} go, out paths
     */
    private function forkRacer(string $scriptBody, array $args): array
    {
        $basePath = dirname(__DIR__, 2);
        $ready = tempnam(sys_get_temp_dir(), 'fc9r');
        $go = tempnam(sys_get_temp_dir(), 'fc9g');
        $out = tempnam(sys_get_temp_dir(), 'fc9o');

        $scriptResource = tmpfile();
        fwrite($scriptResource, $scriptBody);
        $scriptPath = stream_get_meta_data($scriptResource)['uri'];

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'pcntl_fork failed.');

        if ($pid === 0) {
            pcntl_exec(PHP_BINARY, [$scriptPath, $ready, $go, $out, $basePath, ...$args]);
            exit(127);
        }

        $this->childPid = $pid;
        $this->waitFor($ready, 'READY', 'child racer never became ready.');
        file_put_contents($go, 'GO');

        return [$go, $out];
    }

    /** @return array<string, mixed> */
    private function childResult(string $out): array
    {
        $this->waitFor($out, '{"', 'child racer never produced output.');

        $payload = json_decode((string) file_get_contents($out), true);
        $this->assertIsArray($payload);

        @pcntl_waitpid($this->childPid, $status, WNOHANG);
        $this->childPid = 0;

        return $payload;
    }

    /** Child racer: createAndAllocate(order, employee, [qty, transit_qty]). */
    private function allocationChildScript(): string
    {
        return <<<'PHP'
<?php
require $argv[4].'/vendor/autoload.php';
$app = require $argv[4].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

file_put_contents($argv[1], 'READY');
while (! str_starts_with((string) @file_get_contents($argv[2]), 'GO')) {
    usleep(20_000);
}

try {
    $order = App\Models\SalesOrder::whereKey($argv[5])->firstOrFail();
    $employee = App\Models\EmployeeMaster::findOrFail($argv[6]);
    $app->make(App\Services\DeliveryService::class)->createAndAllocate(
        $order->fresh(['items']),
        $employee,
        collect([['sales_order_item_no' => 1, 'qty' => $argv[7], 'unit' => 'PCS', 'transit_qty' => $argv[8]]]),
    );
    file_put_contents($argv[3], json_encode(['ok' => true]));
} catch (Throwable $e) {
    file_put_contents($argv[3], json_encode(['ok' => false, 'error' => $e->getMessage()]));
}
PHP;
    }

    /** Child racer: releaseDelivery(delivery_no). */
    private function releaseChildScript(): string
    {
        return <<<'PHP'
<?php
require $argv[4].'/vendor/autoload.php';
$app = require $argv[4].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

file_put_contents($argv[1], 'READY');
while (! str_starts_with((string) @file_get_contents($argv[2]), 'GO')) {
    usleep(20_000);
}

try {
    $delivery = App\Models\Delivery::findOrFail($argv[5]);
    $app->make(App\Services\DeliveryService::class)->releaseDelivery($delivery);
    file_put_contents($argv[3], json_encode(['ok' => true]));
} catch (Throwable $e) {
    file_put_contents($argv[3], json_encode(['ok' => false, 'error' => $e->getMessage()]));
}
PHP;
    }

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

    /** @return array{0: string, 1: EmployeeMaster, 2: CustomerMaster, 3: ProductMaster, 4: SalesOrder, 5: SalesOrder} */
    private function fixturesWithOrders(): array
    {
        $hex = bin2hex(random_bytes(4));
        $companyId = self::PREFIX_COMPANY.$hex;

        $company = CompanyMaster::factory()->create(['company_id' => $companyId]);
        $employee = EmployeeMaster::factory()->create(['employee_id' => 'FC9E.'.$hex, 'company_id' => $companyId]);
        $primary = CustomerMaster::factory()->primary()->forEmployee($employee)->create();
        $soldTo1 = CustomerMaster::factory()->forEmployee($employee)->create();
        $soldTo2 = CustomerMaster::factory()->forEmployee($employee)->create();
        $product = ProductMaster::factory()->forCompany($company)
            ->create(['product_id' => 'FC9Q.'.$hex, 'basic_unit' => 'PCS']);

        DB::table('product_unit_conversion')->insert([
            'product_id' => $product->product_id,
            'alternative_unit' => 'CTN',
            'numerator' => '24',
            'denominator' => '1',
        ]);
        DB::table('price_condition')->insert([
            'condition_price_no' => 'FC9PC.'.$hex,
            'company_id' => $companyId,
            'sales_region' => null,
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'active' => true,
        ]);
        DB::table('price_condition_item')->insert([
            'condition_price_no' => 'FC9PC.'.$hex,
            'product_id' => $product->product_id,
            'price' => '1000.00',
            'currency' => 'NGN',
            'tax_type' => 'NONE',
        ]);

        $orders = app(SalesOrderService::class);
        $lines = fn () => collect([[
            'product_id' => $product->product_id,
            'qty' => '10',
            'unit' => 'PCS',
            'unit_price' => null,
            'price_override_reason' => null,
        ]]);

        $order1 = $orders->createDraft($employee, $primary->customer_id, $primary->customer_id, $soldTo1->customer_id, $lines())['order'];
        $orders->confirm($order1);
        $order2 = $orders->createDraft($employee, $primary->customer_id, $primary->customer_id, $soldTo2->customer_id, $lines())['order'];
        $orders->confirm($order2);

        // NOTE: no stock is seeded here — each race seeds exactly what its
        // premise requires (additive adjustPhysical would corrupt the setup).

        return [$companyId, $employee, $primary, $product, $order1->fresh(['items']), $order2->fresh(['items'])];
    }

    /** Remove every fixture row this class may have created (children first). */
    private function purgeResidue(): void
    {
        $companyLike = self::PREFIX_COMPANY.'%';
        $employeeLike = 'FC9E.%';

        DB::table('transit_stock_allocation')->whereIn('transit_id', function ($q) use ($companyLike) {
            $q->select('transit_id')->from('transit_stock')->where('company_id', 'like', $companyLike);
        })->delete();

        // Break self-referencing lineage + cross-table references first.
        DB::table('transit_stock')->where('company_id', 'like', $companyLike)->update([
            'parent_transit_id' => null,
            'resolved_movement_id' => null,
            'resolved_to_delivery_no' => null,
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

        DB::table('price_condition_item')->where('condition_price_no', 'like', 'FC9PC.%')->delete();
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
