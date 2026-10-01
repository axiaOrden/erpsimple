<?php

namespace App\Services;

use App\Models\CustomerMaster;
use App\Models\Invoice;

/**
 * WHO the customer invoice is from and to.
 *
 * Business reality (UAT correction):
 *
 *   SELLER   = the SO's supplying Primary Customer (the Distributor who made
 *              the sale). Its address is the warehouse/registered location the
 *              goods actually left from.
 *   BILL TO  = the SO's sold-to Secondary customer — the FINANCIAL DEBTOR.
 *              Debtor semantics are unchanged: invoice.customer_id still
 *              decides credit exposure, settlement and statements.
 *   POWERED  = the sales employee + the employee's company: they supplied the
 *   BY         employee and the products, they are not the seller.
 *
 * Nothing here writes money or documents; it only resolves display parties.
 */
class InvoicePartyService
{
    /**
     * @return array{
     *     seller: array{name: string, lines: array<int, string>, phone: ?string, email: ?string},
     *     warehouse: ?array{name: string, lines: array<int, string>},
     *     debtor: array{name: string, lines: array<int, string>, phone: ?string},
     *     employee: array{id: ?string, name: ?string},
     *     company: array{name: ?string}
     * }
     */
    public function parties(Invoice $invoice): array
    {
        $invoice->loadMissing([
            'salesOrder.supplyingCustomer',
            'salesOrder.sourceCustomer',
            'salesOrder.salesEmployee',
            'customer',
            'company',
        ]);

        $order = $invoice->salesOrder;
        $seller = $order?->supplyingCustomer;
        $source = $order?->sourceCustomer;

        // The stock source may be a SHIP_TO warehouse of the supplying Primary;
        // it is only surfaced when it is a DIFFERENT location, and it never
        // replaces the seller identity.
        $warehouse = $source !== null && $source->customer_id !== $seller?->customer_id
            ? ['name' => (string) $source->business_name, 'lines' => $this->addressLines($source)]
            : null;

        return [
            'seller' => [
                'name' => (string) ($seller?->business_name ?? $order?->supplying_customer_id ?? $invoice->company?->company_name ?? $invoice->company_id),
                'lines' => $seller !== null ? $this->addressLines($seller) : [],
                'phone' => $this->presentable($seller?->phone_number),
                'email' => $this->presentable($seller?->email_address),
            ],
            'warehouse' => $warehouse,
            'debtor' => [
                'name' => (string) ($invoice->customer?->business_name ?? $invoice->customer_id),
                'lines' => $invoice->customer !== null ? $this->addressLines($invoice->customer) : [],
                'phone' => $this->presentable($invoice->customer?->phone_number),
            ],
            'employee' => [
                'id' => $order?->sales_employee_id,
                'name' => $this->presentable($order?->salesEmployee?->employee_name),
            ],
            'company' => [
                'name' => $this->presentable($invoice->company?->company_name) ?? $invoice->company_id,
            ],
        ];
    }

    /**
     * Postal address lines, empties removed — a party with no address renders
     * no blank rows.
     *
     * @return array<int, string>
     */
    private function addressLines(CustomerMaster $customer): array
    {
        $locality = trim(implode(', ', array_filter([
            $customer->city,
            $customer->state,
            $customer->postal_code,
        ])));

        return collect([
            $customer->address,
            $customer->address2,
            $locality !== '' ? $locality : null,
            $customer->country,
        ])->filter(fn ($line) => is_string($line) && trim($line) !== '')
            ->map(fn (string $line) => trim($line))
            ->values()
            ->all();
    }

    private function presentable(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
