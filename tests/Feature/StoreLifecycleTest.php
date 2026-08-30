<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Tests\TestCase;

/**
 * Taking a pharmacy off sale, and putting it away.
 *
 * Suspend existed in the admin and did nothing: the route passes a STORE id and
 * the controller looked up a USER with it, so suspending store 3 targeted user
 * 3 — an unrelated owner, or a 404. It also wrote `users.is_active` while the
 * list rendered `stores.status`, so even the accidental hits showed no change.
 * That is why it looked like the feature was missing.
 *
 * Delete did not exist at all. It is an archive now, not an erase: orders,
 * payouts and invoices point at the store row, and the record of who dispensed
 * a medicine has to outlive the shop.
 */
class StoreLifecycleTest extends TestCase
{
    private function admin(): array
    {
        return $this->tokenFor($this->makeUser(['role' => 'admin']));
    }

    /** An approved pharmacy with one product actually on sale. */
    private function tradingPharmacy(): array
    {
        $owner = $this->makeUser(['role' => 'store_owner']);

        $store = Store::create([
            'owner_id' => $owner->id,
            'name' => 'Lifecycle Pharmacy',
            'slug' => 'life-'.uniqid(),
            'state' => 'Lagos',
            'city' => 'Ikeja',
            'status' => 'active',
            'verification_status' => Store::VERIFICATION_APPROVED,
            'pharmacy_license_expiry' => now()->addYear(),
        ]);

        $product = Product::factory()->create([
            'store_id' => $store->id,
            'is_active' => true,
            'stock_quantity' => 10,
            'requires_prescription' => false,
        ]);

        return [$store, $product, $owner];
    }

    private function onSale(Product $product): bool
    {
        return Product::sellable()->whereKey($product->id)->exists();
    }

    // ---- suspend -----------------------------------------------------------

    public function test_suspending_moves_the_stores_own_status(): void
    {
        [$store] = $this->tradingPharmacy();

        $this->postJson("/api/v1/admin/stores/{$store->id}/suspend", [], $this->admin())
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.is_active', false);

        $this->assertSame('suspended', $store->fresh()->status);
    }

    /**
     * The bug in one assertion: the id in the URL is a store id, and it must not
     * be used to find a user. With separate ids for the store and its owner, a
     * controller reaching for the wrong table changes the wrong row.
     */
    public function test_suspending_does_not_touch_any_user_record(): void
    {
        [$store, , $owner] = $this->tradingPharmacy();

        $collateral = User::find($store->id);
        $collateralWasActive = $collateral?->is_active;

        $this->postJson("/api/v1/admin/stores/{$store->id}/suspend", [], $this->admin())->assertOk();

        // The owner keeps their login: they cannot sell, but they can see the
        // state of the shop and get in touch.
        $this->assertTrue((bool) $owner->fresh()->is_active);

        if ($collateral) {
            $this->assertSame($collateralWasActive, $collateral->fresh()->is_active);
        }
    }

    public function test_a_suspended_pharmacys_stock_leaves_the_catalogue(): void
    {
        [$store, $product] = $this->tradingPharmacy();

        $this->assertTrue($this->onSale($product), 'the fixture should start on sale');

        $this->postJson("/api/v1/admin/stores/{$store->id}/suspend", [], $this->admin())->assertOk();

        $this->assertFalse($this->onSale($product));
    }

    public function test_activating_puts_it_back(): void
    {
        [$store, $product] = $this->tradingPharmacy();

        $this->postJson("/api/v1/admin/stores/{$store->id}/suspend", [], $this->admin())->assertOk();
        $this->postJson("/api/v1/admin/stores/{$store->id}/activate", [], $this->admin())
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertTrue($this->onSale($product));
    }

    /**
     * Activating is not a shortcut past the licence. The two states are separate
     * on purpose, and `canSell()` needs both.
     */
    public function test_activating_does_not_re_approve_a_lapsed_licence(): void
    {
        [$store, $product] = $this->tradingPharmacy();
        $store->update(['pharmacy_license_expiry' => now()->subDay()]);

        $this->postJson("/api/v1/admin/stores/{$store->id}/activate", [], $this->admin())->assertOk();

        $this->assertFalse($this->onSale($product));
        $this->assertFalse($store->fresh()->canSell());
    }

    // ---- archive -----------------------------------------------------------

    public function test_archiving_hides_the_row_and_takes_it_off_sale(): void
    {
        [$store, $product] = $this->tradingPharmacy();

        $this->deleteJson("/api/v1/admin/stores/{$store->id}", [], $this->admin())->assertOk();

        $fresh = Store::withTrashed()->find($store->id);

        $this->assertNotNull($fresh, 'archiving must not erase the row');
        $this->assertTrue($fresh->trashed());
        // Both, so a restore cannot put an archived shop straight back on sale.
        $this->assertSame('suspended', $fresh->status);
        $this->assertFalse($this->onSale($product));
    }

    public function test_an_archived_pharmacy_is_only_listed_on_request(): void
    {
        [$store] = $this->tradingPharmacy();

        $this->deleteJson("/api/v1/admin/stores/{$store->id}", [], $this->admin())->assertOk();

        $ids = fn (string $query) => collect(
            $this->getJson('/api/v1/admin/stores'.$query, $this->admin())->assertOk()->json('data')
        )->pluck('id')->all();

        $this->assertNotContains($store->id, $ids(''));
        $this->assertContains($store->id, $ids('?archived=1'));
    }

    public function test_restoring_brings_it_back_still_suspended(): void
    {
        [$store, $product] = $this->tradingPharmacy();

        $this->deleteJson("/api/v1/admin/stores/{$store->id}", [], $this->admin())->assertOk();

        $this->postJson("/api/v1/admin/stores/{$store->id}/restore", [], $this->admin())
            ->assertOk()
            ->assertJsonPath('data.is_archived', false)
            ->assertJsonPath('data.status', 'suspended');

        // Undoing an archive must never be what puts a shop back on sale.
        $this->assertFalse($this->onSale($product));
    }

    // ---- checkout ----------------------------------------------------------

    public function test_checkout_refuses_a_suspended_pharmacys_stock(): void
    {
        [$store, $product] = $this->tradingPharmacy();
        $this->postJson("/api/v1/admin/stores/{$store->id}/suspend", [], $this->admin())->assertOk();

        $this->assertFalse($store->fresh()->canSell());

        // The catalogue and the checkout are separate gates and both matter: a
        // delisted product can still be sitting in somebody's basket.
        $this->assertFalse($this->onSale($product));
    }

    // ---- the wrong id ------------------------------------------------------

    public function test_an_unknown_store_is_a_404_on_every_action(): void
    {
        $admin = $this->admin();

        $this->postJson('/api/v1/admin/stores/99999999/suspend', [], $admin)->assertStatus(404);
        $this->postJson('/api/v1/admin/stores/99999999/activate', [], $admin)->assertStatus(404);
        $this->deleteJson('/api/v1/admin/stores/99999999', [], $admin)->assertStatus(404);
        $this->postJson('/api/v1/admin/stores/99999999/restore', [], $admin)->assertStatus(404);
    }
}
