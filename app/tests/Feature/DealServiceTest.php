<?php

namespace Tests\Feature;

use App\Models\CompanyMaster;
use App\Models\DealCondition;
use App\Models\DealQualifier;
use App\Models\DealReward;
use App\Models\ProductMaster;
use App\Models\ProductUnitConversion;
use App\Services\DealService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class DealServiceTest extends TestCase
{
    use DatabaseTransactions;

    private CompanyMaster $company;

    private ProductMaster $productA; // basic PCS, CTN = 24 PCS

    private ProductMaster $productB;

    private DealService $deals;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyMaster::factory()->create();

        $this->productA = ProductMaster::factory()->forCompany($this->company)->create(['basic_unit' => 'PCS']);
        ProductUnitConversion::create([
            'product_id' => $this->productA->product_id,
            'alternative_unit' => 'CTN',
            'numerator' => '24',
            'denominator' => '1',
        ]);

        $this->productB = ProductMaster::factory()->forCompany($this->company)->create(['basic_unit' => 'PCS']);

        $this->deals = app(DealService::class);
    }

    private function makeDeal(string $no, string $qualifierQty, string $qualifierUnit, string $rewardQty, string $rewardUnit, ?string $forEachQty = null, ?string $forEachUnit = null): DealCondition
    {
        $deal = DealCondition::create([
            'deal_no' => $no,
            'company_id' => $this->company->company_id,
            'deal_description' => "Buy $qualifierQty $qualifierUnit A get $rewardQty $rewardUnit B",
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'active' => true,
        ]);

        DealQualifier::create([
            'deal_no' => $no,
            'product_id' => $this->productA->product_id,
            'minimum_qty' => $qualifierQty,
            'qualifier_unit' => $qualifierUnit,
        ]);

        DealReward::create([
            'deal_no' => $no,
            'product_id' => $this->productB->product_id,
            'reward_qty' => $rewardQty,
            'reward_unit' => $rewardUnit,
            'for_each_qty' => $forEachQty,
            'for_each_unit' => $forEachUnit,
        ]);

        return $deal;
    }

    private function cart(string $qty, string $unit): Collection
    {
        return collect([
            ['product_id' => $this->productA->product_id, 'qty' => $qty, 'unit' => $unit],
        ]);
    }

    public function test_qualifier_respects_unit_conversion(): void
    {
        // Deal: buy 10 CTN A (240 PCS) get 1 CTN B.
        $this->makeDeal('D-CTN', '10', 'CTN', '1', 'CTN');

        // Cart: 9 CTN = 216 PCS — below 240, no qualification.
        $result = $this->deals->evaluate($this->company->company_id, $this->cart('9', 'CTN'), Carbon::parse('2026-06-15'));
        $this->assertFalse($result['ambiguous']);
        $this->assertCount(0, $result['rewards']);

        // Cart: 10 CTN = 240 PCS — qualifies.
        $result = $this->deals->evaluate($this->company->company_id, $this->cart('10', 'CTN'), Carbon::parse('2026-06-15'));
        $this->assertCount(1, $result['rewards']);
        $this->assertSame($this->productB->product_id, $result['rewards'][0]['product_id']);
        $this->assertSame('1.000', (string) $result['rewards'][0]['reward_qty']);
        $this->assertSame('CTN', $result['rewards'][0]['reward_unit']);
        $this->assertSame('D-CTN', $result['rewards'][0]['deal_no']);
    }

    public function test_qualifier_in_basic_unit_matches(): void
    {
        // Deal minimum: 240 PCS.
        $this->makeDeal('D-PCS', '240', 'PCS', '1', 'CTN');

        // 10 CTN converts to 240 PCS — matches.
        $result = $this->deals->evaluate($this->company->company_id, $this->cart('10', 'CTN'), Carbon::parse('2026-06-15'));
        $this->assertCount(1, $result['rewards']);
    }

    public function test_for_each_multiples_grant_multiple_rewards(): void
    {
        // Every 5 CTN A → 1 CTN B.
        $this->makeDeal('D-EACH', '5', 'CTN', '1', 'CTN', '5', 'CTN');

        // 11 CTN = 2 full multiples of 5 → 2 free CTN of B.
        $result = $this->deals->evaluate($this->company->company_id, $this->cart('11', 'CTN'), Carbon::parse('2026-06-15'));
        $this->assertCount(2, $result['rewards']);
    }

    public function test_expired_or_inactive_deals_are_ignored(): void
    {
        $deal = $this->makeDeal('D-OLD', '1', 'PCS', '1', 'PCS');
        $deal->update(['active' => false]);

        $result = $this->deals->evaluate($this->company->company_id, $this->cart('10', 'PCS'), Carbon::parse('2026-06-15'));
        $this->assertCount(0, $result['rewards']);

        $deal->update(['active' => true, 'valid_from' => '2027-01-01', 'valid_to' => '2027-12-31']);

        $result = $this->deals->evaluate($this->company->company_id, $this->cart('10', 'PCS'), Carbon::parse('2026-06-15'));
        $this->assertCount(0, $result['rewards']);
    }

    public function test_multiple_qualifying_deals_are_reported_ambiguous(): void
    {
        // Two different deals both qualifying for the same cart.
        $this->makeDeal('D-1', '10', 'PCS', '1', 'PCS');
        $this->makeDeal('D-2', '5', 'PCS', '2', 'PCS');

        $result = $this->deals->evaluate($this->company->company_id, $this->cart('10', 'PCS'), Carbon::parse('2026-06-15'));

        $this->assertTrue($result['ambiguous'], 'No stacking rule exists — ambiguity must be reported.');
        $this->assertContains('D-1', $result['qualifying_deals']);
        $this->assertContains('D-2', $result['qualifying_deals']);
    }

    public function test_deals_of_other_company_are_ignored(): void
    {
        $otherCompany = CompanyMaster::factory()->create();
        $otherProduct = ProductMaster::factory()->forCompany($otherCompany)->create(['basic_unit' => 'PCS']);

        $deal = DealCondition::create([
            'deal_no' => 'D-FOREIGN',
            'company_id' => $otherCompany->company_id,
            'deal_description' => 'foreign',
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'active' => true,
        ]);
        DealQualifier::create([
            'deal_no' => 'D-FOREIGN',
            'product_id' => $this->productA->product_id,
            'minimum_qty' => '1',
            'qualifier_unit' => 'PCS',
        ]);
        DealReward::create([
            'deal_no' => 'D-FOREIGN',
            'product_id' => $otherProduct->product_id,
            'reward_qty' => '1',
            'reward_unit' => 'PCS',
            'for_each_qty' => null,
            'for_each_unit' => null,
        ]);

        $result = $this->deals->evaluate($this->company->company_id, $this->cart('10', 'PCS'), Carbon::parse('2026-06-15'));
        $this->assertCount(0, $result['rewards']);
        $this->assertFalse($result['ambiguous']);
    }

    public function test_missing_conversion_throws_rather_than_guessing(): void
    {
        // Deal minimum in TON, but product has no TON conversion.
        $this->makeDeal('D-TON', '1', 'TON', '1', 'PCS');

        $this->expectException(\InvalidArgumentException::class);

        $this->deals->evaluate($this->company->company_id, $this->cart('10', 'PCS'), Carbon::parse('2026-06-15'));
    }
}
