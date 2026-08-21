<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Support\Slug;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Category slugs, and the promise that changing how they are built does not
 * break the links already in the wild.
 *
 * `Str::slug()` deletes a character it does not recognise instead of treating
 * it as a separator, so "Cough/Cold/Flu" became "coughcoldflu". App\Support\Slug
 * separates the joining characters — and Category::findBySlugOrId() still
 * answers to the old form, so an existing bookmark or indexed URL survives.
 */
class CategorySlugTest extends TestCase
{
    private function adminHeaders(): array
    {
        return $this->tokenFor($this->makeUser(['role' => 'admin']));
    }

    private function makeCategory(array $attributes = []): Category
    {
        return Category::create(array_merge([
            'name' => 'Test Category',
            'slug' => 'test-category-'.uniqid(),
            'parent_id' => null,
            'depth' => 0,
            'product_type' => 'general',
            'requires_prescription' => false,
            'is_controlled_substance' => false,
            'is_active' => true,
            'sort_order' => 0,
        ], $attributes));
    }

    public function test_joining_characters_become_hyphens(): void
    {
        $this->assertSame('cough-cold-flu', Slug::make('Cough/Cold/Flu'));
        $this->assertSame('respiratory-asthma-copd', Slug::make('Respiratory (Asthma/COPD)'));
        $this->assertSame('eye-ear-nose-care', Slug::make('Eye/Ear/Nose Care'));
    }

    public function test_names_that_already_slugged_correctly_are_unchanged(): void
    {
        // The spaces around "&" were always doing the separating, so these must
        // come out exactly as they did before — this is the half of the change
        // that must not move.
        $this->assertSame('mother-child-care', Slug::make('Mother & Child Care'));
        $this->assertSame('womens-health', Slug::make("Women's Health"));
        $this->assertSame('prescription-medicines-rx', Slug::make('Prescription Medicines (Rx)'));
        $this->assertSame('vitamins-supplements', Slug::make('Vitamins & Supplements'));
    }

    public function test_a_new_category_gets_a_hyphenated_slug(): void
    {
        $response = $this->postJson('/api/v1/admin/categories', [
            'name' => 'Nose/Throat Care',
            'product_type' => 'general',
        ], $this->adminHeaders())->assertStatus(201);

        $this->assertSame('nose-throat-care', $response->json('data.slug'));
    }

    public function test_a_link_using_the_old_slug_still_resolves(): void
    {
        $category = $this->makeCategory(['name' => 'Ear/Nose Care', 'slug' => 'ear-nose-care']);

        // What a link built before the rule changed would carry.
        $this->getJson('/api/v1/categories/earnosecare')
            ->assertOk()
            ->assertJsonPath('data.id', $category->id);

        $this->getJson('/api/v1/categories/ear-nose-care')
            ->assertOk()
            ->assertJsonPath('data.id', $category->id);
    }

    public function test_an_exact_slug_always_wins_over_the_fallback(): void
    {
        $compact = $this->makeCategory(['name' => 'Compact', 'slug' => 'abcdef']);
        $hyphenated = $this->makeCategory(['name' => 'Hyphenated', 'slug' => 'abc-def']);

        $this->assertSame($compact->id, Category::findBySlugOrId('abcdef')?->id);
        $this->assertSame($hyphenated->id, Category::findBySlugOrId('abc-def')?->id);
    }

    public function test_an_unknown_slug_still_resolves_to_nothing(): void
    {
        $this->assertNull(Category::findBySlugOrId('no-such-category-anywhere'));

        $this->getJson('/api/v1/categories/no-such-category-anywhere')->assertStatus(404);

        // Not "everything": an unknown filter must not quietly widen the results.
        $this->getJson('/api/v1/products?category=no-such-category-anywhere')
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    }

    public function test_an_inactive_category_is_not_reachable_by_either_form(): void
    {
        $this->makeCategory(['name' => 'Hidden/Branch', 'slug' => 'hidden-branch', 'is_active' => false]);

        // The fallback must not become a way around the active check.
        $this->getJson('/api/v1/categories/hidden-branch')->assertStatus(404);
        $this->getJson('/api/v1/categories/hiddenbranch')->assertStatus(404);
    }

    public function test_products_filter_by_either_form_of_the_slug(): void
    {
        $category = $this->makeCategory(['name' => 'Chest/Lung', 'slug' => 'chest-lung']);

        $product = \App\Models\Product::factory()->create([
            'category_id' => $category->id,
            'is_active' => true,
        ]);

        foreach (['chest-lung', 'chestlung'] as $slug) {
            $this->getJson("/api/v1/products?category={$slug}")
                ->assertOk()
                ->assertJsonPath('data.total', 1)
                ->assertJsonPath('data.data.0.id', $product->id);
        }
    }

    /** The data migration itself, run against fixtures inside the test transaction. */
    private function runSlugMigration(): void
    {
        $migration = require base_path('database/migrations/2026_08_21_000002_hyphenate_category_slugs.php');

        $migration->up();
    }

    public function test_the_migration_carries_slug_references_with_it(): void
    {
        // Coupons, sale events and pricing rules point at a category by slug,
        // not by id, so a rename that ignored them would silently stop them
        // matching — a discount that quietly no longer applies.
        $category = $this->makeCategory(['name' => 'Throat/Chest', 'slug' => 'throatchest']);

        DB::table('pricing_configurations')->insert([
            'name' => 'slug-ref-test',
            'category' => 'throatchest',
            'type' => 'percentage',
            'value' => 10,
            'is_active' => true,
            'priority' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $couponId = DB::table('coupons')->insertGetId([
            'name' => 'Slug ref test',
            'code' => 'SLUGREFTEST',
            'type' => 'percentage',
            'value' => 5,
            'applicable_to' => 'specific_categories',
            'applicable_ids' => json_encode(['throatchest', 7]),
            'is_active' => true,
            'valid_from' => now(),
            'valid_until' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runSlugMigration();

        $this->assertSame('throat-chest', Category::find($category->id)->slug);
        $this->assertSame(
            'throat-chest',
            DB::table('pricing_configurations')->where('name', 'slug-ref-test')->value('category')
        );
        // The product id sharing that column is left alone.
        $this->assertSame(
            ['throat-chest', 7],
            json_decode(DB::table('coupons')->where('id', $couponId)->value('applicable_ids'), true)
        );
    }

    public function test_the_migration_leaves_a_deliberately_qualified_slug_alone(): void
    {
        // What the seeder produces when the plain form is already taken. It is
        // not what the old algorithm would have given this name, so the
        // migration must not treat it as its own to rewrite.
        $category = $this->makeCategory(['name' => 'Nose/Ear', 'slug' => 'some-branch-nose-ear']);

        $this->runSlugMigration();

        $this->assertSame('some-branch-nose-ear', Category::find($category->id)->slug);
    }

    public function test_the_migration_skips_a_slug_another_category_already_holds(): void
    {
        $incumbent = $this->makeCategory(['name' => 'Hand Care', 'slug' => 'hand-care']);
        $clashing = $this->makeCategory(['name' => 'Hand/Care', 'slug' => 'handcare']);

        $this->runSlugMigration();

        $this->assertSame('hand-care', Category::find($incumbent->id)->slug);
        $this->assertSame('handcare', Category::find($clashing->id)->slug);
    }
}
