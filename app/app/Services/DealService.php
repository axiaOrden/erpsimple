<?php

namespace App\Services;

use App\Models\DealCondition;
use App\Models\DealQualifier;
use App\Models\DealReward;
use App\Models\ProductMaster;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Trade deal evaluation (rule 9) — always server-side, at draft review and
 * again at authoritative confirmation.
 *
 * Qualifier quantities are normalized to the product's BASIC unit via
 * product_unit_conversion before comparison; rewards are emitted with their
 * configured reward unit/qty. Free lines carry line_source=DEAL,
 * is_free_item=true, parent_item_no, deal_no, unit_price=0.
 *
 * AMBIGUITY POLICY: a single deal may grant each reward once per qualifying
 * multiple (for_each_qty); but if MULTIPLE distinct deals qualify from the
 * same cart, no stacking rule exists in the specification — evaluation stops
 * and reports the qualifying deals rather than inventing a stacking order.
 */
class DealService
{
    public function __construct(private readonly ProductUnitService $units) {}

    /**
     * Evaluate all active deals for the given company against the cart.
     *
     * @param  Collection<int, array{product_id: string, qty: string, unit: string}>  $lines  order lines (order unit quantities)
     * @return array{
     *   rewards: array<int, array{deal_no: string, product_id: string, reward_qty: string, reward_unit: string, parent_product_id: string}>,
     *   qualifying_deals: array<int, string>,
     *   ambiguous: bool
     * }
     */
    public function evaluate(string $companyId, $lines, Carbon $pricingDate): array
    {
        // Normalize each line to basic units of its product.
        $basicQuantities = []; // product_id => basic qty

        foreach ($lines as $line) {
            $product = ProductMaster::find($line['product_id']);

            if ($product === null || $product->company_id !== $companyId) {
                continue;
            }

            $basic = $this->units->toBasic($product, (string) $line['qty'], (string) $line['unit']);
            $basicQuantities[$product->product_id] = ($basicQuantities[$product->product_id] ?? '0')
                + (float) $basic;
        }

        // Active deals valid on the pricing date.
        $deals = DealCondition::query()
            ->where('company_id', $companyId)
            ->where('active', true)
            ->whereDate('valid_from', '<=', $pricingDate->toDateString())
            ->whereDate('valid_to', '>=', $pricingDate->toDateString())
            ->with(['qualifiers.product', 'rewards'])
            ->orderBy('deal_no')
            ->get();

        $qualifying = [];
        $rewards = [];

        foreach ($deals as $deal) {
            $result = $this->evaluateDeal($deal, $basicQuantities);

            if ($result['qualified']) {
                $qualifying[] = $deal->deal_no;

                foreach ($result['rewards'] as $reward) {
                    $rewards[] = $reward + ['deal_no' => $deal->deal_no];
                }
            }
        }

        // Ambiguity: more than one distinct deal qualified. No stacking rule
        // exists — report instead of choosing.
        $ambiguous = count($qualifying) > 1;

        return [
            'rewards' => $rewards,
            'qualifying_deals' => $qualifying,
            'ambiguous' => $ambiguous,
        ];
    }

    /**
     * Evaluate one deal against basic-unit quantities.
     *
     * @param  array<string, float>  $basicQuantities
     * @return array{qualified: bool, rewards: array<int, array{product_id: string, reward_qty: string, reward_unit: string, parent_product_id: string}>}
     */
    private function evaluateDeal(DealCondition $deal, array $basicQuantities): array
    {
        $qualifiers = $deal->qualifiers;

        if ($qualifiers->isEmpty() || $deal->rewards->isEmpty()) {
            return ['qualified' => false, 'rewards' => []];
        }

        // Every qualifier product must meet its minimum (all-AND semantics —
        // the schema's PK (deal_no, product_id) implies AND, not OR).
        $multiples = [];

        foreach ($qualifiers as $qualifier) {
            /** @var DealQualifier $qualifier */
            $available = $basicQuantities[$qualifier->product_id] ?? 0.0;
            $required = $this->units->toBasicById(
                $qualifier->product_id,
                (string) $qualifier->minimum_qty,
                (string) $qualifier->qualifier_unit,
            );

            if ((float) $required <= 0 || $available < (float) $required) {
                return ['qualified' => false, 'rewards' => []];
            }

            // How many times the minimum is met (floor), used for for_each.
            $multiples[$qualifier->product_id] = (int) floor($available / (float) $required);
        }

        // Rewards: for_each multiples apply when configured.
        $rewards = [];

        foreach ($deal->rewards as $reward) {
            /** @var DealReward $reward */
            $times = 1;

            if ($reward->for_each_qty !== null && (float) $reward->for_each_qty > 0) {
                $parentProductId = $qualifiers->first()->product_id;
                $forEachBasic = $this->units->toBasicById(
                    $parentProductId,
                    (string) $reward->for_each_qty,
                    (string) ($reward->for_each_unit ?? $qualifiers->first()->qualifier_unit),
                );

                $times = $forEachBasic > 0
                    ? max(1, (int) floor(($basicQuantities[$parentProductId] ?? 0.0) / (float) $forEachBasic))
                    : 1;
            }

            for ($i = 0; $i < $times; $i++) {
                $rewards[] = [
                    'product_id' => $reward->product_id,
                    'reward_qty' => (string) $reward->reward_qty,
                    'reward_unit' => $reward->reward_unit,
                    // The manual line that triggered the reward.
                    'parent_product_id' => $qualifiers->first()->product_id,
                ];
            }
        }

        return ['qualified' => true, 'rewards' => $rewards];
    }
}
