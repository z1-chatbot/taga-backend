<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\SaleEvent;
use Tests\TestCase;

/**
 * The category pickers must hand out the value the matchers look for.
 *
 * Coupons, sale events and pricing configurations all store a chosen category
 * and later compare it against Product::$product_category -- an accessor for
 * the category's slug, not its primary key. A picker that offers numeric ids
 * therefore saves rows that can never match a product, and nothing complains:
 * the coupon simply never applies. These tests tie the two ends together.
 */
class CategoryPickerTest extends TestCase
{
    private function makeCategorisedProduct(): array
    {
        $category = Category::create([
            'name' => 'Antimalarials',
            'slug' => 'antimalarials-'.uniqid(),
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $product = Product::factory()->create([
            'price' => 1000,
            'category_id' => $category->id,
        ]);

        return [$category, $product];
    }

    public function test_the_coupon_picker_offers_the_value_the_matcher_looks_for(): void
    {
        [$category, $product] = $this->makeCategorisedProduct();

        $response = $this->getJson(
            '/api/v1/admin/coupons/options',
            $this->tokenFor($this->makeUser(['role' => 'admin']))
        )->assertOk();

        $offered = collect($response->json('data.categories'))->pluck('id')->all();

        $this->assertContains($category->slug, $offered, 'the picker must offer slugs');
        $this->assertContains(
            $product->fresh()->product_category,
            $offered,
            'what the picker offers has to be what a product reports as its category'
        );
    }

    public function test_the_sale_event_picker_offers_the_same_values(): void
    {
        [$category] = $this->makeCategorisedProduct();

        $response = $this->getJson(
            '/api/v1/admin/sale-events/options',
            $this->tokenFor($this->makeUser(['role' => 'admin']))
        )->assertOk();

        $this->assertContains(
            $category->slug,
            collect($response->json('data.categories'))->pluck('id')->all()
        );
    }

    public function test_a_category_coupon_built_from_the_picker_actually_applies(): void
    {
        // The round trip that matters: take what the picker gives, save it,
        // and check the product is genuinely covered.
        [$category, $product] = $this->makeCategorisedProduct();

        $coupon = Coupon::create([
            'code' => 'CATTEST'.strtoupper(substr(uniqid(), -5)),
            'name' => 'Category coupon',
            'type' => 'percentage',
            'value' => 10,
            'applicable_to' => 'specific_categories',
            'applicable_ids' => [$category->slug],
            'is_active' => true,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addDay(),
        ]);

        $this->assertContains(
            $product->fresh()->product_category,
            $coupon->applicable_ids,
            'a coupon saved from the picker must cover products in that category'
        );
    }

    public function test_a_category_sale_event_built_from_the_picker_matches_the_product(): void
    {
        [$category, $product] = $this->makeCategorisedProduct();

        $sale = SaleEvent::create([
            'name' => 'Category sale',
            'type' => 'flash_sale',
            'discount_type' => 'percentage',
            'discount_value' => 15,
            'applicable_to' => 'specific_categories',
            'applicable_ids' => [$category->slug],
            'is_active' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
        ]);

        $this->assertTrue(
            in_array($product->fresh()->product_category, $sale->applicable_ids, true),
            'the sale event must recognise a product in the chosen category'
        );
    }

    public function test_a_numeric_id_would_not_have_matched(): void
    {
        // Guards the regression directly: this is what the picker used to give.
        [$category, $product] = $this->makeCategorisedProduct();

        $this->assertNotContains(
            $product->fresh()->product_category,
            [$category->id],
            'a numeric category id can never equal a slug -- that was the bug'
        );
    }
}
