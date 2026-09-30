<?php

namespace App\Services;

use App\Enums\ConfirmationStatus;
use App\Enums\DeliveryStatus;
use App\Enums\InvoicePaymentStatus;
use App\Exceptions\ConfirmationConflict;
use App\Models\CustomerCredit;
use App\Models\DeliveryConfirmation;
use App\Models\DeliveryItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ProductMaster;
use App\Models\ProductUnitConversion;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8 — Invoices (Model A: ONE final invoice per Sales Order).
 *
 * Rulings applied (docs/PHASE8_DESIGN.md REV 2 + §23):
 *  - Billing boundary = authoritative POD. The invoice is generated inside
 *    the POD transaction that first reaches billing-terminal state.
 *  - Billing-terminal: every SO item has a final disposition — terminal POD
 *    outcomes (CONFIRMED / PARTIAL / REJECTED) for all shipped Delivery Items
 *    and/or SO rejection of the remaining demand. Only confirmed_qty invoices.
 *  - Debtor = sold_to_customer_id (server-side, never client-supplied).
 *  - Price = SO snapshot (unit_price in the ORDER UNIT); quantity and price
 *    basis convert TOGETHER. Exact back-conversion → order-unit line;
 *    otherwise basic-unit line with the price divided by the factor. The
 *    authoritative subtotal is computed at full precision and rounded ONCE
 *    (ruling §23.1) — never re-derived from the display-rounded unit_price.
 *  - IMMEDIATE / due_date = invoice_date; tax/discount inherited as-is.
 *  - Positive net exposure blocks SO confirmation (§14) — see exposure().
 */
class InvoiceService
{
    /** Company-prefixed invoice number: INV-EMANL-2026-00001. */
    public function nextNumber(string $companyId): string
    {
        $year = now()->format('Y');
        $prefix = "INV-$companyId-$year-";

        $max = Invoice::where('invoice_no', 'like', $prefix.'%')->max('invoice_no');

        $seq = $max !== null ? (int) substr($max, strlen($prefix)) + 1 : 1;

        return $prefix.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Generate the SO's final invoice if billing-terminal and not yet
     * generated. Runs INSIDE the caller's (POD) transaction; idempotent
     * (returns the existing invoice when present; uq_invoice_so backstops).
     */
    public function generateIfTerminal(SalesOrder $order, string $paymentTerm = 'IMMEDIATE', ?string $dueDate = null): ?Invoice
    {
        $order->refresh();
        $order->load('items');

        $existing = Invoice::where('sales_order_no', $order->sales_order_no)->first();

        if ($existing !== null) {
            return $existing; // Model A: already generated
        }

        if (! $this->isBillingTerminal($order)) {
            return null;
        }

        return $this->generate($order, $paymentTerm, $dueDate);
    }

    /** Billing-terminal test (§1 REV 2): final disposition for every item. */
    public function isBillingTerminal(SalesOrder $order): bool
    {
        $order->loadMissing('items');

        if ($order->items->isEmpty()) {
            return false;
        }

        foreach ($order->items as $item) {
            if ($item->isRejected()) {
                continue; // rejected remainder = final disposition
            }

            $shippedRows = DeliveryItem::where('sales_order_no', $item->sales_order_no)
                ->where('sales_order_item_no', $item->item_no)
                ->whereHas('delivery', fn ($q) => $q->whereIn('delivery_status', [
                    DeliveryStatus::SHIPPED->value,
                    DeliveryStatus::DELIVERED->value,
                    DeliveryStatus::PARTIALLY_DELIVERED->value,
                ]))
                ->get();

            // Unshipped (still open) demand is NOT terminal.
            $orderedBasic = app(ProductUnitService::class)->toBasicById(
                $item->product_id, (string) $item->order_qty, (string) $item->order_unit,
            );

            $shippedBasic = '0.000';
            $outcomes = collect();

            foreach ($shippedRows as $row) {
                try {
                    $shippedBasic = Decimal::add($shippedBasic, app(ProductUnitService::class)->toBasicById(
                        $row->product_id, (string) $row->allocated_qty, (string) $row->delivery_unit,
                    ), 3);
                } catch (\InvalidArgumentException) {
                    return false;
                }

                $outcome = DeliveryConfirmation::where('delivery_no', $row->delivery_no)
                    ->where('delivery_item_no', $row->item_no)
                    ->first();

                if ($outcome === null) {
                    return false; // awaiting POD
                }

                $outcomes->push($outcome);
            }

            // Every shipped row must carry a TERMINAL outcome; confirmed
            // basic quantities are summed at 3dp (outcomes are authoritative).
            $confirmedSum = '0.000';

            foreach ($outcomes as $outcome) {
                if ($outcome->confirmation_status === ConfirmationStatus::CONFIRMED
                    || $outcome->confirmation_status === ConfirmationStatus::PARTIAL) {
                    $confirmedSum = Decimal::add($confirmedSum, app(ProductUnitService::class)->toBasicById(
                        $item->product_id, (string) $outcome->confirmed_qty, (string) $outcome->confirmed_unit,
                    ), 3);
                }
            }

            $rowsOutcomed = $outcomes->count() === $shippedRows->count();
            $fullyShipped = Decimal::compare($shippedBasic, $orderedBasic, 3) >= 0;

            // Terminal ⇔ (fully shipped AND every row outcome'd — whatever the
            // outcomes were, even zero-confirmed rejections), OR a rejected
            // remainder on partially-shipped demand.
            if ($fullyShipped && $rowsOutcomed) {
                continue;
            }

            if ($fullyShipped && ! $rowsOutcomed) {
                return false; // awaiting POD on a shipped row
            }

            // Partially shipped: terminal only when the unshipped remainder
            // is explicitly rejected (§3 REV 2 disposition).
            if (! $item->isRejected()) {
                return false;
            }
        }

        return true;
    }

    /** Build and persist the final invoice (Model A); null when nothing invoiceable. */
    public function generate(SalesOrder $order, string $paymentTerm = 'IMMEDIATE', ?string $dueDate = null): ?Invoice
    {
        $order->refresh();
        $order->load('items');

        /** @var Invoice|null $invoice */
        $invoice = null;

        DB::transaction(function () use (&$invoice, $order, $paymentTerm, $dueDate) {
            $locked = SalesOrder::whereKey($order->sales_order_no)->lockForUpdate()->first();

            $existing = Invoice::where('sales_order_no', $order->sales_order_no)->first();

            if ($existing !== null) {
                $invoice = $existing;

                return;
            }

            $units = app(ProductUnitService::class);
            $lines = [];
            $gross = '0.00';
            $discountTotal = '0.00';
            $taxTotal = '0.00';
            $index = 1;

            foreach ($locked->items as $item) {
                $invoiceableBasic = $this->invoiceableBasicQty($item);

                if (Decimal::compare($invoiceableBasic, '0', 3) === 0) {
                    continue; // rejected / never shipped — omitted
                }

                $product = ProductMaster::findOrFail($item->product_id);
                $basicUnit = $product->basic_unit;
                $orderUnit = (string) $item->order_unit;
                $unitPrice = (string) $item->unit_price;

                if ($orderUnit === $basicUnit) {
                    // Basic-denominated price: direct.
                    $qty = $invoiceableBasic;
                    $lineUnit = $basicUnit;
                    $subtotal = Decimal::mul($qty, $unitPrice, 2);
                    $linePrice = $unitPrice;
                } else {
                    try {
                        $factor = $this->conversionFactor($product, $orderUnit, $basicUnit);
                    } catch (\InvalidArgumentException $e) {
                        abort(422, $e->getMessage());
                    }

                    $quotient = Decimal::div($invoiceableBasic, $factor, 6);

                    // Exact whole back-conversion ⇔ basic_qty ÷ factor is a
                    // non-negative integer (e.g. 240 PCS ÷ 24 → 8 CTN).
                    $isWhole = Decimal::compare(Decimal::mul($quotient, $factor, 6), $invoiceableBasic, 6) === 0
                        && (float) $quotient === floor((float) $quotient);

                    if ($isWhole) {
                        // Whole order units → order-unit line at snapshot price.
                        $qty = Decimal::format((float) $quotient, 3);
                        $lineUnit = $orderUnit;
                        $linePrice = $unitPrice;
                        // Authoritative subtotal (one final computation).
                        $subtotal = Decimal::div(
                            Decimal::mul($invoiceableBasic, $unitPrice, 6),
                            $factor,
                            2,
                        );
                    } else {
                        // Fallback: basic-unit line, price basis divided too.
                        $qty = $invoiceableBasic;
                        $lineUnit = $basicUnit;
                        $linePrice = Decimal::div($unitPrice, $factor, 2);
                        $subtotal = Decimal::div(
                            Decimal::mul($invoiceableBasic, $unitPrice, 6),
                            $factor,
                            2,
                        );
                    }
                }

                $discount = (string) $item->discount_amount;
                $tax = (string) $item->tax_amount;

                $lines[] = [
                    'item_no' => $index++,
                    'product_id' => $item->product_id,
                    'is_free_item' => (bool) $item->is_free_item,
                    'quantity' => $qty,
                    'invoice_unit' => $lineUnit,
                    'unit_price' => $linePrice,
                    'discount_amount' => $discount,
                    'tax_amount' => $tax,
                    'subtotal_amount' => $subtotal,
                    'sales_order_no' => $item->sales_order_no,
                    'sales_order_item_no' => $item->item_no,
                ];

                $gross = Decimal::add($gross, $subtotal, 2);
                $discountTotal = Decimal::add($discountTotal, $discount, 2);
                $taxTotal = Decimal::add($taxTotal, $tax, 2);
            }

            $invoiceAmount = Decimal::sub(Decimal::add($gross, $taxTotal, 2), $discountTotal, 2);

            // §19.4: an order whose every item is rejected (or never shipped)
            // is billing-terminal but has NO invoiceable quantity — no
            // monetary invoice is generated.
            if ($lines === []) {
                $invoice = null;

                return;
            }

            $invoice = Invoice::create([
                'invoice_no' => $this->nextNumber($locked->company_id),
                'company_id' => $locked->company_id,
                'customer_id' => $locked->sold_to_customer_id, // RULED debtor
                'sales_order_no' => $locked->sales_order_no,
                'invoice_date' => now(),
                'due_date' => $dueDate ?? today(),
                'currency' => $locked->currency,
                'gross_amount' => $gross,
                'discount_amount' => $discountTotal,
                'tax_amount' => $taxTotal,
                'invoice_amount' => $invoiceAmount,
                'credit_amount' => '0.00',
                'settled_amount' => '0.00',
                'payment_status' => InvoicePaymentStatus::UNPAID,
                'payment_term' => $paymentTerm,
            ]);

            foreach ($lines as $line) {
                InvoiceItem::create($line + ['invoice_no' => $invoice->invoice_no]);
            }
        });

        return $invoice;
    }

    /**
     * Invoiceable basic quantity for an SO item (§3 REV 2):
     * CONFIRMED → shipped basic; PARTIAL → confirmed basic; REJECTED → 0.
     */
    public function invoiceableBasicQty(SalesOrderItem $item): string
    {
        $units = app(ProductUnitService::class);
        $sum = '0.000';

        $rows = DeliveryItem::where('sales_order_no', $item->sales_order_no)
            ->where('sales_order_item_no', $item->item_no)
            ->whereHas('delivery', fn ($q) => $q->whereIn('delivery_status', [
                DeliveryStatus::SHIPPED->value,
                DeliveryStatus::DELIVERED->value,
                DeliveryStatus::PARTIALLY_DELIVERED->value,
            ]))
            ->get();

        foreach ($rows as $row) {
            $outcome = DeliveryConfirmation::where('delivery_no', $row->delivery_no)
                ->where('delivery_item_no', $row->item_no)
                ->first();

            if ($outcome === null) {
                continue;
            }

            $basis = match ($outcome->confirmation_status) {
                ConfirmationStatus::CONFIRMED => (string) $row->allocated_qty,
                ConfirmationStatus::PARTIAL => (string) $outcome->confirmed_qty,
                ConfirmationStatus::REJECTED => '0',
            };

            // PARTIAL quantities are expressed in the CONFIRMATION's unit,
            // which may differ from the delivery's unit (30 PCS confirmed on
            // a CTN delivery is 30 PCS, never 30 CTN).
            $basisUnit = $outcome->confirmation_status === ConfirmationStatus::PARTIAL
                ? (string) $outcome->confirmed_unit
                : (string) $row->delivery_unit;

            try {
                $sum = Decimal::add($sum, $units->toBasicById($row->product_id, $basis, $basisUnit), 3);
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        return $sum;
    }

    /** factor = how many basic units one alternative unit contains. */
    private function conversionFactor(ProductMaster $product, string $alternativeUnit, string $basicUnit): string
    {
        if ($alternativeUnit === $basicUnit) {
            return '1';
        }

        $conversion = ProductUnitConversion::where('product_id', $product->product_id)
            ->where('alternative_unit', $alternativeUnit)
            ->first();

        if ($conversion === null || Decimal::compare((string) $conversion->denominator, '0', 6) === 0) {
            throw new \InvalidArgumentException("No conversion defined for product {$product->product_id} from unit '{$alternativeUnit}' to '{$basicUnit}'.");
        }

        return Decimal::div((string) $conversion->numerator, (string) $conversion->denominator, 6);
    }

    /**
     * Debtor exposure (§13 REV 2) — three SEPARATE values, against the
     * sold-to debtor only:
     *   invoice_outstanding = Σ invoice_amount − Σ settled_amount
     *   available_credit    = Σ OPEN/PARTIALLY_USED remaining_amount
     *   net_exposure        = max(0, outstanding − available_credit)
     *
     * @return array{outstanding: string, available_credit: string, net_exposure: string}
     */
    public function exposure(string $customerId, ?string $companyId = null): array
    {
        $invoiceQuery = Invoice::where('customer_id', $customerId)
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId));

        $outstanding = '0.00';

        // Outstanding debt per invoice nets off BOTH settled payments AND
        // applied credit (credit_amount) — an invoice's credit reduces the
        // debtor's real debt, while open (unapplied) credit reduces exposure
        // separately via available_credit below.
        $invoiceQuery->get(['invoice_amount', 'settled_amount', 'credit_amount'])->each(function (Invoice $i) use (&$outstanding) {
            $outstanding = Decimal::add(
                $outstanding,
                Decimal::sub(Decimal::sub((string) $i->invoice_amount, (string) $i->settled_amount, 2), (string) $i->credit_amount, 2),
                2,
            );
        });

        $available = '0.00';

        CustomerCredit::where('customer_id', $customerId)
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->whereIn('credit_status', ['OPEN', 'PARTIALLY_USED'])
            ->get(['remaining_amount'])
            ->each(function (CustomerCredit $c) use (&$available) {
                $available = Decimal::add($available, (string) $c->remaining_amount, 2);
            });

        $net = Decimal::compare($outstanding, $available, 2) > 0
            ? Decimal::sub($outstanding, $available, 2)
            : '0.00';

        return [
            'outstanding' => $outstanding,
            'available_credit' => $available,
            'net_exposure' => $net,
        ];
    }

    /**
     * Post-confirmation exposure guard (audit correction, business rule 28):
     * a debtor with positive net exposure must not receive additional
     * commercial exposure at the SAFE boundaries that follow confirmation —
     * delivery allocation and Shipment START. Uses the same three-value
     * exposure math as SO confirmation, so credit/outstanding are never
     * double-counted.
     *
     * POD / rejection / transit / return are deliberately NOT guarded: stock
     * that already left the source must remain fully accountable.
     */
    public function assertDebtorWithinExposure(string $customerId, ?string $companyId, string $action, string $currency = 'NGN'): void
    {
        $exposure = $this->exposure($customerId, $companyId);

        if (Decimal::compare($exposure['net_exposure'], '0', 2) > 0) {
            abort(422, sprintf(
                'Credit exposure: %s has a net exposure of %s %s (invoice outstanding %s, available credit %s). %s is blocked until the debt is settled or customer credit is applied.',
                $customerId,
                $exposure['net_exposure'],
                $currency,
                $exposure['outstanding'],
                $exposure['available_credit'],
                $action,
            ));
        }
    }

    /**
     * SO-confirmation blocking (RULED §21.7): a sold-to debtor with positive
     * net exposure cannot confirm another SO. Throws ConfirmationConflict.
     */
    public function assertDebtorCanConfirm(SalesOrder $order): void
    {
        $exposure = $this->exposure($order->sold_to_customer_id, $order->company_id);

        if (Decimal::compare($exposure['net_exposure'], '0', 2) > 0) {
            throw new ConfirmationConflict([
                sprintf(
                    'Credit exposure: %s owes a net exposure of %s %s (outstanding %s, available credit %s). Settle existing invoices or apply customer credit before confirming another order.',
                    $order->sold_to_customer_id,
                    $exposure['net_exposure'],
                    $order->currency,
                    $exposure['outstanding'],
                    $exposure['available_credit'],
                ),
            ]);
        }
    }
}
