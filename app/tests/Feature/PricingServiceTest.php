<?php

namespace Tests\Feature;

use App\Models\CompanyMaster;
use App\Models\PriceCondition;
use App\Models\PriceConditionItem;
use App\Models\ProductMaster;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PricingServiceTest extends TestCase
{
    use DatabaseTransactions;

    private CompanyMaster $company;

    private ProductMaster $product;

    private PricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyMaster::factory()->create();
        $this->product = ProductMaster::factory()->forCompany($this->company)->create(['basic_unit' => 'PCS']);
        $this->pricing = app(PricingService::class);
    }

    private function makeCondition(string $no, string $from, string $to, array $items, ?string $region = null): PriceCondition
    {
        $condition = PriceCondition::create([
            'condition_price_no' => $no,
            'company_id' => $this->company->company_id,
            'sales_region' => $region,
            'valid_from' => $from,
            'valid_to' => $to,
            'active' => true,
        ]);

        foreach ($items as $productId => $price) {
            PriceConditionItem::create([
                'condition_price_no' => $no,
                'product_id' => $productId,
                'price' => $price,
                'currency' => 'NGN',
                'tax_type' => 'OUTPUT_TAX',
            ]);
        }

        return $condition;
    }

    public function test_resolves_single_applicable_condition(): void
    {
        $this->makeCondition('PC-1', '2026-01-01', '2026-12-31', [$this->product->product_id => '500.00']);

        $result = $this->pricing->resolve($this->product, Carbon::parse('2026-06-15'));

        $this->assertFalse($result['ambiguous']);
        $this->assertSame('500.00', $result['price']);
        $this->assertSame('PC-1', $result['condition_price_no']);
    }

    public function test_expired_condition_is_not_applicable(): void
    {
        $this->makeCondition('PC-EXP', '2025-01-01', '2025-12-31', [$this->product->product_id => '500.00']);

        $result = $this->pricing->resolve($this->product, Carbon::parse('2026-06-15'));

        $this->assertNull($result['price']);
        $this->assertFalse($result['ambiguous']);
    }

    public function test_inactive_condition_is_not_applicable(): void
    {
        $condition = $this->makeCondition('PC-INACT', '2026-01-01', '2026-12-31', [$this->product->product_id => '500.00']);
        $condition->update(['active' => false]);

        $result = $this->pricing->resolve($this->product, Carbon::parse('2026-06-15'));

        $this->assertNull($result['price']);
    }

    public function test_two_generic_conditions_same_date_is_ambiguous(): void
    {
        $this->makeCondition('PC-A', '2026-01-01', '2026-12-31', [$this->product->product_id => '500.00']);
        $this->makeCondition('PC-B', '2026-06-01', '2026-12-31', [$this->product->product_id => '550.00']);

        $result = $this->pricing->resolve($this->product, Carbon::parse('2026-06-15'));

        $this->assertTrue($result['ambiguous'], 'Two equally generic conditions must be reported, not guessed.');
        $this->assertNull($result['price']);
        $this->assertCount(2, $result['candidates']);
    }

    public function test_region_specific_overrides_generic(): void
    {
        $this->makeCondition('PC-GEN', '2026-01-01', '2026-12-31', [$this->product->product_id => '500.00']);
        $this->makeCondition('PC-SW', '2026-01-01', '2026-12-31', [$this->product->product_id => '520.00'], 'SW');

        $result = $this->pricing->resolve($this->product, Carbon::parse('2026-06-15'), 'SW');

        $this->assertFalse($result['ambiguous']);
        $this->assertSame('520.00', $result['price']);
        $this->assertSame('PC-SW', $result['condition_price_no']);
    }

    public function test_two_region_conditions_same_region_is_ambiguous(): void
    {
        $this->makeCondition('PC-SW1', '2026-01-01', '2026-12-31', [$this->product->product_id => '520.00'], 'SW');
        $this->makeCondition('PC-SW2', '2026-01-01', '2026-12-31', [$this->product->product_id => '530.00'], 'SW');

        $result = $this->pricing->resolve($this->product, Carbon::parse('2026-06-15'), 'SW');

        $this->assertTrue($result['ambiguous']);
    }

    public function test_other_company_condition_is_ignored(): void
    {
        $otherProduct = ProductMaster::factory()->create(['basic_unit' => 'PCS']); // creates its own company

        PriceCondition::create([
            'condition_price_no' => 'PC-OTHER',
            'company_id' => $otherProduct->company_id,
            'sales_region' => null,
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'active' => true,
        ]);
        PriceConditionItem::create([
            'condition_price_no' => 'PC-OTHER',
            'product_id' => $this->product->product_id,
            'price' => '999.00',
            'currency' => 'NGN',
            'tax_type' => 'OUTPUT_TAX',
        ]);

        $result = $this->pricing->resolve($this->product, Carbon::parse('2026-06-15'));

        $this->assertNull($result['price'], 'A condition of another company must never apply.');
    }

    public function test_pricing_date_outside_validity_is_ignored(): void
    {
        $this->makeCondition('PC-JAN', '2026-01-01', '2026-01-31', [$this->product->product_id => '480.00']);

        $result = $this->pricing->resolve($this->product, Carbon::parse('2026-06-15'));

        $this->assertNull($result['price']);
    }
}
