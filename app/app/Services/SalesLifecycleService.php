<?php

namespace App\Services;

use App\Enums\ConfirmationStatus;
use App\Enums\DeliveryStatus;
use App\Enums\InvoicePaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Delivery;
use App\Models\DeliveryConfirmation;
use App\Models\DeliveryItem;
use App\Models\EmployeeMaster;
use App\Models\EmployeeProduct;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ProductMaster;
use App\Models\ProductUnitConversion;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\Shipment;
use App\Models\ShipmentDelivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * READ-ONLY field-sales lifecycle analytics. Nothing here writes documents,
 * allocations or money: it only re-reads authoritative states produced by
 * SalesOrderService / DeliveryService / ShipmentService / PodService /
 * InvoiceService and reports where the physical quantity currently sits.
 *
 * Lifecycle buckets (never double counted — the split is derived from the
 * SAME POD/outcome rows that InvoiceService bills from):
 *
 *   OPEN      ordered − allocated            (unallocated, still fulfillable)
 *   ALLOCATED  allocated delivery rows with no terminal POD outcome
 *   DELIVERED  POD-accepted basic quantity
 *              → DELIVERED_PAID  when its invoice is PAID
 *              → DELIVERED_UNPAID otherwise (no invoice yet, unpaid or
 *                partially paid — amounts, not fabricated quantities, carry
 *                the partial-payment detail)
 *   rejected   demand killed by item rejection / POD refusal (out of the
 *              active lifecycle, shown in drill-down only)
 *
 * Because rejection is recorded per SO ITEM (not per quantity), a rejected
 * item's unfulfilled remainder leaves the ORANGE bucket entirely: rejected
 * demand can no longer be processed.
 *
 * HOME GROUPING (per ORDER QUANTITY UNIT): the field dashboard aggregates
 * today's demand by the unit the order was captured in (CTN section, PCS
 * section, …) instead of per product, and does NOT convert everything to one
 * display unit — products whose practical selling unit differs are never mixed
 * in one pipeline. Buckets stay mutually exclusive inside a unit, and their
 * sum must account for the unit's demand exactly once (conservation).
 */
class SalesLifecycleService
{
    /** Field display unit for lifecycle quantities. */
    public const DISPLAY_UNIT = 'CTN';

    private const ALLOCATED_STATUSES = [
        DeliveryStatus::ALLOCATED->value,
        DeliveryStatus::SHIPPED->value,
        DeliveryStatus::PARTIALLY_DELIVERED->value,
        DeliveryStatus::DELIVERED->value,
    ];

    private const SHIPPED_STATUSES = [
        DeliveryStatus::SHIPPED->value,
        DeliveryStatus::PARTIALLY_DELIVERED->value,
        DeliveryStatus::DELIVERED->value,
    ];

    /** product_id → CTN factor (null = no legitimate conversion exists). */
    private array $factorCache = [];

    /** @var array<string, string|null> product_id → basic unit */
    private array $basicUnitCache = [];

    public function __construct(private readonly ProductUnitService $units) {}

    /**
     * Today's SKU lifecycle for one sales employee.
     *
     * Demand basis: orders CONFIRMED on the selected date (a DRAFT is not
     * demand yet). Every quantity is therefore today's selling, positioned in
     * its CURRENT lifecycle state — the same day's demand cannot be counted
     * twice because each basic-unit quantity leaves exactly one bucket.
     *
     * @return array{
     *     date: Carbon, orders: Collection<int, SalesOrder>, rows: array<int, array<string, mixed>>,
     *     totals: array<string, string|null>, unconvertible: array<int, string>, out_of_scope: array<int, string>
     * }
     */
    public function todayLifecycle(EmployeeMaster $employee, ?Carbon $date = null): array
    {
        $day = $date !== null ? $date->copy()->startOfDay() : Carbon::today();

        $orders = SalesOrder::query()
            ->where('company_id', $employee->company_id)
            ->where('sales_employee_id', $employee->employee_id)
            ->where('order_status', '!=', OrderStatus::DRAFT->value)
            ->whereDate('confirmed_at', $day)
            ->with('soldToCustomer')
            ->orderBy('sales_order_no')
            ->get();

        $analyses = $this->classifyOrders($orders);
        $scoped = EmployeeProduct::where('employee_id', $employee->employee_id)->pluck('product_id');

        $rows = [];
        $outOfScope = [];

        foreach ($orders as $order) {
            $analysis = $analyses[$order->sales_order_no] ?? null;

            if ($analysis === null) {
                continue;
            }

            foreach ($analysis['items'] as $item) {
                $productId = $item['product_id'];

                if ($scoped->isNotEmpty() && ! $scoped->contains($productId)) {
                    $outOfScope[$productId] = true;

                    continue;
                }

                $row = $rows[$productId] ?? $this->emptyRow($productId);
                $row['orders'][$order->sales_order_no] = true;

                $row['ordered_basic'] = Decimal::add($row['ordered_basic'], $item['ordered_basic'], 3);
                $row['open_basic'] = Decimal::add($row['open_basic'], $item['remaining_basic'], 3);
                $row['allocated_basic'] = Decimal::add($row['allocated_basic'], $item['awaiting_pod_basic'], 3);
                $row['accepted_basic'] = Decimal::add($row['accepted_basic'], $item['accepted_basic'], 3);
                $row['rejected_basic'] = Decimal::add(
                    $row['rejected_basic'],
                    Decimal::add($item['rejected_demand_basic'], $item['pod_rejected_basic'], 3),
                    3,
                );

                $rows[$productId] = $row;
            }
        }

        // Settlement split: only invoices that are genuinely PAID contribute
        // GREEN. A partially settled multi-SKU invoice cannot be attributed to
        // individual SKUs deterministically, so its quantity stays in the
        // DELIVERED_UNPAID bucket and the amounts (settled vs outstanding) are
        // reported instead of a fabricated paid quantity.
        $paidByProduct = [];
        $paidByItem = []; // "sales_order_no|item_no" ⇒ paid basic qty
        $invoiceNotes = [];

        $invoices = Invoice::whereIn('sales_order_no', $orders->pluck('sales_order_no')->all())->get();

        foreach ($invoices as $invoice) {
            $isPaid = $invoice->payment_status === InvoicePaymentStatus::PAID;

            if (! $isPaid && $invoice->payment_status === InvoicePaymentStatus::PARTIALLY_PAID) {
                $invoiceNotes[] = [
                    'invoice_no' => $invoice->invoice_no,
                    'settled' => (string) $invoice->settled_amount,
                    'credit' => (string) $invoice->credit_amount,
                    'outstanding' => $invoice->outstandingAmount(),
                ];
            }

            if (! $isPaid) {
                continue;
            }

            foreach (InvoiceItem::where('invoice_no', $invoice->invoice_no)->get() as $line) {
                $basic = $this->safeBasic((string) $line->product_id, (string) $line->quantity, (string) $line->invoice_unit);

                if ($basic === null) {
                    continue;
                }

                $paidByProduct[$line->product_id] = Decimal::add(
                    $paidByProduct[$line->product_id] ?? '0.000', $basic, 3,
                );

                // Item-level attribution (the invoice line traces back to the
                // SO line), used by the per-order-unit grouping. Free lines are
                // invoiced at zero value but still carry their accepted qty.
                $key = $line->sales_order_no.'|'.$line->sales_order_item_no;
                $paidByItem[$key] = Decimal::add($paidByItem[$key] ?? '0.000', $basic, 3);
            }
        }

        $unconvertible = [];

        foreach (array_keys($rows) as $productId) {
            $row = $rows[$productId];
            $factor = $this->displayFactor($productId);

            $row['display_unit'] = $factor !== null ? self::DISPLAY_UNIT : null;

            $paid = $paidByProduct[$productId] ?? '0.000';

            // Delivered-unpaid = accepted − (settled/paid). Floored per
            // product; a paid quantity can never exceed what was accepted.
            $unpaid = Decimal::compare($row['accepted_basic'], $paid, 3) > 0
                ? Decimal::sub($row['accepted_basic'], $paid, 3)
                : '0.000';

            $row['delivered_paid_basic'] = $paid;
            $row['delivered_unpaid_basic'] = $unpaid;

            foreach (['ordered', 'open', 'allocated', 'delivered_unpaid', 'delivered_paid', 'accepted', 'rejected'] as $bucket) {
                $row[$bucket.'_display'] = $factor !== null
                    ? $this->displayQty($row[$bucket.'_basic'], $factor)
                    : null;
            }

            if ($factor === null) {
                $unconvertible[] = $productId;
            }

            $rows[$productId] = $row;
        }

        $products = ProductMaster::whereIn('product_id', array_keys($rows))->get()->keyBy('product_id');

        foreach ($rows as $productId => $row) {
            $product = $products[$productId] ?? null;

            $row['product'] = $product?->product_description ?? $productId;
            $row['basic_unit'] = $product?->basic_unit ?? '';
            $row['is_free_item'] = false;
            $row['note'] = $row['display_unit'] === null
                ? 'No '.self::DISPLAY_UNIT.' conversion defined — shown in '.$row['basic_unit'].' at basic-unit precision.'
                : null;

            $rows[$productId] = $row;
        }

        uasort($rows, fn ($a, $b) => strcmp($a['product'], $b['product']));

        $totals = ['ordered' => null, 'open' => null, 'allocated' => null, 'delivered_unpaid' => null, 'delivered_paid' => null, 'rejected' => null, 'lines' => null];
        $totals['lines'] = count($rows);

        foreach (['ordered', 'open', 'allocated', 'delivered_unpaid', 'delivered_paid', 'rejected'] as $bucket) {
            $sum = null;

            foreach ($rows as $row) {
                if ($row[$bucket.'_display'] === null) {
                    continue;
                }

                $sum = Decimal::add($sum ?? '0.000', $row[$bucket.'_display'], 3);
            }

            $totals[$bucket] = $sum;
        }

        return [
            'date' => $day,
            'display_unit' => self::DISPLAY_UNIT,
            'orders' => $orders,
            'rows' => $rows,
            'unit_groups' => $this->unitGroups($orders, $analyses, $scoped, $paidByItem, $rows),
            'totals' => $totals,
            'unconvertible' => $unconvertible,
            'out_of_scope' => array_keys($outOfScope),
            'partial_invoices' => $invoiceNotes,
        ];
    }

    /**
     * Today's lifecycle grouped by the ORDER QUANTITY UNIT (item 11).
     *
     * Every SO line contributes its own buckets, converted from the
     * authoritative BASIC quantity into the unit the order line was captured in
     * — never into one global display unit, so CTN demand and PCS demand are
     * never mixed. Buckets inside a unit are mutually exclusive:
     *
     *   open (not allocated) + allocated + delivered-unpaid + delivered-paid
     *   + rejected (terminal, not delivered) = the unit's demand, exactly once.
     *
     * @param  Collection<int, SalesOrder>  $orders
     * @param  array<string, array<string, mixed>>  $analyses
     * @param  Collection<int, string>  $scoped  employee product scope (empty = all)
     * @param  array<string, string>  $paidByItem  "sales_order_no|item_no" ⇒ paid basic qty
     * @param  array<int, array<string, mixed>>  $rows  per-product rows (product scope resolution)
     * @return array<int, array<string, mixed>>
     */
    private function unitGroups(Collection $orders, array $analyses, Collection $scoped, array $paidByItem, array $rows): array
    {
        $groups = [];

        foreach ($orders as $order) {
            $analysis = $analyses[$order->sales_order_no] ?? null;

            if ($analysis === null) {
                continue;
            }

            foreach ($analysis['items'] as $item) {
                $productId = (string) $item['product_id'];

                if ($scoped->isNotEmpty() && ! $scoped->contains($productId)) {
                    continue; // same scope rule as the per-product rows
                }

                $accepted = (string) $item['accepted_basic'];
                $paid = $paidByItem[$order->sales_order_no.'|'.$item['item_no']] ?? '0.000';

                // A paid quantity can never exceed what was accepted, and is
                // never invented for a partially settled invoice.
                if (Decimal::compare($paid, $accepted, 3) > 0) {
                    $paid = $accepted;
                }

                $buckets = [
                    'open' => (string) $item['remaining_basic'],
                    'allocated' => (string) $item['awaiting_pod_basic'],
                    'delivered_unpaid' => Decimal::sub($accepted, $paid, 3),
                    'delivered_paid' => $paid,
                    'rejected' => Decimal::add(
                        (string) $item['rejected_demand_basic'], (string) $item['pod_rejected_basic'], 3,
                    ),
                    'ordered' => (string) $item['ordered_basic'],
                ];

                $unit = (string) ($item['order_unit'] ?? '');

                if ($unit === '') {
                    continue;
                }

                try {
                    $values = array_map(
                        fn (string $basic) => $this->units->fromBasicById($productId, $basic, $unit),
                        $buckets,
                    );
                    $displayUnit = $unit;
                } catch (\InvalidArgumentException) {
                    // Master-data drift: fall back to the product's BASIC unit
                    // for this line rather than converting by guesswork.
                    $values = $buckets;
                    $displayUnit = $rows[$productId]['basic_unit'] ?? $unit;
                }

                $group = $groups[$displayUnit] ?? $this->emptyUnitGroup($displayUnit);

                foreach ($buckets as $bucket => $_) {
                    $group[$bucket] = Decimal::add($group[$bucket], (string) $values[$bucket], 3);
                }

                $group['lines']++;
                $group['free_lines'] += (bool) $item['is_free_item'] ? 1 : 0;
                $group['orders'][$order->sales_order_no] = true;
                $group['products'][$productId] = $rows[$productId]['product'] ?? $productId;

                $groups[$displayUnit] = $group;
            }
        }

        foreach ($groups as $unit => $group) {
            // Conservation check, exposed to the UI/tests: the mutually
            // exclusive buckets must account for the unit's demand exactly
            // once, with no double counting.
            $sum = Decimal::add($group['open'], $group['allocated'], 3);
            $sum = Decimal::add($sum, $group['delivered_unpaid'], 3);
            $sum = Decimal::add($sum, $group['delivered_paid'], 3);
            $sum = Decimal::add($sum, $group['rejected'], 3);

            $groups[$unit]['total'] = $group['ordered'];
            $groups[$unit]['bucket_sum'] = $sum;
            $groups[$unit]['balanced'] = Decimal::compare($sum, $group['ordered'], 3) === 0;
            $groups[$unit]['order_count'] = count($group['orders']);
        }

        return array_values($groups);
    }

    /** @return array<string, mixed> */
    private function emptyUnitGroup(string $unit): array
    {
        return [
            'unit' => $unit,
            'open' => '0.000',
            'allocated' => '0.000',
            'delivered_unpaid' => '0.000',
            'delivered_paid' => '0.000',
            'rejected' => '0.000',
            'ordered' => '0.000',
            'lines' => 0,
            'free_lines' => 0,
            'orders' => [],
            'products' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function emptyRow(string $productId): array
    {
        return [
            'product_id' => $productId,
            'ordered_basic' => '0.000',
            'open_basic' => '0.000',
            'allocated_basic' => '0.000',
            'accepted_basic' => '0.000',
            'rejected_basic' => '0.000',
            'delivered_paid_basic' => '0.000',
            'delivered_unpaid_basic' => '0.000',
            'orders' => [],
        ];
    }

    /**
     * Classify orders as ONGOING (operational work remains) or COMPLETED.
     *
     * No database status is invented: the category is DERIVED from the
     * authoritative states (demand, allocation, POD outcomes, invoice,
     * settlement) in a bounded number of bulk queries.
     *
     * @param  Collection<int, SalesOrder>  $orders
     * @return array<string, array<string, mixed>> keyed by sales_order_no
     */
    public function classifyOrders(Collection $orders): array
    {
        if ($orders->isEmpty()) {
            return [];
        }

        $orderNos = $orders->pluck('sales_order_no')->all();

        $items = SalesOrderItem::whereIn('sales_order_no', $orderNos)
            ->orderBy('item_no')
            ->get()
            ->groupBy('sales_order_no');

        $deliveryItems = DeliveryItem::whereIn('sales_order_no', $orderNos)->get()->groupBy('sales_order_no');

        $deliveries = Delivery::whereIn('sales_order_no', $orderNos)->get()->keyBy('delivery_no');
        $deliveryNos = $deliveries->keys()->all();

        $outcomes = $deliveryNos === []
            ? collect()
            : DeliveryConfirmation::whereIn('delivery_no', $deliveryNos)->get()
                ->keyBy(fn (DeliveryConfirmation $c) => $c->delivery_no.'|'.$c->delivery_item_no);

        $links = $deliveryNos === []
            ? collect()
            : ShipmentDelivery::whereIn('delivery_no', $deliveryNos)->get()->keyBy('delivery_no');

        $shipments = $links->isEmpty()
            ? collect()
            : Shipment::whereIn('shipment_no', $links->pluck('shipment_no')->unique()->all())->get()->keyBy('shipment_no');

        $invoices = Invoice::whereIn('sales_order_no', $orderNos)->get()->keyBy('sales_order_no');

        $result = [];

        foreach ($orders as $order) {
            $result[$order->sales_order_no] = $this->analyse(
                $order,
                $items->get($order->sales_order_no) ?? collect(),
                $deliveryItems->get($order->sales_order_no) ?? collect(),
                $deliveries,
                $outcomes,
                $links,
                $shipments,
                $invoices->get($order->sales_order_no),
            );
        }

        return $result;
    }

    /** Single-order analysis (detail screens). */
    public function orderAnalysis(SalesOrder $order): array
    {
        return $this->classifyOrders(collect([$order]))[$order->sales_order_no];
    }

    /**
     * OPERATIONAL unfinished work of one analysed order, derived from the SAME
     * metrics the lifecycle buckets use. Deliberately excludes money: financial
     * blocking is the Phase 8 NET-exposure rule (`max(0, outstanding −
     * available credit)`), so an outstanding invoice covered by customer credit
     * must not read as unfinished work here.
     *
     * Unfinished ⇔ one of:
     *   - open demand (confirmed, not yet allocated)
     *   - a released allocation awaiting re-allocation
     *   - an allocated delivery not yet dispatched (no Shipment START)
     *   - dispatched quantity still awaiting its POD outcome
     *   - POD-accepted quantity whose invoice has not been generated yet
     *
     * "Allocated, not dispatched" and "dispatched, awaiting POD" come from
     * separate metrics: the lifecycle's awaiting_pod_basic also covers mere
     * allocations (the YELLOW processing bucket), which must not be reported
     * as dispatched stock.
     *
     * A fully shipped cycle whose every outcome is recorded — including one
     * whose accepted quantity is zero because POD rejected it — is NOT
     * unfinished: the difference already lives in the implemented Phase 9
     * transit/custody workflow, which is parallel accountability for the
     * delivered employee, not another step of the commercial cycle.
     *
     * @param  array<string, mixed>  $analysis  one `orderAnalysis()` result
     * @return list<string> human-readable operational blockers
     */
    public function unfinishedOperationalReasons(array $analysis): array
    {
        $metrics = $analysis['metrics'] ?? [];
        $reasons = [];

        if (Decimal::compare((string) ($metrics['remaining_fulfillable_basic'] ?? '0'), '0', 3) > 0) {
            $reasons[] = 'open demand not yet allocated';
        }

        if ((int) ($metrics['draft_allocations'] ?? 0) > 0) {
            $reasons[] = 'released allocation awaiting re-allocation';
        }

        if ((int) ($metrics['allocated_not_shipped'] ?? 0) > 0) {
            $reasons[] = 'allocated delivery not yet dispatched';
        }

        if (Decimal::compare((string) ($metrics['dispatched_awaiting_pod_basic'] ?? '0'), '0', 3) > 0) {
            $reasons[] = 'dispatched quantity awaiting POD';
        }

        if (Decimal::compare((string) ($metrics['accepted_basic'] ?? '0'), '0', 3) > 0
            && ($analysis['invoice'] ?? null) === null) {
            $reasons[] = 'POD-accepted quantity not yet invoiced';
        }

        return $reasons;
    }

    /**
     * @param  Collection<int, SalesOrderItem>  $items
     * @param  Collection<int, DeliveryItem>  $deliveryItems
     * @param  Collection<string, Delivery>  $deliveries
     * @param  Collection<string, DeliveryConfirmation>  $outcomes
     * @param  Collection<string, ShipmentDelivery>  $links
     * @param  Collection<string, Shipment>  $shipments
     */
    private function analyse(
        SalesOrder $order,
        Collection $items,
        Collection $deliveryItems,
        Collection $deliveries,
        Collection $outcomes,
        Collection $links,
        Collection $shipments,
        ?Invoice $invoice,
    ): array {
        $metrics = [
            'ordered_basic' => '0.000',
            'allocated_basic' => '0.000',
            'shipped_basic' => '0.000',
            'awaiting_pod_basic' => '0.000',
            // Dispatched (SHIPPED/PARTIALLY_DELIVERED/DELIVERED) rows with no
            // outcome yet — a strict SUBSET of awaiting_pod_basic, which also
            // covers mere allocations. Kept separate because "allocated, not
            // dispatched" and "dispatched, awaiting POD" are different
            // operational facts (the VAN one-cycle rule reports them apart).
            'dispatched_awaiting_pod_basic' => '0.000',
            'accepted_basic' => '0.000',
            'rejected_demand_basic' => '0.000',
            'pod_rejected_basic' => '0.000',
            'remaining_fulfillable_basic' => '0.000',
            'draft_allocations' => 0,
            'allocated_not_shipped' => 0,
            'ready_shipments' => 0,
            'awaiting_pod_rows' => 0,
        ];

        $itemRows = [];

        foreach ($items as $item) {
            $ordered = $this->safeBasic((string) $item->product_id, (string) $item->order_qty, (string) $item->order_unit);

            if ($ordered === null) {
                continue; // unmappable unit — excluded rather than guessed
            }

            $allocated = '0.000';
            $shipped = '0.000';
            $awaiting = '0.000';
            $accepted = '0.000';
            $podRejected = '0.000';

            foreach ($deliveryItems->where('sales_order_item_no', $item->item_no) as $row) {
                $delivery = $deliveries->get($row->delivery_no);

                if ($delivery === null) {
                    continue;
                }

                $status = $delivery->delivery_status->value;

                if ($status === DeliveryStatus::DRAFT->value) {
                    $metrics['draft_allocations']++;

                    continue;
                }

                if (! in_array($status, self::ALLOCATED_STATUSES, true)) {
                    continue;
                }

                $rowBasic = $this->safeBasic((string) $row->product_id, (string) $row->allocated_qty, (string) $row->delivery_unit);

                if ($rowBasic === null) {
                    continue;
                }

                $allocated = Decimal::add($allocated, $rowBasic, 3);

                if (in_array($status, self::SHIPPED_STATUSES, true)) {
                    $shipped = Decimal::add($shipped, $rowBasic, 3);
                }

                if ($status === DeliveryStatus::ALLOCATED->value && $delivery->shipped_at === null) {
                    $metrics['allocated_not_shipped']++;

                    $link = $links->get($row->delivery_no);
                    $shipment = $link !== null ? $shipments->get($link->shipment_no) : null;

                    if ($shipment !== null && in_array($shipment->shipment_status, [ShipmentStatus::DRAFT, ShipmentStatus::READY], true)) {
                        $metrics['ready_shipments']++;
                    }
                }

                $outcome = $outcomes->get($row->delivery_no.'|'.$row->item_no);

                if ($outcome === null) {
                    $awaiting = Decimal::add($awaiting, $rowBasic, 3);
                    $metrics['awaiting_pod_rows']++;

                    if (in_array($status, self::SHIPPED_STATUSES, true)) {
                        $metrics['dispatched_awaiting_pod_basic'] = Decimal::add(
                            $metrics['dispatched_awaiting_pod_basic'], $rowBasic, 3,
                        );
                    }

                    continue;
                }

                if ($outcome->confirmation_status === ConfirmationStatus::CONFIRMED) {
                    $accepted = Decimal::add($accepted, $rowBasic, 3);
                } elseif ($outcome->confirmation_status === ConfirmationStatus::PARTIAL) {
                    $partial = $this->safeBasic(
                        (string) $row->product_id, (string) $outcome->confirmed_qty, (string) $outcome->confirmed_unit,
                    );

                    if ($partial !== null) {
                        $accepted = Decimal::add($accepted, $partial, 3);
                    }
                } else {
                    $podRejected = Decimal::add($podRejected, $rowBasic, 3);
                }
            }

            // Rejection is per ITEM: the unfulfilled remainder is dead demand
            // and leaves the lifecycle entirely.
            $dead = $item->isRejected() && Decimal::compare($ordered, $allocated, 3) > 0
                ? Decimal::sub($ordered, $allocated, 3)
                : '0.000';

            $remaining = $item->isRejected()
                ? '0.000'
                : (Decimal::compare($ordered, $allocated, 3) > 0 ? Decimal::sub($ordered, $allocated, 3) : '0.000');

            $itemRows[] = [
                'item_no' => $item->item_no,
                'product_id' => $item->product_id,
                'order_unit' => (string) $item->order_unit,
                'order_qty' => (string) $item->order_qty,
                'ordered_basic' => $ordered,
                'allocated_basic' => $allocated,
                'awaiting_pod_basic' => $awaiting,
                'accepted_basic' => $accepted,
                'pod_rejected_basic' => $podRejected,
                'rejected_demand_basic' => $dead,
                'remaining_basic' => $remaining,
                'rejected' => $item->isRejected(),
                'is_free_item' => (bool) $item->is_free_item,
            ];

            foreach (['ordered' => $ordered, 'allocated' => $allocated, 'shipped' => $shipped, 'awaiting_pod' => $awaiting, 'accepted' => $accepted, 'rejected_demand' => $dead, 'pod_rejected' => $podRejected, 'remaining_fulfillable' => $remaining] as $key => $value) {
                $metrics[$key.'_basic'] = Decimal::add($metrics[$key.'_basic'], $value, 3);
            }
        }

        // ---- derived category -------------------------------------------------
        $outstanding = $invoice?->outstandingAmount();

        $reasons = [];

        if ($order->order_status === OrderStatus::DRAFT) {
            $reasons[] = 'Draft — not confirmed';
        }

        if (Decimal::compare($metrics['remaining_fulfillable_basic'], '0', 3) > 0) {
            $reasons[] = 'Open demand';
        }

        if ($metrics['draft_allocations'] > 0) {
            $reasons[] = 'Allocation released — re-allocate';
        }

        if ($metrics['allocated_not_shipped'] > 0) {
            $reasons[] = $metrics['ready_shipments'] > 0 ? 'Shipment not started' : 'Allocated — not dispatched';
        }

        if (Decimal::compare($metrics['awaiting_pod_basic'], '0', 3) > 0) {
            $reasons[] = 'Awaiting POD';
        }

        if (Decimal::compare($metrics['accepted_basic'], '0', 3) > 0 && $invoice === null) {
            $reasons[] = 'Invoice pending';
        }

        if ($invoice !== null && Decimal::compare((string) $outstanding, '0', 2) > 0) {
            $reasons[] = 'Payment outstanding';
        }

        $steps = [
            ['key' => 'so', 'label' => 'SO', 'state' => $order->order_status === OrderStatus::DRAFT ? 'active' : 'done', 'detail' => $order->order_status->value],
            ['key' => 'delivery', 'label' => 'Delivery', 'state' => Decimal::compare($metrics['allocated_basic'], '0', 3) > 0 ? 'done' : ($metrics['draft_allocations'] > 0 ? 'active' : 'pending'), 'detail' => $metrics['draft_allocations'] > 0 ? $metrics['draft_allocations'].' draft row(s)' : null],
            ['key' => 'shipment', 'label' => 'Shipment', 'state' => Decimal::compare($metrics['shipped_basic'], '0', 3) > 0 ? 'done' : ($metrics['allocated_not_shipped'] > 0 ? 'active' : 'pending'), 'detail' => $metrics['allocated_not_shipped'] > 0 ? $metrics['allocated_not_shipped'].' allocated, not dispatched' : null],
            ['key' => 'pod', 'label' => 'POD', 'state' => Decimal::compare($metrics['awaiting_pod_basic'], '0', 3) > 0 ? 'active' : (Decimal::compare($metrics['accepted_basic'], '0', 3) > 0 || Decimal::compare($metrics['pod_rejected_basic'], '0', 3) > 0 ? 'done' : 'pending'), 'detail' => $metrics['awaiting_pod_rows'] > 0 ? $metrics['awaiting_pod_rows'].' row(s) awaiting confirmation' : null],
            ['key' => 'invoice', 'label' => 'Invoice', 'state' => $invoice !== null ? 'done' : (Decimal::compare($metrics['accepted_basic'], '0', 3) > 0 ? 'active' : 'pending'), 'detail' => $invoice?->invoice_no],
            ['key' => 'payment', 'label' => 'Payment', 'state' => $invoice === null ? 'pending' : (Decimal::compare((string) $outstanding, '0', 2) > 0 ? 'active' : 'done'), 'detail' => $invoice?->payment_status->value],
        ];

        return [
            'state' => $reasons === [] ? 'COMPLETED' : 'ONGOING',
            'reasons' => $reasons,
            'steps' => $steps,
            'items' => $itemRows,
            'metrics' => $metrics,
            'invoice' => $invoice,
            'outstanding' => $outstanding,
            'has_work' => $reasons !== [],
        ];
    }

    /** Basic-unit conversion that degrades gracefully instead of guessing. */
    private function safeBasic(string $productId, string $qty, string $unit): ?string
    {
        try {
            return $this->units->toBasicById($productId, $qty, $unit);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * How many basic units one display unit (CTN) holds, from the EXISTING
     * conversion rows. Null when the product has no such conversion — the UI
     * must not invent one.
     */
    public function displayFactor(string $productId): ?string
    {
        if (array_key_exists($productId, $this->factorCache)) {
            return $this->factorCache[$productId];
        }

        // A product whose BASIC unit already is the display unit needs no
        // conversion row: its quantities are CTN as recorded. (Inventing a
        // conversion here would be wrong; reporting "no conversion" would be
        // misleading.)
        if (strtoupper((string) $this->basicUnit($productId)) === self::DISPLAY_UNIT) {
            return $this->factorCache[$productId] = '1';
        }

        $conversion = ProductUnitConversion::where('product_id', $productId)
            ->where('alternative_unit', self::DISPLAY_UNIT)
            ->first();

        $factor = null;

        if ($conversion !== null && Decimal::compare((string) $conversion->denominator, '0', 6) !== 0) {
            $factor = Decimal::div((string) $conversion->numerator, (string) $conversion->denominator, 6);
        }

        return $this->factorCache[$productId] = $factor;
    }

    /** basic qty ÷ factor, or null when no conversion exists. */
    public function displayQty(string $basicQty, ?string $factor): ?string
    {
        if ($factor === null || Decimal::compare($factor, '0', 6) === 0) {
            return null;
        }

        return Decimal::format((float) Decimal::div($basicQty, $factor, 6), 3);
    }

    /** The product's basic unit, memoized (one query per product per request). */
    private function basicUnit(string $productId): ?string
    {
        if (! array_key_exists($productId, $this->basicUnitCache)) {
            $this->basicUnitCache[$productId] = ProductMaster::where('product_id', $productId)->value('basic_unit');
        }

        return $this->basicUnitCache[$productId];
    }
}
