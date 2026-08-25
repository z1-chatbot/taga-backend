<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Tests\TestCase;

/**
 * The read-only pharmacy policy, for the pharmacies it binds.
 *
 * Every rule on that page is enforced against a store's own catalogue —
 * minimum shelf life decides which of their stock is sellable, prescription
 * validity decides when a customer's prescription stops covering their
 * medicines — and only platform admins could see any of it. A store's way of
 * discovering the rules was to have a sale refused.
 */
class StorePharmacyPolicyViewTest extends TestCase
{
    private function storeOwner(array $storeAttributes = []): array
    {
        $owner = $this->makeUser(['role' => 'store_owner']);

        // No StoreFactory in this project; the other store tests build one by
        // hand the same way.
        $store = Store::create(array_merge([
            'owner_id' => $owner->id,
            'name' => 'Policy Test Pharmacy',
            'slug' => 'pol-'.uniqid(),
            'email' => 'shop'.uniqid().'@pharmacy.test',
            'state' => 'Lagos',
            'city' => 'Ikeja',
            'status' => 'active',
            'verification_status' => Store::VERIFICATION_APPROVED,
            'can_sell_prescription' => true,
            'can_sell_controlled' => false,
        ], $storeAttributes));

        return [$owner, $store];
    }

    public function test_a_pharmacy_can_read_the_policy(): void
    {
        [$owner, $store] = $this->storeOwner();

        $response = $this->getJson('/api/v1/store/pharmacy-policy', $this->tokenFor($owner))->assertOk();

        // The numbers themselves.
        $response->assertJsonStructure([
            'data' => [
                'policy' => [
                    'min_shelf_life_days',
                    'prescription_validity_days',
                    'stock_expiry_warning_days',
                ],
                'enforced_always',
                'store' => [
                    'name',
                    'verification_status',
                    'can_sell_prescription',
                    'can_sell_controlled',
                    'licence_expiry',
                ],
            ],
        ]);

        // And where this pharmacy stands against them, which is the half that
        // makes the numbers actionable rather than trivia.
        $this->assertSame($store->name, $response->json('data.store.name'));
        $this->assertTrue($response->json('data.store.can_sell_prescription'));
        $this->assertFalse($response->json('data.store.can_sell_controlled'));
    }

    public function test_an_account_with_no_store_is_refused(): void
    {
        $customer = $this->makeUser(['role' => 'customer']);

        $this->getJson('/api/v1/store/pharmacy-policy', $this->tokenFor($customer))
            ->assertStatus(403);
    }

    public function test_reading_the_policy_cannot_change_it(): void
    {
        [$owner] = $this->storeOwner();

        // There is no store-side write route at all, and the admin one is
        // behind the `admin` middleware. Both halves are asserted because
        // "read-only" that rests on the UI hiding a button is not read-only.
        // Refused, and the exact code is the router's business: there is no PUT
        // registered on this path at all, so what comes back is whatever the
        // group's own handling produces. What matters is that it is not a 2xx.
        $this->putJson('/api/v1/store/pharmacy-policy', ['min_shelf_life_days' => 999], $this->tokenFor($owner))
            ->assertStatus(403);

        $this->putJson('/api/v1/admin/pharmacy-policy', ['min_shelf_life_days' => 999], $this->tokenFor($owner))
            ->assertStatus(403);
    }

    public function test_staff_of_a_pharmacy_can_read_it_too(): void
    {
        [, $store] = $this->storeOwner();

        $staff = $this->makeUser([
            'role' => 'staff',
            'store_id' => $store->id,
        ]);

        $this->getJson('/api/v1/store/pharmacy-policy', $this->tokenFor($staff))
            ->assertOk()
            ->assertJsonPath('data.store.name', $store->name);
    }
}
