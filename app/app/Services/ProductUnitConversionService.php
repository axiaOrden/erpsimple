<?php

namespace App\Services;

use App\Models\ProductMaster;
use App\Models\ProductUnitConversion;
use App\Models\UnitMaster;
use Illuminate\Support\Facades\DB;

/**
 * Manages product_unit_conversion rows for one product.
 *
 * Formula: alternative qty × numerator / denominator = basic qty
 * (1 CTN = 24 PCS for a PCS-based product ⇒ numerator=24, denominator=1)
 */
class ProductUnitConversionService
{
    /**
     * Replace the product's conversions with the given set (atomic).
     *
     * @param  array<int, array{alternative_unit: string, numerator: numeric, denominator: numeric}>  $conversions
     * @return list<string> error messages (empty = success)
     */
    public function syncConversions(ProductMaster $product, array $conversions): array
    {
        $errors = $this->validate($product, $conversions);

        if ($errors !== []) {
            return $errors;
        }

        DB::transaction(function () use ($product, $conversions) {
            ProductUnitConversion::where('product_id', $product->product_id)->delete();

            foreach ($conversions as $conversion) {
                ProductUnitConversion::create([
                    'product_id' => $product->product_id,
                    'alternative_unit' => $conversion['alternative_unit'],
                    'numerator' => $conversion['numerator'],
                    'denominator' => $conversion['denominator'],
                ]);
            }
        });

        return [];
    }

    /**
     * @param  array<int, array{alternative_unit: string, numerator: numeric, denominator: numeric}>  $conversions
     * @return list<string>
     */
    public function validate(ProductMaster $product, array $conversions): array
    {
        $errors = [];
        $seenUnits = [];

        foreach ($conversions as $index => $conversion) {
            $unit = strtoupper(trim((string) ($conversion['alternative_unit'] ?? '')));
            $numerator = $conversion['numerator'] ?? null;
            $denominator = $conversion['denominator'] ?? null;
            $label = 'Alternative unit #'.($index + 1);

            if ($unit === '') {
                $errors[] = "$label: unit is required.";

                continue;
            }

            if (! UnitMaster::where('unit_code', $unit)->exists()) {
                $errors[] = "$label: unit '$unit' does not exist.";

                continue;
            }

            if ($unit === $product->basic_unit) {
                $errors[] = "$label: '$unit' is the product's basic unit and cannot be an alternative.";

                continue;
            }

            if (isset($seenUnits[$unit])) {
                $errors[] = "$label: duplicate alternative unit '$unit'.";

                continue;
            }

            $seenUnits[$unit] = true;

            if (! is_numeric($numerator) || (float) $numerator <= 0) {
                $errors[] = "$label: numerator must be greater than 0.";
            }

            if (! is_numeric($denominator) || (float) $denominator <= 0) {
                $errors[] = "$label: denominator must be greater than 0.";
            }
        }

        return $errors;
    }
}
