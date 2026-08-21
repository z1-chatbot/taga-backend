<?php

namespace Tests\Feature;

use App\Models\Product;
use Tests\TestCase;

/**
 * Catalogue browsing: filtering, sorting and pagination.
 *
 * The sort tests are regressions. `sort_by` was passed straight to orderBy(),
 * so any unrecognised value — a stale bookmark, a mistyped link, a crawler —
 * returned a 500 for the whole listing. `per_page` was unbounded.
 */
class CatalogueTest extends TestCase
{
    public function test_an_unknown_sort_falls_back_instead_of_erroring(): void
    {
        Product::factory()->count(3)->create();

        $this->getJson('/api/v1/products?sort_by=not_a_real_column')->assertOk();
    }

    public function test_a_sort_injection_attempt_does_not_error(): void
    {
        Product::factory()->create();

        $this->getJson('/api/v1/products?sort_by='.urlencode('price; DROP TABLE products'))
            ->assertOk();

        // The table is still there.
        $this->assertGreaterThan(0, Product::count());
    }

    public function test_an_invalid_sort_direction_does_not_error(): void
    {
        Product::factory()->create();

        $this->getJson('/api/v1/products?sort_by=price&sort_order=sideways')->assertOk();
    }

    public function test_per_page_is_clamped(): void
    {
        Product::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/products?per_page=100000')->assertOk();

        $this->assertLessThanOrEqual(100, $response->json('data.per_page'));
    }

    public function test_price_sorting_is_ascending_when_asked(): void
    {
        Product::factory()->create(['price' => 5000]);
        Product::factory()->create(['price' => 100]);
        Product::factory()->create(['price' => 2000]);

        $response = $this->getJson('/api/v1/products?sort_by=price_low_high&per_page=50')->assertOk();

        $prices = collect($response->json('data.data'))->pluck('price')->map(fn ($p) => (float) $p)->all();

        $sorted = $prices;
        sort($sorted);

        $this->assertSame($sorted, $prices);
    }

    public function test_the_prescription_filter_selects_only_rx_items(): void
    {
        Product::factory()->prescriptionOnly()->create();
        Product::factory()->count(2)->create();

        $response = $this->getJson('/api/v1/products?requires_prescription=1&per_page=50')->assertOk();

        $flags = collect($response->json('data.data'))->pluck('requires_prescription');

        $this->assertGreaterThan(0, $flags->count());

        foreach ($flags as $flag) {
            $this->assertTrue((bool) $flag);
        }
    }

    public function test_search_matches_on_name(): void
    {
        Product::factory()->create(['name' => 'Amoxicillin 250mg Capsules']);
        Product::factory()->create(['name' => 'Bandage Roll']);

        $response = $this->getJson('/api/v1/products?search=Amoxicillin&per_page=50')->assertOk();

        $names = collect($response->json('data.data'))->pluck('name');

        $this->assertTrue($names->contains(fn ($n) => str_contains($n, 'Amoxicillin')));
        $this->assertFalse($names->contains('Bandage Roll'));
    }

    public function test_a_single_product_is_readable(): void
    {
        $product = Product::factory()->create();

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            // The single-product endpoint nests the record under data.product
            // alongside data.related_products.
            ->assertJsonPath('data.product.id', $product->id);
    }

    public function test_a_missing_product_returns_404(): void
    {
        $this->getJson('/api/v1/products/99999999')->assertStatus(404);
    }

    public function test_the_rx_flag_is_exposed_to_the_storefront(): void
    {
        // The storefront renders its "Rx only" badge from this field, so it has
        // to survive the API's response transform.
        $product = Product::factory()->prescriptionOnly()->create();

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.product.requires_prescription', true);
    }
}
