<?php

namespace App\Services;

use App\Enums\DifferenceDisposition;
use App\Enums\DifferenceReason;
use App\Enums\LiabilityParty;
use App\Enums\MovementType;
use App\Enums\TransitAllocationStatus;
use App\Enums\TransitStatus;
use App\Models\AppUser;
use App\Models\DeliveryItem;
use App\Models\EmployeeMaster;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\TransitStock;
use App\Models\TransitStockAllocation;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9 — transit stock (docs/TRANSIT_STOCK_CORRECTION.md REV 3).
 *
 * Invariants:
 *  - GOODS_ISSUE is immutable; transit rows never rewrite it.
 *  - Source available stock changes ONLY via a verified source receipt
 *    (VAN_RETURN movement + unrestricted +=), never by the claimant.
 *  - difference_reason × difference_disposition → transit state (matrix);
 *    SHORT_DELIVERY / OTHER never manufacture stock (DISCREPANCY).
 *  - Allocation is REVERSIBLE until Shipment START (ruling 3): reservations
 *    split a dedicated child row (ACTIVE → RELEASED back to REUSABLE, or
 *    ACTIVE → FINALIZED at START, physically irreversible).
 *  - The authoritative portion derivation for Delivery/Shipment lives HERE
 *    (single formula, ruling 1): transit_basic = Σ ACTIVE|FINALIZED
 *    allocations; stock_basic = delivery-item basic − transit_basic.
 *  - Custody-aware, company-scoped authorization; the claimant can never
 *    verify their own source receipt; admins do not bypass custody for
 *    return initiation (ruling 2).
 */
class TransitService
{
    /**
     * THE single derivation of a Delivery Item's physical portions
     * (ruling 1). DeliveryService and ShipmentService both call this.
     *
     * @return array{transit_basic: string, stock_basic: string, allocation_ids: array<int, int>}
     */
    public function portionsBasic(DeliveryItem $item, string $itemBasicQty): array
    {
        $transitBasic = '0.000';
        $ids = [];

        foreach ($this->activeOrFinalizedAllocations($item)->all() as $allocation) {
            $transitBasic = Decimal::add($transitBasic, (string) $allocation->allocated_qty, 3);
            $ids[] = (int) $allocation->transit_allocation_id;
        }

        if (Decimal::compare($transitBasic, $itemBasicQty, 3) > 0) {
            // Defensive: must be impossible (consumption is demand-anchored).
            abort(422, 'Transit portion exceeds the delivery item quantity — data invariant violated.');
        }

        return [
            'transit_basic' => $transitBasic,
            'stock_basic' => Decimal::sub($itemBasicQty, $transitBasic, 3),
            'allocation_ids' => $ids,
        ];
    }

    /**
     * Record a POD difference as transit state (called INSIDE the POD
     * transaction, after the confirmation row exists). The (reason ×
     * disposition) matrix is authoritative; difference_reason alone never
     * decides.
     */
    public function recordPodDifference(
        EmployeeMaster $actor,
        DeliveryItem $item,
        int $confirmationId,
        string $differenceBasic,
        DifferenceReason $reason,
        DifferenceDisposition $disposition,
    ): ?TransitStock {
        if (Decimal::compare($differenceBasic, '0', 3) === 0) {
            return null;
        }

        $state = $this->deriveInitialState($reason, $disposition);

        if ($state === null) {
            return null;
        }

        $party = match ($reason) {
            DifferenceReason::EMPLOYEE_DAMAGE => LiabilityParty::EMPLOYEE,
            DifferenceReason::DISTRIBUTOR_DAMAGE => LiabilityParty::DISTRIBUTOR,
            default => LiabilityParty::NONE,
        };

        $row = $this->createRow($actor, $item, $confirmationId, $differenceBasic, $state, null, $party);

        // A difference claimed AT_SOURCE is a return claim by the holder —
        // stamp the claimant so they can never verify their own receipt.
        if ($state === TransitStatus::PENDING_SOURCE_RECEIPT) {
            $row->claimed_by_employee_id = $actor->employee_id;
            $row->save();
        }

        return $row;
    }

    /** Reason × disposition → initial state (REV 3 matrix). */
    private function deriveInitialState(DifferenceReason $reason, DifferenceDisposition $disposition): ?TransitStatus
    {
        return match ($reason) {
            DifferenceReason::EMPLOYEE_DAMAGE => $disposition === DifferenceDisposition::DAMAGED
                ? TransitStatus::DAMAGED
                : $this->invalid($reason, $disposition),
            DifferenceReason::DISTRIBUTOR_DAMAGE => $disposition === DifferenceDisposition::DAMAGED
                ? TransitStatus::DAMAGED
                : $this->invalid($reason, $disposition),
            DifferenceReason::CUSTOMER_REJECTED => match ($disposition) {
                DifferenceDisposition::WITH_EMPLOYEE => TransitStatus::REUSABLE,
                DifferenceDisposition::DAMAGED, DifferenceDisposition::AT_SOURCE => $this->invalid($reason, $disposition),
                DifferenceDisposition::UNKNOWN => TransitStatus::DISCREPANCY,
            },
            DifferenceReason::RETURNED => match ($disposition) {
                DifferenceDisposition::WITH_EMPLOYEE => TransitStatus::REUSABLE,
                DifferenceDisposition::AT_SOURCE => TransitStatus::PENDING_SOURCE_RECEIPT,
                DifferenceDisposition::DAMAGED => $this->invalid($reason, $disposition),
                DifferenceDisposition::UNKNOWN => TransitStatus::DISCREPANCY,
            },
            // Never manufacture stock from a shortage or an unclear reason.
            DifferenceReason::SHORT_DELIVERY, DifferenceReason::OTHER => TransitStatus::DISCREPANCY,
            default => null,
        };
    }

    /** Reject reason × disposition combinations that contradict physics. */
    private function invalid(DifferenceReason $reason, DifferenceDisposition $disposition): never
    {
        abort(422, "Difference reason {$reason->value} cannot be combined with disposition {$disposition->value}.");
    }

    /**
     * One traceable row. Splits always fork a dedicated child row, so a
     * free balance (root) and an ACTIVE reservation (child) never share a
     * row — releases and returns can never collide.
     */
    private function createRow(
        EmployeeMaster $actor,
        DeliveryItem $item,
        int $confirmationId,
        string $quantityBasic,
        TransitStatus $status,
        ?int $parentTransitId = null,
        LiabilityParty $party = LiabilityParty::NONE,
    ): TransitStock {
        return TransitStock::create([
            'company_id' => $item->delivery->company_id,
            'origin_shipment_no' => $this->originShipmentNo($item->delivery_no),
            'origin_delivery_no' => $item->delivery_no,
            'origin_delivery_item_no' => $item->item_no,
            'source_customer_id' => $item->delivery->source_customer_id,
            'product_id' => $item->product_id,
            'original_quantity' => $quantityBasic,
            'quantity' => $quantityBasic,
            'basic_unit' => $item->product->basic_unit,
            'holding_employee_id' => $actor->employee_id,
            'transit_status' => $status,
            'liability_party' => $party,
            'origin_confirmation_id' => $confirmationId,
            'parent_transit_id' => $parentTransitId,
        ]);
    }

    private function originShipmentNo(string $deliveryNo): string
    {
        $shipmentNo = DB::table('shipment_delivery')->where('delivery_no', $deliveryNo)->value('shipment_no');

        if ($shipmentNo === null) {
            abort(422, 'The delivery has no origin shipment for transit traceability.');
        }

        return (string) $shipmentNo;
    }

    // ---- Allocation lifecycle (ruling 3) --------------------------------

    /**
     * Reserve reusable transit quantity for a Delivery Item (FIFO). Always
     * splits a dedicated child row → release/return can never collide with
     * a reservation. Runs INSIDE the delivery transaction under the SO row
     * lock held by DeliveryService (demand anchor).
     *
     * @return array<int, int> created transit row ids
     */
    public function reserveForDeliveryItem(
        EmployeeMaster $actor,
        DeliveryItem $item,
        string $qtyBasic,
    ): array {
        if (Decimal::compare($qtyBasic, '0', 3) <= 0) {
            return [];
        }

        $sourceCustomerId = $item->delivery->source_customer_id;
        $available = $this->reusableBalance($actor->employee_id, $item->product_id, $sourceCustomerId);

        if (Decimal::compare($qtyBasic, $available, 3) > 0) {
            abort(422, 'Transit allocation of '.Decimal::trimZeros($qtyBasic).' '
                .$item->product->basic_unit.' exceeds the reusable transit balance of '
                .Decimal::trimZeros($available).' '.$item->product->basic_unit.'.');
        }

        $rows = TransitStock::reusableFor($actor->employee_id, $item->product_id, $sourceCustomerId)
            ->lockForUpdate()
            ->get();

        // Re-verify UNDER THE LOCK: a concurrent reservation may have
        // consumed the balance between the pre-check above and this lock
        // (the pre-check alone is advisory; this one is authoritative).
        $lockedAvailable = '0.000';

        foreach ($rows as $row) {
            $lockedAvailable = Decimal::add($lockedAvailable, (string) $row->quantity, 3);
        }

        if (Decimal::compare($qtyBasic, $lockedAvailable, 3) > 0) {
            abort(422, 'Transit allocation of '.Decimal::trimZeros($qtyBasic).' '
                .$item->product->basic_unit.' exceeds the reusable transit balance of '
                .Decimal::trimZeros($lockedAvailable).' '.$item->product->basic_unit.'.');
        }

        $remaining = $qtyBasic;
        $created = [];

        foreach ($rows as $row) {
            if (Decimal::compare($remaining, '0', 3) <= 0) {
                break;
            }

            if (Decimal::compare((string) $row->quantity, '0', 3) <= 0) {
                continue;
            }

            $take = Decimal::compare($remaining, (string) $row->quantity, 3) > 0
                ? (string) $row->quantity
                : $remaining;

            // Physical move: the reserved quantity leaves the source row and
            // lives ONLY on the dedicated child row until release or START.
            // This keeps the free-balance query conservative by construction.
            $row->quantity = Decimal::sub((string) $row->quantity, $take, 3);
            $row->save();

            // Dedicated child row: carries the reserved quantity.
            $child = TransitStock::create([
                'company_id' => $row->company_id,
                'origin_shipment_no' => $row->origin_shipment_no,
                'origin_delivery_no' => $row->origin_delivery_no,
                'origin_delivery_item_no' => $row->origin_delivery_item_no,
                'source_customer_id' => $row->source_customer_id,
                'product_id' => $row->product_id,
                'original_quantity' => $take,
                'quantity' => $take,
                'basic_unit' => $row->basic_unit,
                'holding_employee_id' => $row->holding_employee_id,
                'transit_status' => TransitStatus::REUSABLE,
                'liability_party' => LiabilityParty::NONE,
                'origin_confirmation_id' => $row->origin_confirmation_id,
                'parent_transit_id' => $row->transit_id,
            ]);

            TransitStockAllocation::create([
                'transit_id' => $child->transit_id,
                'delivery_no' => $item->delivery_no,
                'delivery_item_no' => $item->item_no,
                'allocated_qty' => $take,
                'allocated_by' => $actor->employee_id,
                'alloc_status' => TransitAllocationStatus::ACTIVE,
            ]);

            $created[] = (int) $child->transit_id;
            $remaining = Decimal::sub($remaining, $take, 3);
        }

        return $created;
    }

    /**
     * Release a delivery's transit reservations (delivery released before
     * START). Each child row becomes a standalone REUSABLE balance again —
     * lineage intact; the allocation row is kept as RELEASED history.
     */
    public function releaseForDelivery(string $deliveryNo): void
    {
        $allocations = TransitStockAllocation::where('delivery_no', $deliveryNo)
            ->where('alloc_status', TransitAllocationStatus::ACTIVE->value)
            ->lockForUpdate()
            ->get();

        foreach ($allocations as $allocation) {
            $transit = TransitStock::whereKey($allocation->transit_id)->lockForUpdate()->firstOrFail();

            if ($transit->transit_status !== TransitStatus::REUSABLE) {
                abort(422, 'Transit row '.$transit->transit_id.' is no longer reusable — refresh.');
            }

            // The dedicated child row simply rejoins the free pool.
            $transit->transit_status = TransitStatus::REUSABLE;
            $transit->save();

            $allocation->alloc_status = TransitAllocationStatus::RELEASED;
            $allocation->released_at = now();
            $allocation->save();
        }
    }

    /**
     * Shipment START: reservations become physically irreversible
     * (ACTIVE → FINALIZED; transit rows → REALLOCATED terminal).
     */
    public function finalizeForDelivery(string $deliveryNo): void
    {
        $allocations = TransitStockAllocation::where('delivery_no', $deliveryNo)
            ->where('alloc_status', TransitAllocationStatus::ACTIVE->value)
            ->lockForUpdate()
            ->get();

        foreach ($allocations as $allocation) {
            $transit = TransitStock::whereKey($allocation->transit_id)->lockForUpdate()->firstOrFail();

            $transit->transit_status = TransitStatus::REALLOCATED;
            $transit->resolved_to_delivery_no = $allocation->delivery_no;
            $transit->save();

            $allocation->alloc_status = TransitAllocationStatus::FINALIZED;
            $allocation->finalized_at = now();
            $allocation->save();
        }
    }

    /** Quantity reserved (ACTIVE or FINALIZED) for one delivery item, basic units. */
    public function reservedBasicQty(DeliveryItem $item): string
    {
        return $this->portionsBasic($item, $this->itemBasicQty($item))['transit_basic'];
    }

    private function itemBasicQty(DeliveryItem $item): string
    {
        return app(ProductUnitService::class)->toBasicById(
            $item->product_id,
            (string) $item->allocated_qty,
            (string) $item->delivery_unit,
        );
    }

    private function activeOrFinalizedAllocations(DeliveryItem $item)
    {
        return TransitStockAllocation::where('delivery_no', $item->delivery_no)
            ->where('delivery_item_no', $item->item_no)
            ->whereIn('alloc_status', [TransitAllocationStatus::ACTIVE->value, TransitAllocationStatus::FINALIZED->value])
            ->orderBy('transit_allocation_id')
            ->get();
    }

    /** Σ REUSABLE quantity for one holder+product+source (authoritative balance). */
    public function reusableBalance(string $employeeId, string $productId, string $sourceCustomerId): string
    {
        $sum = '0.000';

        foreach (TransitStock::reusableFor($employeeId, $productId, $sourceCustomerId)->get() as $row) {
            $sum = Decimal::add($sum, (string) $row->quantity, 3);
        }

        return $sum;
    }

    // ---- Return to source (custody + anti-self-verification) ------------

    /**
     * Holding employee initiates a return claim: REUSABLE →
     * PENDING_SOURCE_RECEIPT. Custody rule (ruling 2): only the physically
     * holding employee — admins/superadmin do NOT bypass this.
     */
    public function initiateReturn(EmployeeMaster $actor, TransitStock $transit, ?string $remarks = null): TransitStock
    {
        return DB::transaction(function () use ($actor, $transit, $remarks) {
            $locked = TransitStock::whereKey($transit->transit_id)->lockForUpdate()->firstOrFail();

            if ($locked->holding_employee_id !== $actor->employee_id) {
                abort(403, 'Only the employee physically holding this transit stock can initiate its return.');
            }

            if ($locked->transit_status !== TransitStatus::REUSABLE || Decimal::compare((string) $locked->quantity, '0', 3) <= 0) {
                abort(422, 'Only REUSABLE transit stock with an open balance can be returned.');
            }

            $locked->transit_status = TransitStatus::PENDING_SOURCE_RECEIPT;
            $locked->claimed_by_employee_id = $actor->employee_id;
            $locked->remarks = $remarks ?? $locked->remarks;
            $locked->save();

            return $locked;
        });
    }

    /**
     * Source-side verification (company admin / superadmin — NEVER the
     * claimant): VAN_RETURN movement, source unrestricted += qty, RETURNED.
     */
    public function verifySourceReceipt(AppUser $verifier, TransitStock $transit): TransitStock
    {
        return DB::transaction(function () use ($verifier, $transit) {
            $locked = TransitStock::whereKey($transit->transit_id)->lockForUpdate()->firstOrFail();

            $this->assertAdmin($verifier, $locked);

            if ($locked->transit_status !== TransitStatus::PENDING_SOURCE_RECEIPT) {
                abort(422, 'Only PENDING_SOURCE_RECEIPT transit stock can be verified as received.');
            }

            // Anti-self-verification (ruling 1): the claiming employee can
            // never verify their own receipt. Admins have no employee_id, so
            // the identity comparison is meaningful only for employee users.
            if ($verifier->employee_id !== null
                && $verifier->employee_id === $locked->claimed_by_employee_id) {
                abort(422, 'The employee who claimed the source return cannot verify their own receipt.');
            }

            $quantity = (string) $locked->quantity;

            // 1. Immutable positive movement (existing VAN_RETURN semantics).
            $movement = InventoryMovement::create([
                'company_id' => $locked->company_id,
                'customer_id' => $locked->source_customer_id,
                'product_id' => $locked->product_id,
                'movement_type' => MovementType::VAN_RETURN,
                'quantity' => $quantity,
                'basic_unit' => $locked->basic_unit,
                'reference_type' => 'TRANSIT_RETURN',
                'reference_no' => (string) $locked->transit_id,
                'movement_datetime' => now(),
                'created_by' => $verifier->employee_id,
                'remarks' => 'Transit stock returned to source (verified)',
            ]);

            // 2. Source availability rises — physically true now.
            $inventory = Inventory::where('customer_id', $locked->source_customer_id)
                ->where('product_id', $locked->product_id)
                ->lockForUpdate()
                ->first();

            if ($inventory === null) {
                $inventory = Inventory::create([
                    'customer_id' => $locked->source_customer_id,
                    'product_id' => $locked->product_id,
                    'unrestricted_qty' => '0',
                    'restricted_qty' => '0',
                    'basic_unit' => $locked->basic_unit,
                ]);
            }

            $inventory->unrestricted_qty = Decimal::add((string) $inventory->unrestricted_qty, $quantity, 3);
            $inventory->save();

            // 3. Terminal RETURNED with verifier stamp.
            $locked->transit_status = TransitStatus::RETURNED;
            $locked->verified_by_employee_id = $verifier->employee_id;
            $locked->resolved_movement_id = $movement->movement_id;
            $locked->resolved_by = $verifier->employee_id;
            $locked->resolved_at = now();
            $locked->save();

            return $locked;
        });
    }

    // ---- Discrepancy resolutions / write-off ----------------------------

    /** Found intact with the operation → REUSABLE (custody or admin). */
    public function resolveFound(AppUser $actor, TransitStock $transit): TransitStock
    {
        return $this->resolve($actor, $transit, TransitStatus::REUSABLE, LiabilityParty::NONE);
    }

    /** Confirmed damaged → DAMAGED (+ liability party). */
    public function resolveDamaged(AppUser $actor, TransitStock $transit, LiabilityParty $party): TransitStock
    {
        return $this->resolve($actor, $transit, TransitStatus::DAMAGED, $party);
    }

    /** Confirmed lost/short → LOSS (admin only). */
    public function resolveLost(AppUser $actor, TransitStock $transit): TransitStock
    {
        return $this->resolve($actor, $transit, TransitStatus::LOSS, LiabilityParty::NONE, adminOnly: true);
    }

    /** Disposal/settlement closure of DAMAGED stock (admin only). */
    public function writeOff(AppUser $actor, TransitStock $transit, ?string $remarks = null): TransitStock
    {
        return DB::transaction(function () use ($actor, $transit, $remarks) {
            $locked = TransitStock::whereKey($transit->transit_id)->lockForUpdate()->firstOrFail();

            $this->assertAdmin($actor, $locked);

            if ($locked->transit_status !== TransitStatus::DAMAGED) {
                abort(422, 'Only DAMAGED transit stock can be written off.');
            }

            $locked->transit_status = TransitStatus::WRITTEN_OFF;
            $locked->resolved_by = $actor->employee_id;
            $locked->resolved_at = now();
            $locked->remarks = $remarks ?? $locked->remarks;
            $locked->save();

            return $locked;
        });
    }

    private function resolve(
        AppUser $actor,
        TransitStock $transit,
        TransitStatus $target,
        LiabilityParty $party,
        bool $adminOnly = false,
    ): TransitStock {
        return DB::transaction(function () use ($actor, $transit, $target, $party, $adminOnly) {
            $locked = TransitStock::whereKey($transit->transit_id)->lockForUpdate()->firstOrFail();

            $adminOnly
                ? $this->assertAdmin($actor, $locked)
                : $this->assertOperational($actor, $locked);

            if ($locked->transit_status !== TransitStatus::DISCREPANCY) {
                abort(422, 'Only DISCREPANCY transit stock can be resolved.');
            }

            $locked->transit_status = $target;
            $locked->liability_party = $party;
            $locked->resolved_by = $actor->employee_id;
            $locked->resolved_at = now();
            $locked->save();

            return $locked;
        });
    }

    /** Found-at-source discrepancy resolution = the same verified receipt flow. */
    public function resolveFoundAtSource(AppUser $verifier, TransitStock $transit): TransitStock
    {
        $locked = $transit->fresh();

        if ($locked === null || $locked->transit_status !== TransitStatus::DISCREPANCY) {
            abort(422, 'Only DISCREPANCY transit stock can be resolved as found at source.');
        }

        $locked->transit_status = TransitStatus::PENDING_SOURCE_RECEIPT;
        $locked->save();

        return $this->verifySourceReceipt($verifier, $locked);
    }

    // ---- Authorization helpers (custody-aware, company-scoped) ----------

    private function assertOperational(AppUser $actor, TransitStock $transit): void
    {
        if ($actor->isCompanyAdmin() || $actor->isSuperadmin()) {
            $this->assertCompanyScope($actor, $transit);

            return;
        }

        if (! $actor->isSalesEmployee()) {
            abort(403, 'Not allowed to operate on transit stock.');
        }

        // Custody: the holding employee acts on THEIR rows only.
        if ($transit->holding_employee_id !== $actor->employee_id) {
            abort(403, 'Only the employee physically holding this transit stock can act on it.');
        }

        $this->assertCompanyScope($actor, $transit);
    }

    private function assertAdmin(AppUser $actor, TransitStock $transit): void
    {
        if (! $actor->isCompanyAdmin() && ! $actor->isSuperadmin()) {
            abort(403, 'Only a company admin can perform this action.');
        }

        $this->assertCompanyScope($actor, $transit);
    }

    private function assertCompanyScope(AppUser $actor, TransitStock $transit): void
    {
        if ($actor->isSuperadmin()) {
            return; // cross-company authority
        }

        if ($actor->company_id !== $transit->company_id) {
            abort(403, 'This transit record belongs to another company.');
        }
    }
}
