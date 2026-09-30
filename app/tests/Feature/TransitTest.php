<?php

namespace Tests\Feature;

use App\Enums\DeliveryStatus;
use App\Enums\DifferenceDisposition;
use App\Enums\DifferenceReason;
use App\Enums\LiabilityParty;
use App\Enums\MovementType;
use App\Enums\TransitAllocationStatus;
use App\Enums\TransitStatus;
use App\Models\AppUser;
use App\Models\CompanyMaster;
use App\Models\CustomerMaster;
use App\Models\Delivery;
use App\Models\DeliveryConfirmation;
use App\Models\EmployeeMaster;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\PriceCondition;
use App\Models\PriceConditionItem;
use App\Models\ProductMaster;
use App\Models\ProductUnitConversion;
use App\Models\SalesOrder;
use App\Models\TransitStock;
use App\Models\TransitStockAllocation;
use App\Services\DeliveryService;
use App\Services\InventoryService;
use App\Services\PodService;
use App\Services\ProductUnitService;
use App\Services\SalesOrderService;
use App\Services\ShipmentService;
use App\Services\TransitService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Phase 9 — transit stock (docs/TRANSIT_STOCK_CORRECTION.md REV 3 +
 * implementation rulings 1–3). GOODS_ISSUE stays immutable; POD differences
 * become traceable transit rows per the (reason × disposition) matrix;
 * allocation is reversible until Shipment START.
 *
 * Every chain uses its OWN sold-to customer (unpaid invoices create positive
 * exposure, which would legitimately block a repeat confirm for the same
 * debtor) and its own employee/primary where custody matters.
 */
class TransitTest extends TestCase
{
    use DatabaseTransactions;

    private CompanyMaster $company;

    private CompanyMaster $otherCompany;

    private EmployeeMaster $employee;

    private AppUser $seller;

    private AppUser $admin;

    private EmployeeMaster $otherEmployee;

    private AppUser $otherSeller;

    private AppUser $otherAdmin;

    private AppUser $superadmin;

    private CustomerMaster $primary;

    private CustomerMaster $primaryB;

    private CustomerMaster $otherPrimary;

    private EmployeeMaster $employeeB;

    private ProductMaster $product;

    private DeliveryService $deliveries;

    private ShipmentService $shipments;

    private PodService $pod;

    private TransitService $transit;

    private SalesOrderService $orders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyMaster::factory()->create();
        $this->employee = EmployeeMaster::factory()->create(['company_id' => $this->company->company_id]);
        $this->seller = AppUser::factory()->salesEmployee()->create([
            'company_id' => $this->company->company_id,
            'employee_id' => $this->employee->employee_id,
        ]);
        $this->admin = AppUser::factory()->companyAdmin()->create([
            'company_id' => $this->company->company_id,
            'employee_id' => null,
        ]);

        $this->otherCompany = CompanyMaster::factory()->create();
        $this->otherEmployee = EmployeeMaster::factory()->create(['company_id' => $this->otherCompany->company_id]);
        $this->otherSeller = AppUser::factory()->salesEmployee()->create([
            'company_id' => $this->otherCompany->company_id,
            'employee_id' => $this->otherEmployee->employee_id,
        ]);
        $this->otherAdmin = AppUser::factory()->companyAdmin()->create([
            'company_id' => $this->otherCompany->company_id,
            'employee_id' => null,
        ]);
        $this->superadmin = AppUser::factory()->superadmin()->create();

        $this->primary = CustomerMaster::factory()->primary()->forEmployee($this->employee)->create();

        // Employee B: same company, different van/route — the realistic
        // "another employee's transit stock" scenario (products are
        // company-scoped, so cross-company sharing is impossible anyway).
        $this->employeeB = EmployeeMaster::factory()->create(['company_id' => $this->company->company_id]);
        $this->primaryB = CustomerMaster::factory()->primary()->forEmployee($this->employeeB)->create();

        $this->otherPrimary = CustomerMaster::factory()->primary()->forEmployee($this->otherEmployee)->create();

        $this->product = ProductMaster::factory()->forCompany($this->company)->create(['basic_unit' => 'PCS']);

        // A single named price condition so confirm() resolves a price.
        $conditionNo = 'PC-TRANSIT-'.$this->company->company_id;
        PriceCondition::create([
            'condition_price_no' => $conditionNo,
            'company_id' => $this->company->company_id,
            'sales_region' => null,
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'active' => true,
        ]);
        PriceConditionItem::create([
            'condition_price_no' => $conditionNo,
            'product_id' => $this->product->product_id,
            'price' => '1000.00',
            'currency' => 'NGN',
            'tax_type' => 'NONE',
        ]);

        ProductUnitConversion::create([
            'product_id' => $this->product->product_id,
            'alternative_unit' => 'CTN',
            'numerator' => '24',
            'denominator' => '1',
        ]);

        $this->deliveries = app(DeliveryService::class);
        $this->shipments = app(ShipmentService::class);
        $this->pod = app(PodService::class);
        $this->transit = app(TransitService::class);
        $this->orders = app(SalesOrderService::class);
    }

    // ---- Helpers ------------------------------------------------------------

    private function newCustomer(?EmployeeMaster $employee = null): CustomerMaster
    {
        return CustomerMaster::factory()->forEmployee($employee ?? $this->employee)->create();
    }

    private function receiveStock(string $qty = '2000', ?CustomerMaster $primary = null): void
    {
        app(InventoryService::class)->adjustPhysical(
            $this->employee, ($primary ?? $this->primary)->customer_id, $this->product->product_id, $qty, 'PCS', MovementType::GOODS_RECEIPT,
        );
    }

    private function confirmedOrder(EmployeeMaster $employee, CustomerMaster $primary, CustomerMaster $soldTo, string $qty = '10', string $unit = 'PCS'): SalesOrder
    {
        $order = $this->orders->createDraft(
            $employee,
            $primary->customer_id,
            $primary->customer_id,
            $soldTo->customer_id,
            collect([[
                'product_id' => $this->product->product_id,
                'qty' => $qty,
                'unit' => $unit,
                'unit_price' => null,
                'price_override_reason' => null,
            ]]),
        )['order'];

        $this->orders->confirm($order);

        return $order->fresh(['items']);
    }

    private function allocate(EmployeeMaster $employee, SalesOrder $order, string $qty, string $unit = 'PCS', string $transitQty = '0'): Delivery
    {
        return $this->deliveries->createAndAllocate(
            $order->fresh(['items']),
            $employee,
            collect([[
                'sales_order_item_no' => 1,
                'qty' => $qty,
                'unit' => $unit,
                'transit_qty' => $transitQty,
            ]]),
        )['delivery'];
    }

    private function ship(EmployeeMaster $employee, Delivery $delivery, CustomerMaster $primary): void
    {
        $shipment = $this->shipments->createShipment($employee, $this->company->company_id, $primary->customer_id);
        $this->shipments->attachDelivery($shipment, $delivery->delivery_no);
        $this->shipments->markReady($shipment);
        $this->shipments->start($employee, $shipment);
    }

    private function pod(EmployeeMaster $employee, Delivery $delivery, string $confirmedQty, DifferenceReason $reason = DifferenceReason::NONE, ?DifferenceDisposition $disposition = null): void
    {
        $this->pod->confirmItem(
            $employee, $delivery->delivery_no, 1, $confirmedQty, 'PCS', $reason, null, $disposition,
        );
    }

    /**
     * Full chain: order → allocate → ship → POD difference → transit row.
     * Each call gets a FRESH sold-to customer unless one is passed.
     */
    private function transitFrom(
        EmployeeMaster $employee,
        CustomerMaster $primary,
        string $shipped,
        string $accepted,
        DifferenceReason $reason,
        DifferenceDisposition $disposition,
        ?CustomerMaster $soldTo = null,
    ): TransitStock {
        $this->receiveStock('2000', $primary);
        $order = $this->confirmedOrder($employee, $primary, $soldTo ?? $this->newCustomer(), $shipped);
        $delivery = $this->allocate($employee, $order, $shipped);
        $this->ship($employee, $delivery, $primary);
        $this->pod($employee, $delivery, $accepted, $reason, $disposition);

        return TransitStock::where('origin_delivery_no', $delivery->delivery_no)->firstOrFail();
    }

    private function inv(?CustomerMaster $primary = null): Inventory
    {
        return Inventory::where('customer_id', ($primary ?? $this->primary)->customer_id)
            ->where('product_id', $this->product->product_id)
            ->firstOrFail();
    }

    // ---- 1. Disposition matrix ---------------------------------------------

    public function test_customer_rejected_retained_goods_become_reusable_transit(): void
    {
        $transit = $this->transitFrom(
            $this->employee, $this->primary, '10', '8',
            DifferenceReason::CUSTOMER_REJECTED, DifferenceDisposition::WITH_EMPLOYEE,
        );

        $this->assertSame(TransitStatus::REUSABLE, $transit->transit_status);
        $this->assertSame('2.000', (string) $transit->quantity);
        $this->assertSame('2.000', (string) $transit->original_quantity);
        $this->assertSame($this->employee->employee_id, $transit->holding_employee_id);
        $this->assertSame($this->primary->customer_id, $transit->source_customer_id);
        $this->assertNotNull($transit->origin_shipment_no);
        $this->assertSame('2.000', $this->transit->reusableBalance($this->employee->employee_id, $this->product->product_id, $this->primary->customer_id));

        // Conservation: 10 issued = 8 confirmed + 2 in transit (NOT back at source).
        $this->assertSame('0.000', (string) $this->inv()->restricted_qty);
        $this->assertSame('1990.000', (string) $this->inv()->unrestricted_qty, 'On-hand 2000 − 10 issued; the 2 unaccepted sit in transit.');
    }

    public function test_damage_reasons_create_damaged_rows_with_liability(): void
    {
        $employeeDamage = $this->transitFrom(
            $this->employee, $this->primary, '10', '8',
            DifferenceReason::EMPLOYEE_DAMAGE, DifferenceDisposition::DAMAGED,
        );
        $this->assertSame(TransitStatus::DAMAGED, $employeeDamage->transit_status);
        $this->assertSame(LiabilityParty::EMPLOYEE, $employeeDamage->liability_party);

        $distributorDamage = $this->transitFrom(
            $this->employee, $this->primary, '10', '8',
            DifferenceReason::DISTRIBUTOR_DAMAGE, DifferenceDisposition::DAMAGED,
        );
        $this->assertSame(LiabilityParty::DISTRIBUTOR, $distributorDamage->liability_party);
    }

    public function test_invalid_reason_disposition_combinations_are_rejected(): void
    {
        $this->receiveStock('2000');
        $order = $this->confirmedOrder($this->employee, $this->primary, $this->newCustomer(), '10');
        $delivery = $this->allocate($this->employee, $order, '10');
        $this->ship($this->employee, $delivery, $this->primary);

        try {
            $this->pod($this->employee, $delivery, '8', DifferenceReason::CUSTOMER_REJECTED, DifferenceDisposition::DAMAGED);
            $this->fail('Contradictory reason × disposition must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        // The whole POD transaction rolled back: no confirmation, no transit.
        $this->assertSame(0, DeliveryConfirmation::where('delivery_no', $delivery->delivery_no)->count());
        $this->assertSame(0, TransitStock::where('origin_delivery_no', $delivery->delivery_no)->count());
    }

    public function test_short_delivery_and_other_never_manufacture_stock(): void
    {
        foreach ([
            [DifferenceReason::SHORT_DELIVERY, DifferenceDisposition::UNKNOWN],
            [DifferenceReason::SHORT_DELIVERY, DifferenceDisposition::WITH_EMPLOYEE],
            [DifferenceReason::OTHER, DifferenceDisposition::WITH_EMPLOYEE],
        ] as [$reason, $disposition]) {
            $transit = $this->transitFrom($this->employee, $this->primary, '10', '8', $reason, $disposition);

            $this->assertSame(TransitStatus::DISCREPANCY, $transit->transit_status);
            $this->assertSame('2.000', (string) $transit->quantity, 'Discrepancy is represented, not stock.');
        }

        $this->assertSame('0.000', $this->transit->reusableBalance($this->employee->employee_id, $this->product->product_id, $this->primary->customer_id));
    }

    public function test_returned_at_source_creates_pending_receipt_and_verification_restores_source(): void
    {
        $transit = $this->transitFrom(
            $this->employee, $this->primary, '10', '8',
            DifferenceReason::RETURNED, DifferenceDisposition::AT_SOURCE,
        );

        $this->assertSame(TransitStatus::PENDING_SOURCE_RECEIPT, $transit->transit_status);
        $this->assertSame($this->employee->employee_id, $transit->claimed_by_employee_id);
        $this->assertSame('1990.000', (string) $this->inv()->unrestricted_qty, 'A claim alone restores nothing.');

        $verified = $this->transit->verifySourceReceipt($this->admin, $transit);

        $this->assertSame(TransitStatus::RETURNED, $verified->transit_status);
        $this->assertSame('1992.000', (string) $this->inv()->unrestricted_qty);

        $movement = InventoryMovement::findOrFail($verified->resolved_movement_id);
        $this->assertSame(MovementType::VAN_RETURN, $movement->movement_type);
        $this->assertSame('2.000', (string) $movement->quantity);
        $this->assertSame('TRANSIT_RETURN', $movement->reference_type);
        $this->assertSame((string) $verified->transit_id, $movement->reference_no);
    }

    public function test_claimant_cannot_verify_own_source_receipt(): void
    {
        // The claimant holds the stock and has NO user of their own, so a
        // company-admin user can (illegitimately) be bound to that identity.
        $claimant = EmployeeMaster::factory()->create(['company_id' => $this->company->company_id]);
        $claimantCustomer = CustomerMaster::factory()->primary()->forEmployee($claimant)->create();
        $claimantAdminUser = AppUser::factory()->companyAdmin()->create([
            'company_id' => $this->company->company_id,
            'employee_id' => $claimant->employee_id,
        ]);

        $this->receiveStock('2000', $claimantCustomer);
        $order = $this->confirmedOrder($claimant, $claimantCustomer, $this->newCustomer($claimant), '10');
        $delivery = $this->allocate($claimant, $order, '10');
        $this->ship($claimant, $delivery, $claimantCustomer);
        $this->pod($claimant, $delivery, '8', DifferenceReason::RETURNED, DifferenceDisposition::AT_SOURCE);

        $transit = TransitStock::where('origin_delivery_no', $delivery->delivery_no)->firstOrFail();
        $this->assertSame(TransitStatus::PENDING_SOURCE_RECEIPT, $transit->transit_status);

        // The claimant (through their admin user) can NEVER verify their own receipt.
        try {
            $this->transit->verifySourceReceipt($claimantAdminUser, $transit);
            $this->fail('The claimant must never verify their own receipt.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        // A non-admin user cannot verify at all.
        try {
            $this->transit->verifySourceReceipt($this->seller, $transit);
            $this->fail('Non-admin verification must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(TransitStatus::PENDING_SOURCE_RECEIPT, $transit->fresh()->transit_status);

        // A clean company admin CAN verify.
        $verified = $this->transit->verifySourceReceipt($this->admin, $transit);
        $this->assertSame(TransitStatus::RETURNED, $verified->transit_status);
    }

    // ---- Discrepancy resolutions --------------------------------------------

    public function test_discrepancy_resolutions_found_damaged_lost(): void
    {
        $found = $this->transitFrom($this->employee, $this->primary, '10', '8', DifferenceReason::SHORT_DELIVERY, DifferenceDisposition::UNKNOWN);
        $damaged = $this->transitFrom($this->employee, $this->primary, '10', '8', DifferenceReason::OTHER, DifferenceDisposition::UNKNOWN);
        $lost = $this->transitFrom($this->employee, $this->primary, '10', '8', DifferenceReason::SHORT_DELIVERY, DifferenceDisposition::UNKNOWN);
        $writeOffTarget = $this->transitFrom($this->employee, $this->primary, '10', '8', DifferenceReason::EMPLOYEE_DAMAGE, DifferenceDisposition::DAMAGED);

        // Holding employee reports "found intact".
        $resolved = $this->transit->resolveFound($this->seller, $found);
        $this->assertSame(TransitStatus::REUSABLE, $resolved->transit_status);

        // Admin confirms damage with liability.
        $damagedRow = $this->transit->resolveDamaged($this->admin, $damaged, LiabilityParty::EMPLOYEE);
        $this->assertSame(TransitStatus::DAMAGED, $damagedRow->transit_status);
        $this->assertSame(LiabilityParty::EMPLOYEE, $damagedRow->liability_party);

        // Admin (only) confirms loss.
        $lostRow = $this->transit->resolveLost($this->admin, $lost);
        $this->assertSame(TransitStatus::LOSS, $lostRow->transit_status);

        // LOSS / WRITTEN_OFF are admin-only: custody is not enough.
        try {
            $this->transit->writeOff($this->seller, $writeOffTarget);
            $this->fail('Write-off must be admin-only.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $writtenOff = $this->transit->writeOff($this->admin, $writeOffTarget);
        $this->assertSame(TransitStatus::WRITTEN_OFF, $writtenOff->transit_status);
    }

    public function test_discrepancy_found_at_source_restores_source_via_verification(): void
    {
        $transit = $this->transitFrom($this->employee, $this->primary, '10', '8', DifferenceReason::SHORT_DELIVERY, DifferenceDisposition::UNKNOWN);

        $resolved = $this->transit->resolveFoundAtSource($this->admin, $transit);

        $this->assertSame(TransitStatus::RETURNED, $resolved->transit_status);
        $this->assertSame('1992.000', (string) $this->inv()->unrestricted_qty);
    }

    // ---- Ruling 1: portions + START semantics --------------------------------

    public function test_mixed_start_issues_stock_portion_only(): void
    {
        // The employee holds 2 reusable transit from an earlier route.
        $this->transitFrom($this->employee, $this->primary, '10', '8', DifferenceReason::CUSTOMER_REJECTED, DifferenceDisposition::WITH_EMPLOYEE);
        $this->assertSame('1990.000', (string) $this->inv()->unrestricted_qty);

        $order = $this->confirmedOrder($this->employee, $this->primary, $this->newCustomer(), '10');
        $delivery = $this->allocate($this->employee, $order, '10', 'PCS', '2');

        // Allocation: stock portion 8 unrestricted → restricted; transit 2 reserved.
        $this->assertSame('1982.000', (string) $this->inv()->unrestricted_qty);
        $this->assertSame('8.000', (string) $this->inv()->restricted_qty);

        // Portions follow ruling 1 from THE centralized derivation.
        $item = $delivery->items()->firstOrFail();
        $portions = $this->transit->portionsBasic($item, '10.000');
        $this->assertSame('2.000', $portions['transit_basic']);
        $this->assertSame('8.000', $portions['stock_basic']);

        $this->ship($this->employee, $delivery, $this->primary);

        // START consumed the STOCK portion only.
        $this->assertSame('0.000', (string) $this->inv()->restricted_qty);
        $this->assertSame('1982.000', (string) $this->inv()->unrestricted_qty, 'On-hand decreased by the stock portion only (8 of the 10 were issued from source).');

        $gi = InventoryMovement::where('reference_type', 'DELIVERY')
            ->where('reference_no', $delivery->delivery_no)
            ->where('movement_type', MovementType::GOODS_ISSUE)
            ->get();
        $this->assertCount(1, $gi);
        $this->assertSame('-8.000', (string) $gi[0]->quantity, 'GI = −stock_basic only; the transit 2 were issued once at their origin.');

        // Reservations finalized; transit rows REALLOCATED (irreversible).
        $allocation = TransitStockAllocation::where('delivery_no', $delivery->delivery_no)->firstOrFail();
        $this->assertSame(TransitAllocationStatus::FINALIZED, $allocation->alloc_status);
        $this->assertSame(TransitStatus::REALLOCATED, $allocation->transit->transit_status);
        $this->assertSame('0.000', $this->transit->reusableBalance($this->employee->employee_id, $this->product->product_id, $this->primary->customer_id));
    }

    public function test_pure_transit_start_creates_no_gi_and_touches_no_inventory(): void
    {
        // Seed exactly 2 reusable (10 shipped, 8 accepted, 2 retained).
        $this->transitFrom($this->employee, $this->primary, '10', '8', DifferenceReason::CUSTOMER_REJECTED, DifferenceDisposition::WITH_EMPLOYEE);

        $order = $this->confirmedOrder($this->employee, $this->primary, $this->newCustomer(), '10');
        $delivery = $this->allocate($this->employee, $order, '2', 'PCS', '2');

        $this->ship($this->employee, $delivery, $this->primary);

        $this->assertSame(0, InventoryMovement::where('reference_no', $delivery->delivery_no)->count(), 'Pure-transit delivery: NO inventory movement at all.');
        $this->assertSame(DeliveryStatus::SHIPPED, $delivery->fresh()->delivery_status);
        $this->assertSame(TransitStatus::REALLOCATED, TransitStockAllocation::where('delivery_no', $delivery->delivery_no)->firstOrFail()->transit->transit_status);
    }

    public function test_pure_stock_start_remains_phase_6_behavior(): void
    {
        $this->receiveStock('10');
        $order = $this->confirmedOrder($this->employee, $this->primary, $this->newCustomer(), '10');
        $delivery = $this->allocate($this->employee, $order, '10');
        $this->ship($this->employee, $delivery, $this->primary);

        $gi = InventoryMovement::where('reference_no', $delivery->delivery_no)
            ->where('movement_type', MovementType::GOODS_ISSUE)->firstOrFail();
        $this->assertSame('-10.000', (string) $gi->quantity);
        $this->assertSame('0.000', (string) $this->inv()->restricted_qty);
        $this->assertSame('0.000', (string) $this->inv()->unrestricted_qty);
    }

    // ---- Ruling 3: reversible allocation until START --------------------------

    public function test_release_before_start_restores_stock_and_transit(): void
    {
        $this->transitFrom($this->employee, $this->primary, '10', '8', DifferenceReason::CUSTOMER_REJECTED, DifferenceDisposition::WITH_EMPLOYEE);

        $order = $this->confirmedOrder($this->employee, $this->primary, $this->newCustomer(), '10');
        $delivery = $this->allocate($this->employee, $order, '10', 'PCS', '2');

        $this->deliveries->releaseDelivery($delivery);

        // STOCK portion restored (2000 seeded − 10 GI'd − 8 reserved + 8 restored).
        $this->assertSame('1990.000', (string) $this->inv()->unrestricted_qty);
        $this->assertSame('0.000', (string) $this->inv()->restricted_qty);

        // TRANSIT portion restored as REUSABLE; allocation kept as history.
        $allocation = TransitStockAllocation::where('delivery_no', $delivery->delivery_no)->firstOrFail();
        $this->assertSame(TransitAllocationStatus::RELEASED, $allocation->alloc_status);
        $this->assertNotNull($allocation->released_at);
        $this->assertSame(TransitStatus::REUSABLE, $allocation->transit->transit_status);
        $this->assertSame('2.000', $this->transit->reusableBalance($this->employee->employee_id, $this->product->product_id, $this->primary->customer_id));

        // Released transit can subsequently fulfill another valid delivery.
        $secondOrder = $this->confirmedOrder($this->employee, $this->primary, $this->newCustomer(), '10');
        $second = $this->allocate($this->employee, $secondOrder, '2', 'PCS', '2');
        $this->assertSame(TransitAllocationStatus::ACTIVE, TransitStockAllocation::where('delivery_no', $second->delivery_no)->firstOrFail()->alloc_status);
    }

    public function test_started_transit_allocation_cannot_be_released(): void
    {
        $this->transitFrom($this->employee, $this->primary, '10', '8', DifferenceReason::CUSTOMER_REJECTED, DifferenceDisposition::WITH_EMPLOYEE);

        $order = $this->confirmedOrder($this->employee, $this->primary, $this->newCustomer(), '10');
        $delivery = $this->allocate($this->employee, $order, '10', 'PCS', '2');
        $this->ship($this->employee, $delivery, $this->primary);

        try {
            $this->deliveries->releaseDelivery($delivery);
            $this->fail('A STARTED (shipped) delivery can never be released.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $allocation = TransitStockAllocation::where('delivery_no', $delivery->delivery_no)->firstOrFail();
        $this->assertSame(TransitAllocationStatus::FINALIZED, $allocation->alloc_status);
        $this->assertSame(TransitStatus::REALLOCATED, $allocation->transit->fresh()->transit_status);
    }

    // ---- Custody / authorization ----------------------------------------------

    public function test_employee_a_cannot_consume_employee_b_transit(): void
    {
        // B holds 2 reusable from their own route/source (B-assigned customer).
        $this->transitFrom(
            $this->employeeB, $this->primaryB, '10', '8',
            DifferenceReason::CUSTOMER_REJECTED, DifferenceDisposition::WITH_EMPLOYEE,
            $this->newCustomer($this->employeeB),
        );
        $this->assertSame('2.000', $this->transit->reusableBalance($this->employeeB->employee_id, $this->product->product_id, $this->primaryB->customer_id));

        // A's order sources from A's primary — A has NO transit balance there,
        // so A can never consume B's stock (custody is per holding employee).
        $this->receiveStock('2000');
        $aOrder = $this->confirmedOrder($this->employee, $this->primary, $this->newCustomer(), '10');

        try {
            $this->allocate($this->employee, $aOrder, '5', 'PCS', '2');
            $this->fail('Employee A must not consume Employee B transit stock.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode(), 'A has no reusable balance of their own.');
        }

        // B consumes their OWN balance — allowed (B's order sources from B's primary).
        $this->receiveStock('2000', $this->primaryB);
        $bOrder = $this->confirmedOrder($this->employeeB, $this->primaryB, $this->newCustomer($this->employeeB), '10');
        $this->allocate($this->employeeB, $bOrder, '5', 'PCS', '2');
        $this->assertSame('0.000', $this->transit->reusableBalance($this->employeeB->employee_id, $this->product->product_id, $this->primaryB->customer_id));
    }

    public function test_return_initiation_requires_physical_custody_even_for_admin_roles(): void
    {
        $transit = $this->transitFrom($this->employee, $this->primary, '10', '8', DifferenceReason::CUSTOMER_REJECTED, DifferenceDisposition::WITH_EMPLOYEE);

        try {
            $this->transit->initiateReturn($this->otherEmployee, $transit);
            $this->fail('Non-custodian return initiation must fail.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // Ruling 2: an admin-role employee who is NOT the custodian is still
        // blocked by the physical-custody rule.
        $adminWithEmployee = EmployeeMaster::factory()->create(['company_id' => $this->company->company_id]);
        $adminEmployeeUser = AppUser::factory()->companyAdmin()->create([
            'company_id' => $this->company->company_id,
            'employee_id' => $adminWithEmployee->employee_id,
        ]);

        try {
            $this->transit->initiateReturn($adminEmployeeUser->employee, $transit);
            $this->fail('Admin-role employees must not bypass custody.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // The custodian CAN initiate.
        $claimed = $this->transit->initiateReturn($this->employee, $transit);
        $this->assertSame(TransitStatus::PENDING_SOURCE_RECEIPT, $claimed->transit_status);
    }

    public function test_foreign_company_admin_cannot_act_on_transit_rows(): void
    {
        $transit = $this->transitFrom($this->employee, $this->primary, '10', '8', DifferenceReason::RETURNED, DifferenceDisposition::AT_SOURCE);

        foreach ([
            fn () => $this->transit->verifySourceReceipt($this->otherSeller, $transit),
            fn () => $this->transit->verifySourceReceipt($this->otherAdmin, $transit),
            fn () => $this->transit->resolveFound($this->otherSeller, $transit),
            fn () => $this->transit->resolveLost($this->otherAdmin, $transit),
            fn () => $this->transit->writeOff($this->otherAdmin, $transit),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Foreign-company users must not act on transit rows.');
            } catch (HttpException $e) {
                $this->assertContains($e->getStatusCode(), [403, 422]);
            }
        }

        // Superadmin retains cross-company authority.
        $verified = $this->transit->verifySourceReceipt($this->superadmin, $transit);
        $this->assertSame(TransitStatus::RETURNED, $verified->transit_status);
    }

    // ---- Guard rails ------------------------------------------------------------

    public function test_transit_portion_can_never_exceed_delivery_item_quantity(): void
    {
        $this->receiveStock('2000');
        $order = $this->confirmedOrder($this->employee, $this->primary, $this->newCustomer(), '10');

        try {
            $this->allocate($this->employee, $order, '5', 'PCS', '7');
            $this->fail('Transit portion larger than the requested quantity must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_allocation_cannot_exceed_reusable_transit_balance(): void
    {
        $this->transitFrom($this->employee, $this->primary, '10', '8', DifferenceReason::CUSTOMER_REJECTED, DifferenceDisposition::WITH_EMPLOYEE);

        $order = $this->confirmedOrder($this->employee, $this->primary, $this->newCustomer(), '10');

        try {
            $this->allocate($this->employee, $order, '10', 'PCS', '3');
            $this->fail('Consumption above the reusable balance must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        // Nothing was partially consumed (all-or-nothing delivery transaction).
        $this->assertSame('2.000', $this->transit->reusableBalance($this->employee->employee_id, $this->product->product_id, $this->primary->customer_id));
        $this->assertSame(0, Delivery::where('sales_order_no', $order->sales_order_no)->count());
    }

    // ---- Conservation (Example A end to end) -----------------------------------

    public function test_conservation_example_a_rejection_then_reallocation(): void
    {
        // 1. GI 10; customer 1 accepts 8; 2 REUSABLE.
        $this->receiveStock('10');
        $order1 = $this->confirmedOrder($this->employee, $this->primary, $this->newCustomer(), '10');
        $delivery1 = $this->allocate($this->employee, $order1, '10');
        $this->ship($this->employee, $delivery1, $this->primary);
        $this->pod($this->employee, $delivery1, '8', DifferenceReason::CUSTOMER_REJECTED, DifferenceDisposition::WITH_EMPLOYEE);

        // 2. Reallocate the 2 to customer 2's order (pure-transit delivery).
        $order2 = $this->confirmedOrder($this->employee, $this->primary, $this->newCustomer(), '10');
        $delivery2 = $this->allocate($this->employee, $order2, '2', 'PCS', '2');
        $this->ship($this->employee, $delivery2, $this->primary);

        // 3. Customer 2 accepts everything.
        $this->pod($this->employee, $delivery2, '2');

        // Conservation: exactly ONE goods issue of −10 exists (the origin);
        // the reallocation created NO second GI.
        $gis = InventoryMovement::where('movement_type', MovementType::GOODS_ISSUE)
            ->where('product_id', $this->product->product_id)
            ->get();
        $this->assertCount(1, $gis);
        $this->assertSame('-10.000', (string) $gis[0]->quantity);

        // 10 issued = 8 (customer 1) + 2 (customer 2) confirmed.
        $units = app(ProductUnitService::class);
        $confirmedTotal = (float) DeliveryConfirmation::whereIn('delivery_no', [$delivery1->delivery_no, $delivery2->delivery_no])
            ->get()
            ->sum(fn ($c) => (float) $units->toBasicById($this->product->product_id, (string) $c->confirmed_qty, (string) $c->confirmed_unit));
        $this->assertEqualsWithDelta(10.0, $confirmedTotal, 0.001);

        // No open transit remains; source available went 10 → 0 → 0.
        $this->assertSame('0.000', $this->transit->reusableBalance($this->employee->employee_id, $this->product->product_id, $this->primary->customer_id));
        $this->assertSame('0.000', (string) $this->inv()->unrestricted_qty);
        $this->assertSame('0.000', (string) $this->inv()->restricted_qty);
    }
}
