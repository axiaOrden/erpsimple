<?php

namespace App\Services;

use App\Models\PriceCondition;
use App\Models\PriceConditionItem;
use App\Models\ProductMaster;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Recommended-price resolution (rule 8).
 *
 * Applicability: company + product + pricing date (+ optional sales region of
 * the SOLD-TO customer), within the condition's validity window and active.
 *
 * AMBIGUITY POLICY: if several active conditions provide a price for the same
 * product on the same date, and no deterministic precedence rule exists in the
 * business specification, resolution STOPS and reports the candidates. We do
 * not invent precedence (e.g. "latest wins" or "cheapest wins") — the concrete
 * overlapping condition numbers are returned for a business decision.
 */
class PricingService
{
    /**
     * Resolve the recommended price for one product.
     *
     * @return array{price: ?string, currency: string, tax_type: string, condition_price_no: ?string,
     *              ambiguous: bool, candidates: array<int, array{condition_price_no: string, price: string, sales_region: ?string}>}
     */
    public function resolve(ProductMaster $product, Carbon $pricingDate, ?string $salesRegion = null): array
    {
        $candidates = PriceCondition::query()
            ->where('company_id', $product->company_id)
            ->where('active', true)
            ->whereDate('valid_from', '<=', $pricingDate->toDateString())
            ->whereDate('valid_to', '>=', $pricingDate->toDateString())
            ->whereHas('items', fn ($q) => $q->where('product_id', $product->product_id))
            ->where(function ($q) use ($salesRegion) {
                $q->whereNull('sales_region');

                if ($salesRegion !== null && $salesRegion !== '') {
                    $q->orWhere('sales_region', $salesRegion);
                }
            })
            ->orderBy('condition_price_no')
            ->get();

        $rows = [];

        foreach ($candidates as $condition) {
            /** @var PriceConditionItem|null $item */
            $item = $condition->items->firstWhere('product_id', $product->product_id)
                ?? $condition->items()->where('product_id', $product->product_id)->first();

            if ($item === null) {
                continue;
            }

            $rows[] = [
                'condition_price_no' => $condition->condition_price_no,
                'price' => (string) $item->price,
                'currency' => $item->currency,
                'tax_type' => $item->tax_type->value,
                'sales_region' => $condition->sales_region,
            ];
        }

        if ($rows === []) {
            return ['price' => null, 'currency' => 'NGN', 'tax_type' => 'NONE', 'condition_price_no' => null, 'ambiguous' => false, 'candidates' => []];
        }

        if (count($rows) === 1) {
            $only = $rows[0];

            return [
                'price' => $only['price'],
                'currency' => $only['currency'],
                'tax_type' => $only['tax_type'],
                'condition_price_no' => $only['condition_price_no'],
                'ambiguous' => false,
                'candidates' => $rows,
            ];
        }

        // Multiple candidates: ambiguous UNLESS exactly one is region-specific
        // and the others are null-region (region-specific overrides generic —
        // the only deterministic rule the schema implies).
        $regionSpecific = array_values(array_filter($rows, fn ($r) => $r['sales_region'] !== null));
        $generic = array_values(array_filter($rows, fn ($r) => $r['sales_region'] === null));

        if ($salesRegion !== null && $salesRegion !== '' && count($regionSpecific) === 1 && count($generic) >= 0) {
            $specific = $regionSpecific[0];

            return [
                'price' => $specific['price'],
                'currency' => $specific['currency'],
                'tax_type' => $specific['tax_type'],
                'condition_price_no' => $specific['condition_price_no'],
                'ambiguous' => false,
                'candidates' => $rows,
            ];
        }

        return [
            'price' => null,
            'currency' => $rows[0]['currency'],
            'tax_type' => $rows[0]['tax_type'],
            'condition_price_no' => null,
            'ambiguous' => true,
            'candidates' => $rows,
        ];
    }

    /**
     * Resolve prices for many products at once. Ambiguous products get a null
     * price and are listed in `ambiguous` for the caller to surface.
     *
     * @param  Collection<int, ProductMaster>  $products
     * @return array{prices: array<string, array>, ambiguous: array<string, array>}
     */
    public function resolveMany($products, Carbon $pricingDate, ?string $salesRegion = null): array
    {
        $prices = [];
        $ambiguous = [];

        foreach ($products as $product) {
            $result = $this->resolve($product, $pricingDate, $salesRegion);

            if ($result['ambiguous']) {
                $ambiguous[$product->product_id] = $result['candidates'];
            } elseif ($result['price'] !== null) {
                $prices[$product->product_id] = $result;
            }
        }

        return ['prices' => $prices, 'ambiguous' => $ambiguous];
    }
}
