<?php

namespace App\Services;

use App\Enums\ConfirmationStatus;
use App\Enums\DeliveryStatus;
use App\Enums\DifferenceDisposition;
use App\Enums\DifferenceReason;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\CustomerEmployee;
use App\Models\Delivery;
use App\Models\DeliveryConfirmation;
use App\Models\DeliveryItem;
use App\Models\EmployeeMaster;
use App\Models\SalesOrder;
use App\Models\Shipment;
use App\Models\ShipmentDelivery;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7 — POD: what the receiving customer confirms was received.
 *
 * Invariants (docs/PHASE7_DESIGN.md, APPROVED with §9 rulings):
 *  - POD NEVER touches inventory: GOODS_ISSUE stays immutable; differences
 *    restore nothing (return/credit/liability workflows are future phases).
 *  - One AUTHORITATIVE confirmation per shipped Delivery Item; no overwrite.
 *  - Quantities normalized to the product BASIC unit via Decimal math;
 *    confirmed > shipped is rejected (no accidental over-delivery).
 *  - Status: CONFIRMED (full), PARTIAL (0 < confirmed < shipped, non-NONE
 *    reason REQUIRED), REJECTED (zero confirmed, non-NONE reason REQUIRED).
 *  - Authorization (RULED §9.1): the confirming employee must be assigned to
 *    the DELIVERY's sold_to_customer_id — the receiving side of the flow.
 *  - Derivation: Delivery → DELIVERED / PARTIALLY_DELIVERED when every
 *    shipped item has an outcome; SO header derives from confirmed basic
 *    quantities (rejection precedence preserved, §9.2); Shipment IN_TRANSIT →
 *    COMPLETED when every attached delivery has outcomes for all items (§9.3).
 */
class PodService
{
    public function __construct(
        private readonly ProductUnitService $units,
        private readonly InvoiceService $invoices,
        private readonly TransitService $transit,
    ) {}

    /**
     * Authoritatively confirm one shipped Delivery Item.
     *
     * @return array{confirmation: DeliveryConfirmation, delivery: Delivery, shipment: Shipment}
     */
    public function confirmItem(
        EmployeeMaster $actor,
        string $deliveryNo,
        int $deliveryItemNo,
        string $confirmedQty,
        string $confirmedUnit,
        DifferenceReason $reason,
        ?string $remarks = null,
        ?DifferenceDisposition $disposition = null,
    ): array {
        /** @var array{confirmation: DeliveryConfirmation, delivery: Delivery, shipment: Shipment}|null $result */
        $result = null;

        DB::transaction(function () use (&$result, $actor, $deliveryNo, $deliveryItemNo, $confirmedQty, $confirmedUnit, $reason, $remarks, $disposition) {
            // Lock the delivery; everything downstream validates under it.
            $delivery = Delivery::whereKey($deliveryNo)->lockForUpdate()->first();

            if ($delivery === null) {
                abort(404, 'Delivery not found.');
            }

            // Duplicate check FIRST — a retried/duplicate submission on an
            // already-outcome'd item must read as a duplicate, not as a
            // confusing status error (delivery may already be DELIVERED).
            $existing = DeliveryConfirmation::where('delivery_no', $deliveryNo)
                ->where('delivery_item_no', $deliveryItemNo)
                ->lockForUpdate()
                ->exists();

            if ($existing) {
                abort(422, 'This delivery item already has a confirmation. POD corrections are a separate future workflow.');
            }

            if ($delivery->delivery_status !== DeliveryStatus::SHIPPED) {
                abort(422, 'Only SHIPPED deliveries can be confirmed (this one is '.$delivery->delivery_status->value.').');
            }

            // The delivery must be on an IN_TRANSIT shipment.
            $shipment = Shipment::whereIn('shipment_no', ShipmentDelivery::where('delivery_no', $deliveryNo)->select('shipment_no'))
                ->lockForUpdate()
                ->first();

            if ($shipment === null || $shipment->shipment_status !== ShipmentStatus::IN_TRANSIT) {
                abort(422, 'POD requires the delivery to be on an IN_TRANSIT shipment.');
            }

            $item = DeliveryItem::where('delivery_no', $deliveryNo)
                ->where('item_no', $deliveryItemNo)
                ->lockForUpdate()
                ->first();

            if ($item === null) {
                abort(404, 'Delivery item not found.');
            }

            // Basic-unit normalization (also rejects unknown units/conversions).
            try {
                $shippedBasic = $this->units->toBasicById($item->product_id, (string) $item->allocated_qty, (string) $item->delivery_unit);
                $confirmedBasic = $this->units->toBasicById($item->product_id, $confirmedQty, $confirmedUnit);
            } catch (\InvalidArgumentException $e) {
                abort(422, $e->getMessage());
            }

            if (Decimal::compare($confirmedBasic, '0', 3) < 0) {
                abort(422, 'Confirmed quantity cannot be negative.');
            }

            if (Decimal::compare($confirmedBasic, $shippedBasic, 3) > 0) {
                abort(422, 'Confirmed '.Decimal::trimZeros($confirmedBasic).' '.$item->product->basic_unit
                    .' exceeds the shipped '.Decimal::trimZeros($shippedBasic).' '.$item->product->basic_unit
                    .' — over-delivery is not supported.');
            }

            $differenceBasic = Decimal::sub($shippedBasic, $confirmedBasic, 3);
            $isFull = Decimal::compare($differenceBasic, '0', 3) === 0;
            $isZero = Decimal::compare($confirmedBasic, '0', 3) === 0;

            $status = $isFull
                ? ConfirmationStatus::CONFIRMED
                : ($isZero ? ConfirmationStatus::REJECTED : ConfirmationStatus::PARTIAL);

            // Reason matrix: full confirmation → NONE forced; a difference
            // requires a non-NONE reason.
            if ($isFull) {
                $reason = DifferenceReason::NONE;
            } elseif ($reason === DifferenceReason::NONE) {
                abort(422, 'A difference requires a reason (EMPLOYEE_DAMAGE, DISTRIBUTOR_DAMAGE, CUSTOMER_REJECTED, SHORT_DELIVERY, RETURNED or OTHER).');
            }

            $confirmation = DeliveryConfirmation::create([
                'delivery_no' => $deliveryNo,
                'delivery_item_no' => $deliveryItemNo,
                'confirmed_qty' => $confirmedQty,
                'confirmed_unit' => $confirmedUnit,
                'difference_qty' => $this->differenceInInputUnit($item, $differenceBasic, $confirmedUnit),
                'difference_unit' => $confirmedUnit,
                'difference_reason' => $reason,
                'confirmation_status' => $status,
                'confirmation_date' => now(),
                'confirmed_by' => $actor->employee_id,
                'remarks' => $remarks,
            ]);

            // Phase 9: a POD difference becomes traceable transit stock per
            // the (reason × disposition) matrix — SHORT_DELIVERY / OTHER and
            // unknown whereabouts NEVER manufacture stock. Legacy callers
            // without an explicit disposition get the physically-default
            // one (refusals stay with the driver; damages are damages;
            // shortages are unknown). The new UI/API requires explicit
            // disposition.
            $effectiveDisposition = $disposition ?? match ($reason) {
                DifferenceReason::EMPLOYEE_DAMAGE, DifferenceReason::DISTRIBUTOR_DAMAGE => DifferenceDisposition::DAMAGED,
                DifferenceReason::SHORT_DELIVERY, DifferenceReason::OTHER => DifferenceDisposition::UNKNOWN,
                default => DifferenceDisposition::WITH_EMPLOYEE,
            };

            $this->transit->recordPodDifference(
                $actor,
                $item,
                $confirmation->confirmation_id,
                $differenceBasic,
                $reason,
                $effectiveDisposition,
            );

            $this->deriveDeliveryStatus($delivery);

            $shipment->refresh();
            $this->maybeCompleteShipment($shipment);

            $this->refreshOrderFromConfirmations(SalesOrder::findOrFail($delivery->sales_order_no));

            // Billing boundary (Phase 8, RULED): the FIRST transaction that
            // leaves every item terminally dispositioned generates the SO's
            // single final invoice here — still inside the POD transaction,
            // under the same locks. Idempotent: an existing invoice is
            // returned untouched and uq_invoice_so backstops any race.
            $order = SalesOrder::whereKey($delivery->sales_order_no)->first();

            $invoice = $order !== null
                ? $this->invoices->generateIfTerminal($order)
                : null;

            $result = [
                'confirmation' => $confirmation->fresh(),
                'delivery' => $delivery->fresh(),
                'shipment' => $shipment->fresh(),
                'invoice' => $invoice,
            ];
        });

        return $result;
    }

    /**
     * Re-express a basic-unit difference in the user's input unit (display
     * convenience; the authoritative comparison was basic-vs-basic).
     */
    private function differenceInInputUnit(DeliveryItem $item, string $differenceBasic, string $inputUnit): string
    {
        if ($inputUnit === $item->product->basic_unit) {
            return $differenceBasic;
        }

        try {
            return $this->units->fromBasic($item->product, $differenceBasic, $inputUnit);
        } catch (\InvalidArgumentException) {
            return $differenceBasic;
        }
    }

    /**
     * Delivery derivation (forward only): every shipped item has an outcome →
     * DELIVERED (all CONFIRMED) or PARTIALLY_DELIVERED (any PARTIAL/REJECTED).
     */
    private function deriveDeliveryStatus(Delivery $delivery): void
    {
        $items = $delivery->items()->get();

        if ($items->isEmpty() || $delivery->delivery_status !== DeliveryStatus::SHIPPED) {
            return;
        }

        // One authoritative confirmation per item (composite lookup — see the
        // DeliveryItem::confirmations note on the Eloquent limitation).
        $outcomes = DeliveryConfirmation::where('delivery_no', $delivery->delivery_no)
            ->get()
            ->keyBy('delivery_item_no');

        if ($items->contains(fn (DeliveryItem $i) => ! $outcomes->has($i->item_no))) {
            return; // still awaiting POD for at least one item
        }

        $allConfirmed = $items->every(
            fn (DeliveryItem $i) => $outcomes[$i->item_no]->confirmation_status === ConfirmationStatus::CONFIRMED,
        );

        $delivery->delivery_status = $allConfirmed ? DeliveryStatus::DELIVERED : DeliveryStatus::PARTIALLY_DELIVERED;
        $delivery->delivered_at = now();
        $delivery->save();
    }

    /**
     * Shipment IN_TRANSIT → COMPLETED (outcome-only, RULED §9.3): every
     * attached delivery has an authoritative outcome for every item.
     */
    private function maybeCompleteShipment(Shipment $shipment): void
    {
        if ($shipment->shipment_status !== ShipmentStatus::IN_TRANSIT) {
            return;
        }

        $deliveries = Delivery::whereIn('delivery_no', ShipmentDelivery::where('shipment_no', $shipment->shipment_no)->select('delivery_no'))
            ->with('items')
            ->get();

        $outcomes = DeliveryConfirmation::whereIn('delivery_no', $deliveries->pluck('delivery_no'))
            ->get()
            ->groupBy('delivery_no');

        $complete = $deliveries->isNotEmpty() && $deliveries->every(fn (Delivery $d) => $d->items->isNotEmpty() && $d->items->every(
            fn (DeliveryItem $i) => $outcomes->get($d->delivery_no, collect())->contains(
                fn ($c) => (int) $c->delivery_item_no === (int) $i->item_no,
            ),
        ));

        if ($complete) {
            $shipment->shipment_status = ShipmentStatus::COMPLETED;
            $shipment->completed_on = now();
            $shipment->save();
        }
    }

    /**
     * SO fulfillment from CONFIRMED basic quantities (RULED §9.2, examples
     * 1–6 of the design). Rejection precedence (Phase 4) is preserved; START
     * never wrote the header — only POD moves it past OPEN_DELIVERY.
     */
    public function refreshOrderFromConfirmations(SalesOrder $order): void
    {
        $order->refresh();
        $order->load('items');

        $items = $order->items;

        if ($items->isEmpty() || $order->order_status === OrderStatus::DRAFT) {
            return;
        }

        $allRejected = $items->every(fn ($i) => $i->isRejected());
        $anyRejected = $items->contains(fn ($i) => $i->isRejected());

        if ($allRejected) {
            $order->order_status = OrderStatus::COMPLETELY_REJECTED;
            $order->save();

            return;
        }

        if ($anyRejected) {
            $order->order_status = OrderStatus::PARTIALLY_REJECTED;
            $order->save();

            return;
        }

        $anyConfirmed = false;
        $allShippedConfirmed = true;
        $hasShipment = false;

        foreach ($items as $item) {
            $rows = DeliveryItem::where('sales_order_no', $item->sales_order_no)
                ->where('sales_order_item_no', $item->item_no)
                ->whereHas('delivery', fn ($q) => $q->whereIn('delivery_status', [
                    DeliveryStatus::SHIPPED->value,
                    DeliveryStatus::DELIVERED->value,
                    DeliveryStatus::PARTIALLY_DELIVERED->value,
                ]))
                ->get();

            if ($rows->isEmpty()) {
                $allShippedConfirmed = false;

                continue;
            }

            $hasShipment = true;

            $outcomes = DeliveryConfirmation::whereIn('delivery_no', $rows->pluck('delivery_no'))
                ->get()
                ->keyBy(fn ($c) => $c->delivery_no.'|'.$c->delivery_item_no);

            foreach ($rows as $row) {
                $confirmation = $outcomes->get($row->delivery_no.'|'.$row->item_no);

                if ($confirmation === null) {
                    $allShippedConfirmed = false;

                    continue;
                }

                $anyConfirmed = true;

                if ($confirmation->confirmation_status !== ConfirmationStatus::CONFIRMED) {
                    $allShippedConfirmed = false;
                }
            }
        }

        $order->order_status = match (true) {
            $hasShipment && $allShippedConfirmed => OrderStatus::COMPLETELY_DELIVERED,
            $anyConfirmed => OrderStatus::PARTIALLY_DELIVERED,
            default => $order->order_status, // nothing confirmed yet — untouched
        };

        $order->save();
    }

    /**
     * POD authorization (RULED §9.1): receiving-side sold-to assignment.
     * Admins are company-scoped via the delivery record (customers are
     * global); superadmin follows existing conventions.
     */
    public function assertCanConfirm($user, Delivery $delivery): void
    {
        if ($user->isSalesEmployee()) {
            abort_if($user->employee === null, 403, 'Your login is not linked to an employee record.');

            $assigned = CustomerEmployee::where('employee_id', $user->employee_id)
                ->where('customer_id', $delivery->customer_id) // sold_to_customer_id
                ->exists();

            abort_if(! $assigned, 403, 'POD belongs to the receiving side — you are not assigned to this delivery\'s customer.');

            return;
        }

        if (! $user->isSuperadmin()) {
            abort_if($delivery->company_id !== $user->company_id, 403, 'This delivery belongs to another company.');
        }
    }
}
