<?php

namespace App\Services;

use App\Models\ProductMaster;
use App\Models\ProductUnitConversion;

/**
 * Unit conversion (rule 7): alternative qty × numerator / denominator = basic qty.
 * All internal stock/qualifier math uses the product's basic unit.
 */
class ProductUnitService
{
    /** Convert a quantity in the given unit to the product's basic unit. */
    public function toBasic(ProductMaster $product, string $qty, string $unit): string
    {
        $qtyFloat = (float) $qty;

        if ($unit === $product->basic_unit) {
            return $this->format($qtyFloat);
        }

        $conversion = ProductUnitConversion::where('product_id', $product->product_id)
            ->where('alternative_unit', $unit)
            ->first();

        if ($conversion === null) {
            // No conversion defined: the quantity cannot be interpreted —
            // callers must treat this as a validation error, not guess.
            throw new \InvalidArgumentException(
                "No conversion defined for product {$product->product_id} from unit '$unit' to '{$product->basic_unit}'.",
            );
        }

        return $this->format($qtyFloat * (float) $conversion->numerator / (float) $conversion->denominator);
    }

    /** Convert by product id (for qualifier rows that carry only ids). */
    public function toBasicById(string $productId, string $qty, string $unit): string
    {
        $product = ProductMaster::find($productId);

        if ($product === null) {
            throw new \InvalidArgumentException("Unknown product '$productId'.");
        }

        return $this->toBasic($product, $qty, $unit);
    }

    /** Convert a basic-unit quantity into the given (alternative) unit. */
    public function fromBasic(ProductMaster $product, string $basicQty, string $unit): string
    {
        $basicFloat = (float) $basicQty;

        if ($unit === $product->basic_unit) {
            return $this->format($basicFloat);
        }

        $conversion = ProductUnitConversion::where('product_id', $product->product_id)
            ->where('alternative_unit', $unit)
            ->first();

        if ($conversion === null) {
            throw new \InvalidArgumentException(
                "No conversion defined for product {$product->product_id} from '{$product->basic_unit}' to unit '$unit'.",
            );
        }

        if ((float) $conversion->numerator == 0) {
            throw new \InvalidArgumentException('Invalid conversion (zero numerator).');
        }

        return $this->format($basicFloat * (float) $conversion->denominator / (float) $conversion->numerator);
    }

    /** Convert a basic-unit quantity into a unit by product id. */
    public function fromBasicById(string $productId, string $basicQty, string $unit): string
    {
        $product = ProductMaster::find($productId);

        if ($product === null) {
            throw new \InvalidArgumentException("Unknown product '$productId'.");
        }

        return $this->fromBasic($product, $basicQty, $unit);
    }

    private function format(float $value): string
    {
        return number_format(round($value, 3), 3, '.', '');
    }
}
