<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use Tests\TestCase;

/**
 * A pharmacy can load the forms it is expected to fill in.
 *
 * The product form fetches its categories and its attribute vocabulary in one
 * Promise.all. The vocabulary lived behind the admin-role middleware, so a
 * pharmacy opening /products/add got "Access denied. Admin privileges
 * required." — and because one rejection fails the whole Promise.all, the
 * categories never arrived either. The visible symptom was an empty form with
 * no category list, which points nowhere near the actual cause.
 *
 * Nothing there was privileged: /api/v1/settings/product-attributes has always
 * served the identical payload unauthenticated. The admin gate protected
 * nothing and only locked out the people who needed it.
 */
class StoreOwnerFormAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // taga_test carries schema but no seed data, and every assertion here
        // runs through permission middleware.
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    private function pharmacyOwner()
    {
        $owner = $this->makeUser([
            'role' => 'store_owner',
            'role_id' => Role::where('name', 'store_owner')->value('id'),
        ]);

        $store = Store::create([
            'owner_id' => $owner->id,
            'name' => 'Form Access Pharmacy',
            'slug' => 'fa-'.uniqid(),
            'email' => 'shop'.uniqid().'@pharmacy.test',
            'state' => 'Lagos',
            'city' => 'Ikeja',
            'status' => 'active',
            'verification_status' => Store::VERIFICATION_APPROVED,
        ]);

        $owner->forceFill(['store_id' => $store->id])->save();

        return $owner;
    }

    public function test_a_pharmacy_can_load_the_product_form_reference_data(): void
    {
        $owner = $this->pharmacyOwner();

        $this->getJson('/api/v1/admin/settings/product-attributes', $this->tokenFor($owner))
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_a_pharmacy_can_load_the_categories_the_same_form_needs(): void
    {
        $owner = $this->pharmacyOwner();

        // The other half of the same Promise.all. Asserted together because it
        // was the half that visibly broke, while the other half was the cause.
        $this->getJson('/api/v1/categories?flat=1', $this->tokenFor($owner))
            ->assertOk();
    }

    public function test_a_pharmacy_can_load_the_coupon_form_reference_data(): void
    {
        $owner = $this->pharmacyOwner();

        // Same fault, not yet reported: coupons are store-owner territory, and
        // editing one fetched its type vocabulary from the admin-only group.
        $this->getJson('/api/v1/admin/settings/coupon-types', $this->tokenFor($owner))
            ->assertOk();
    }

    public function test_a_customer_still_cannot_read_them(): void
    {
        $customer = $this->makeUser(['role' => 'customer']);

        // Moved off the admin role, but not thrown open: each is keyed to the
        // permission for the form it feeds.
        $this->getJson('/api/v1/admin/settings/product-attributes', $this->tokenFor($customer))
            ->assertStatus(403);

        $this->getJson('/api/v1/admin/settings/coupon-types', $this->tokenFor($customer))
            ->assertStatus(403);
    }

    public function test_platform_settings_themselves_stay_admin_only(): void
    {
        $owner = $this->pharmacyOwner();

        // The move covers form vocabularies, not the settings screen. A
        // pharmacy must not be able to read or write platform configuration.
        $this->getJson('/api/v1/admin/settings', $this->tokenFor($owner))
            ->assertStatus(403);

        $this->getJson('/api/v1/admin/settings/admin/all', $this->tokenFor($owner))
            ->assertStatus(403);
    }
}
