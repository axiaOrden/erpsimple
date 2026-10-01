<?php

namespace App\Services;

use App\Enums\DeliveryStatus;
use App\Models\DealCondition;
use App\Models\DealQualifier;
use App\Models\DeliveryItem;
use App\Models\ProductMaster;
use App\Models\SalesOrderItem;
use Illuminate\Support\Collection;

/**
 * DEAL/free entitlement — the dependency between a free (DEAL) line and the
 * paid parent line that earned it.
 *
 * A free line is DEPENDENT fulfilment, never independent demand:
 *
 *   eligible_free =
 *       floor(parent_eligible_qty / qualifier_per_multiple_qty) × reward_qty
 *
 * where
 *   - parent_eligible_qty is the parent paid line's CUMULATIVE authoritative
 *     fulfilled quantity (allocated, non-released deliveries, converted to the
 *     parent product's basic unit) plus whatever the caller is allocating for
 *     that parent in the SAME operation. Cumulative — so splitting a deal
 *     across several Deliveries can never mint duplicate free entitlement;
 *   - qualifier_per_multiple_qty is the deal's `for_each_qty` when configured,
 *     otherwise its qualifier minimum, converted through the EXISTING unit
 *     conversion for the qualifier product (never hard-coded PCS/CTN);
 *   - reward_qty is the deal's configured reward for the free product,
 *     converted to that product's basic unit.
 *
 * The entitlement is computed per (parent line, deal, reward product) and the
 * already-allocated free quantity of those lines is subtracted, so free
 * quantity can never move ahead of its parent's deal entitlement.
 *
 * This service only DERIVES quantities; it never writes stock, allocations,
 * shipments, POD or money. DeliveryService enforces it under the order lock,
 * SalesOrderService uses it to close dependent demand when the parent's
 * remaining demand is terminally rejected.
 */
class DealEntitlementService
{
    public function __construct(private readonly ProductUnitService $units) {}

    /** A DEAL line that hangs off a paid parent line (dependent fulfilment). */
    public function isDependentFreeLine(SalesOrderItem $item): bool
    {
        return (bool) $item->is_free_item && $item->parent_item_no !== null;
    }

    /** The paid parent line that earned this free line, when resolvable. */
    public function parentOf(SalesOrderItem $item): ?SalesOrderItem
    {
        if ($item->parent_item_no === null) {
            return null;
        }

        return SalesOrderItem::where('sales_order_no', $item->sales_order_no)
            ->where('item_no', $item->parent_item_no)
            ->first();
    }

    /**
     * Cumulative allocated quantity of an SO line in BASIC units — DRAFT
     * (released) deliveries hold no stock and therefore earn nothing.
     */
    public function allocatedBasic(SalesOrderItem $item): string
    {
        $sum = '0.000';

        $rows = DeliveryItem::where('sales_order_no', $item->sales_order_no)
            ->where('sales_order_item_no', $item->item_no)
            ->whereHas('delivery', fn ($q) => $q->where('delivery_status', '!=', DeliveryStatus::DRAFT->value))
            ->get();

        foreach ($rows as $row) {
            try {
                $sum = Decimal::add($sum, $this->units->toBasicById(
                    (string) $item->product_id, (string) $row->allocated_qty, (string) $row->delivery_unit,
                ), 3);
            } catch (\InvalidArgumentException) {
                continue; // unmappable unit — never guessed into a demand figure
            }
        }

        return $sum;
    }

    /**
     * Full dependency context for a free line.
     *
     * @param  string  $additionalParentBasic  parent quantity being allocated in the SAME operation (basic units)
     * @return array{
     *     parent: SalesOrderItem,
     *     parent_item_no: int,
     *     parent_ordered_basic: string,
     *     parent_allocated_basic: string,
     *     parent_eligible_basic: string,
     *     parent_unit: string,
     *     parent_ordered_display: ?string,
     *     parent_allocated_display: ?string,
     *     multiple_basic: string,
     *     multiple_unit: string,
     *     reward_per_multiple_basic: string,
     *     multiples: int,
     *     entitlement_basic: string,
     *     entitlement_display: ?string,
     *     allocated_basic: string,
     *     allocated_display: ?string,
     *     remaining_eligible_basic: string,
     *     remaining_eligible_display: ?string,
     *     line_remaining_demand_basic: string,
     *     effective_remaining_basic: string,
     *     unit: string
     * }|null
     */
    public function context(SalesOrderItem $item, string $additionalParentBasic = '0.000'): ?array
    {
        if (! $this->isDependentFreeLine($item)) {
            return null;
        }

        $parent = $this->parentOf($item);

        if ($parent === null) {
            return null;
        }

        $deal = $item->deal_no !== null
            ? DealCondition::with(['qualifiers', 'rewards'])->find($item->deal_no)
            : null;

        // No deal master data left: the free line has NO independent
        // entitlement (fail closed — never grant free stock without a source).
        if ($deal === null) {
            return $this->emptyContext($item, $parent);
        }

        $multiple = $this->multipleBasic($deal, $parent);
        $rewardPerMultiple = $this->rewardPerMultipleBasic($deal, $item);

        $parentOrdered = $this->units->toBasicById((string) $parent->product_id, (string) $parent->order_qty, (string) $parent->order_unit);
        $parentAllocated = $this->allocatedBasic($parent);
        $parentEligible = Decimal::add($parentAllocated, $additionalParentBasic, 3);

        $multiples = Decimal::compare($multiple, '0', 6) > 0
            ? (int) floor(((float) $parentEligible) / ((float) $multiple))
            : 0;

        $entitlement = Decimal::mul((string) $multiples, $rewardPerMultiple, 3);
        $allocatedFree = $this->allocatedFreeBasic($item);
        $remainingEligible = Decimal::compare($entitlement, $allocatedFree, 3) > 0
            ? Decimal::sub($entitlement, $allocatedFree, 3)
            : '0.000';

        $lineOrdered = $this->units->toBasicById((string) $item->product_id, (string) $item->order_qty, (string) $item->order_unit);
        $lineAllocated = $this->allocatedBasic($item);
        $lineRemaining = Decimal::compare($lineOrdered, $lineAllocated, 3) > 0
            ? Decimal::sub($lineOrdered, $lineAllocated, 3)
            : '0.000';

        // The quantity actually allocatable for THIS line: the smaller of its
        // own unmet demand and the deal entitlement still unclaimed.
        $effective = Decimal::compare($lineRemaining, $remainingEligible, 3) < 0 ? $lineRemaining : $remainingEligible;

        return [
            'parent' => $parent,
            'parent_item_no' => (int) $parent->item_no,
            'parent_ordered_basic' => $parentOrdered,
            'parent_allocated_basic' => $parentAllocated,
            'parent_eligible_basic' => $parentEligible,
            'parent_unit' => (string) $parent->order_unit,
            'parent_ordered_display' => $this->display((string) $parent->product_id, $parentOrdered, (string) $parent->order_unit),
            'parent_allocated_display' => $this->display((string) $parent->product_id, $parentAllocated, (string) $parent->order_unit),
            'multiple_basic' => $multiple,
            'multiple_unit' => $this->multipleUnit($deal, $parent),
            'reward_per_multiple_basic' => $rewardPerMultiple,
            'multiples' => $multiples,
            'entitlement_basic' => $entitlement,
            'entitlement_display' => $this->display((string) $item->product_id, $entitlement, (string) $item->order_unit),
            'allocated_basic' => $allocatedFree,
            'allocated_display' => $this->display((string) $item->product_id, $allocatedFree, (string) $item->order_unit),
            'remaining_eligible_basic' => $remainingEligible,
            'remaining_eligible_display' => $this->display((string) $item->product_id, $remainingEligible, (string) $item->order_unit),
            'line_allocated_basic' => $lineAllocated,
            'line_remaining_demand_basic' => $lineRemaining,
            'effective_remaining_basic' => $effective,
            'unit' => (string) $item->order_unit,
        ];
    }

    /**
     * Dependent free demand that becomes NON-FULFILLABLE when the parent paid
     * line's remaining demand is terminally rejected. A line is closable when
     * it still has unmet demand of its own AND either
     *   (a) the parent's committed quantity no longer earns it (the deal
     *       entitlement is exhausted — item 4's "excess remaining free
     *       automatically closes"), or
     *   (b) nothing of it was ever physically committed (parent terminal ⇒
     *       uncommitted dependent demand is dead — item 1/2).
     *
     * A free line that already committed quantity AND still holds earned
     * entitlement is left alone: it is deliverable, earned by the parent
     * quantity that really was fulfilled.
     *
     * @return Collection<int, SalesOrderItem>
     */
    public function closableFreeLines(SalesOrderItem $parent): Collection
    {
        return SalesOrderItem::where('sales_order_no', $parent->sales_order_no)
            ->where('parent_item_no', $parent->item_no)
            ->where('is_free_item', true)
            ->orderBy('item_no')
            ->get()
            ->reject(fn (SalesOrderItem $free) => $free->isRejected())
            ->filter(function (SalesOrderItem $free) {
                $context = $this->context($free);

                if ($context === null) {
                    return false; // unresolvable dependency: leave untouched
                }

                if (Decimal::compare($context['line_remaining_demand_basic'], '0', 3) <= 0) {
                    return false; // nothing left to close
                }

                return Decimal::compare($context['remaining_eligible_basic'], '0', 3) <= 0
                    || Decimal::compare($context['line_allocated_basic'], '0', 3) === 0;
            })
            ->values();
    }

    /**
     * Human-readable dependency line for a free line, e.g.
     *   "Deal entitlement · parent fulfilled/allocated 6 / 12 PCS ·
     *    free currently eligible 0 PCS"
     *
     * @param  array<string, mixed>  $context
     */
    public function note(array $context): string
    {
        $parentAllocated = $context['parent_allocated_display'] ?? $context['parent_allocated_basic'];
        $parentOrdered = $context['parent_ordered_display'] ?? $context['parent_ordered_basic'];
        $eligible = $context['remaining_eligible_display'] ?? $context['remaining_eligible_basic'];

        return 'Deal entitlement · parent fulfilled/allocated '
            .Decimal::trimZeros((string) $parentAllocated).' / '.Decimal::trimZeros((string) $parentOrdered)
            .' '.$context['parent_unit']
            .' · free currently eligible '.Decimal::trimZeros((string) $eligible).' '.$context['unit'];
    }

    /**
     * The "one reward per N qualifier units" basis, in the qualifier
     * product's basic units: `for_each_qty` when configured, else the
     * qualifier minimum.
     */
    private function multipleBasic(DealCondition $deal, SalesOrderItem $parent): string
    {
        $qualifier = $this->qualifierFor($deal, $parent);

        if ($qualifier === null) {
            return '0.000';
        }

        $reward = $deal->rewards->firstWhere('product_id', $parent->product_id);

        if ($reward !== null && $reward->for_each_qty !== null && Decimal::compare((string) $reward->for_each_qty, '0', 3) > 0) {
            try {
                return $this->units->toBasicById(
                    (string) $qualifier->product_id,
                    (string) $reward->for_each_qty,
                    (string) ($reward->for_each_unit ?? $qualifier->qualifier_unit),
                );
            } catch (\InvalidArgumentException) {
                // fall through to the qualifier minimum
            }
        }

        try {
            return $this->units->toBasicById(
                (string) $qualifier->product_id,
                (string) $qualifier->minimum_qty,
                (string) $qualifier->qualifier_unit,
            );
        } catch (\InvalidArgumentException) {
            return '0.000';
        }
    }

    private function multipleUnit(DealCondition $deal, SalesOrderItem $parent): string
    {
        $qualifier = $this->qualifierFor($deal, $parent);

        return $qualifier !== null ? (string) $qualifier->qualifier_unit : (string) $parent->order_unit;
    }

    private function qualifierFor(DealCondition $deal, SalesOrderItem $parent): ?DealQualifier
    {
        return $deal->qualifiers->firstWhere('product_id', $parent->product_id)
            ?? $deal->qualifiers->first();
    }

    /** Deal reward for the free line's product, in that product's basic unit. */
    private function rewardPerMultipleBasic(DealCondition $deal, SalesOrderItem $item): string
    {
        $sum = '0.000';

        foreach ($deal->rewards->where('product_id', $item->product_id) as $reward) {
            try {
                $sum = Decimal::add($sum, $this->units->toBasicById(
                    (string) $item->product_id, (string) $reward->reward_qty, (string) $reward->reward_unit,
                ), 3);
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        return $sum;
    }

    /**
     * Free quantity already allocated against the same parent/deal/reward
     * product — the entitlement is shared by those lines, so a second
     * Delivery can never claim it twice.
     */
    private function allocatedFreeBasic(SalesOrderItem $item): string
    {
        $sum = '0.000';

        $siblings = SalesOrderItem::where('sales_order_no', $item->sales_order_no)
            ->where('parent_item_no', $item->parent_item_no)
            ->where('is_free_item', true)
            ->where('product_id', $item->product_id)
            ->when($item->deal_no !== null, fn ($q) => $q->where('deal_no', $item->deal_no))
            ->get();

        foreach ($siblings as $sibling) {
            $sum = Decimal::add($sum, $this->allocatedBasic($sibling), 3);
        }

        return $sum;
    }

    /** Basic quantity expressed in a business unit (null when unmappable). */
    private function display(string $productId, string $basicQty, string $unit): ?string
    {
        $product = ProductMaster::find($productId);

        if ($product === null) {
            return null;
        }

        try {
            return $this->units->fromBasic($product, $basicQty, $unit);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** Fail-closed context: no deal master data ⇒ no entitlement at all. */
    private function emptyContext(SalesOrderItem $item, SalesOrderItem $parent): array
    {
        $parentOrdered = $this->units->toBasicById((string) $parent->product_id, (string) $parent->order_qty, (string) $parent->order_unit);
        $parentAllocated = $this->allocatedBasic($parent);
        $lineOrdered = $this->units->toBasicById((string) $item->product_id, (string) $item->order_qty, (string) $item->order_unit);
        $lineAllocated = $this->allocatedBasic($item);
        $lineRemaining = Decimal::compare($lineOrdered, $lineAllocated, 3) > 0
            ? Decimal::sub($lineOrdered, $lineAllocated, 3)
            : '0.000';

        return [
            'parent' => $parent,
            'parent_item_no' => (int) $parent->item_no,
            'parent_ordered_basic' => $parentOrdered,
            'parent_allocated_basic' => $parentAllocated,
            'parent_eligible_basic' => $parentAllocated,
            'parent_unit' => (string) $parent->order_unit,
            'parent_ordered_display' => $this->display((string) $parent->product_id, $parentOrdered, (string) $parent->order_unit),
            'parent_allocated_display' => $this->display((string) $parent->product_id, $parentAllocated, (string) $parent->order_unit),
            'multiple_basic' => '0.000',
            'multiple_unit' => (string) $parent->order_unit,
            'reward_per_multiple_basic' => '0.000',
            'multiples' => 0,
            'entitlement_basic' => '0.000',
            'entitlement_display' => '0.000',
            'allocated_basic' => '0.000',
            'allocated_display' => '0.000',
            'remaining_eligible_basic' => '0.000',
            'remaining_eligible_display' => '0.000',
            'line_allocated_basic' => $lineAllocated,
            'line_remaining_demand_basic' => $lineRemaining,
            'effective_remaining_basic' => '0.000',
            'unit' => (string) $item->order_unit,
        ];
    }
}
