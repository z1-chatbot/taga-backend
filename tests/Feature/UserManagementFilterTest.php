<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * Filtering the user list, and who is allowed to change what in it.
 *
 * The role dropdown offered exactly two options, "Customer" and "Vendor".
 * There has never been a vendor role in this system — it is not in the roles
 * table and no user has ever carried it — so half the filter always returned an
 * empty list, and every role that does exist (store_owner, manager, support,
 * the store staff roles) could not be filtered for at all.
 *
 * The list also excluded administrators outright, so an admin account created
 * through this very page could not afterwards be found on it.
 */
class UserManagementFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Both: the platform roles and the store staff roles live in separate
        // seeders, and the user list contains people from both sets.
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->seed(\Database\Seeders\StoreStaffRolesSeeder::class);
    }

    private function admin(): User
    {
        return $this->makeUser([
            'role' => 'admin',
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]);
    }

    private function manager(): User
    {
        return $this->makeUser([
            'role' => 'manager',
            'role_id' => Role::where('name', 'manager')->value('id'),
        ]);
    }

    public function test_administrators_appear_in_the_list(): void
    {
        $admin = $this->admin();
        $target = $this->makeUser(['role' => 'admin', 'role_id' => Role::where('name', 'admin')->value('id')]);

        $response = $this->getJson('/api/v1/admin/users?per_page=200', $this->tokenFor($admin))
            ->assertOk();

        // Creating an administrator here and then not finding them here is the
        // bug this asserts against.
        $this->assertContains($target->id, array_column($response->json('data'), 'id'));
    }

    public function test_filtering_by_a_real_role_returns_those_users(): void
    {
        $admin = $this->admin();
        $owner = $this->makeUser([
            'role' => 'store_owner',
            'role_id' => Role::where('name', 'store_owner')->value('id'),
        ]);
        $customer = $this->makeUser(['role' => 'customer']);

        $ids = array_column(
            $this->getJson('/api/v1/admin/users?role=store_owner&per_page=200', $this->tokenFor($admin))
                ->assertOk()
                ->json('data'),
            'id'
        );

        $this->assertContains($owner->id, $ids);
        $this->assertNotContains($customer->id, $ids);
    }

    public function test_filtering_by_customer_still_works(): void
    {
        $admin = $this->admin();
        $customer = $this->makeUser(['role' => 'customer']);

        $ids = array_column(
            $this->getJson('/api/v1/admin/users?role=customer&per_page=200', $this->tokenFor($admin))
                ->assertOk()
                ->json('data'),
            'id'
        );

        // Customers have role='customer' and no row in `roles` — a value that
        // exists without existing as a record — so this path must not depend on
        // the relation.
        $this->assertContains($customer->id, $ids);
    }

    public function test_the_filter_role_list_includes_store_roles(): void
    {
        $admin = $this->admin();

        $all = array_column(
            $this->getJson('/api/v1/admin/users/roles?all=1', $this->tokenFor($admin))
                ->assertOk()
                ->json('data'),
            'name'
        );

        // The user list contains store staff, so a filter that cannot name
        // their roles cannot find them.
        $this->assertContains('store_manager', $all);
        $this->assertContains('store_owner', $all);

        // The assignment list is still the narrower one.
        $assignable = array_column(
            $this->getJson('/api/v1/admin/users/roles', $this->tokenFor($admin))->json('data'),
            'name'
        );

        $this->assertNotContains('store_manager', $assignable);
        $this->assertContains('store_owner', $assignable);
    }

    public function test_date_filters_narrow_the_list(): void
    {
        $admin = $this->admin();

        $old = $this->makeUser(['role' => 'customer']);
        $old->forceFill(['created_at' => now()->subMonths(6)])->save();

        $recent = $this->makeUser(['role' => 'customer']);

        $ids = array_column(
            $this->getJson(
                '/api/v1/admin/users?date_from='.now()->subDays(7)->toDateString().'&per_page=200',
                $this->tokenFor($admin)
            )->assertOk()->json('data'),
            'id'
        );

        $this->assertContains($recent->id, $ids);
        $this->assertNotContains($old->id, $ids);
    }

    /* ------------------------------------------------ privilege boundaries */

    public function test_a_manager_cannot_deactivate_an_administrator(): void
    {
        $manager = $this->manager();
        $target = $this->admin();

        // There was no check here at all, and `users.manage_status` belongs to
        // the manager role — so with administrators now visible in the list,
        // locking one out would have been a single click.
        $this->putJson("/api/v1/admin/users/{$target->id}/toggle-status", [], $this->tokenFor($manager))
            ->assertStatus(403);

        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_an_administrator_can_deactivate_another_administrator(): void
    {
        $admin = $this->admin();
        $target = $this->admin();

        $this->putJson("/api/v1/admin/users/{$target->id}/toggle-status", [], $this->tokenFor($admin))
            ->assertOk();

        $this->assertFalse($target->fresh()->is_active);
    }

    public function test_a_manager_cannot_grant_administrator_by_role_name(): void
    {
        $manager = $this->manager();
        $target = $this->makeUser(['role' => 'customer']);

        $this->putJson("/api/v1/admin/users/{$target->id}", ['role' => 'admin'], $this->tokenFor($manager))
            ->assertStatus(403);

        $this->assertSame('customer', $target->fresh()->role);
    }

    public function test_a_manager_cannot_grant_administrator_by_role_id(): void
    {
        $manager = $this->manager();
        $target = $this->makeUser(['role' => 'customer']);

        // The other way in. Setting role_id to the admin role grants the same
        // access without the word "admin" appearing in the payload at all.
        $this->putJson(
            "/api/v1/admin/users/{$target->id}",
            ['role_id' => Role::where('name', 'admin')->value('id')],
            $this->tokenFor($manager)
        )->assertStatus(403);

        $this->assertNotSame('admin', $target->fresh()->role);
    }

    public function test_a_real_staff_role_can_be_assigned(): void
    {
        $admin = $this->admin();
        $target = $this->makeUser(['role' => 'customer']);

        // The old rule was `in:admin,customer,vendor`, so setting any role that
        // actually exists failed validation.
        $this->putJson("/api/v1/admin/users/{$target->id}", ['role' => 'support'], $this->tokenFor($admin))
            ->assertOk();

        $this->assertSame('support', $target->fresh()->role);
    }
}
