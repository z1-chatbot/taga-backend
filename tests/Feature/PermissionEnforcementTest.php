<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * Does ticking a box in Roles & Permissions actually change what someone can do?
 *
 * The screen presents 66 checkboxes across 20 groups as if each one gates
 * something. These tests establish which of them the server actually reads,
 * and which are decoration — the answer differs by section, because the admin
 * API is split across two route groups with different gates:
 *
 *   · one applies `permission:<name>` per route and does consult the boxes
 *   · one applies the `admin` middleware, which only asks `role === 'admin'`
 *     and never looks at a permission at all
 */
class PermissionEnforcementTest extends TestCase
{
    /**
     * The live database's permission rows, mirrored into the test database.
     *
     * These are the exact names the Roles & Permissions screen renders as
     * checkboxes, so a test that invents its own would not be testing the
     * thing an operator actually clicks.
     */
    private const LIVE_PERMISSIONS = [
        'products.view', 'products.create', 'products.edit', 'products.delete',
        'orders.view', 'orders.view_details', 'orders.update_status',
        'users.view', 'users.edit', 'users.create', 'users.manage_status',
        'orders.update_payment', 'reports.export',
        'shipping.view', 'shipping.manage',
        'settings.view', 'settings.edit',
        'roles.view', 'roles.edit', 'roles.create',
        'payouts.view', 'payouts.process', 'payouts.create',
        'delivery.view', 'delivery.manage', 'delivery.assign', 'delivery.track',
        'stores.view', 'stores.edit', 'stores.verify', 'stores.manage_status',
        'pricing.view', 'pricing.manage',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::LIVE_PERMISSIONS as $name) {
            Permission::firstOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
                    'group' => explode('.', $name)[0],
                ]
            );
        }
    }

    private function staffWith(array $permissionNames): User
    {
        $role = Role::create([
            'name' => 'audit_role_'.uniqid(),
            'display_name' => 'Audit Role',
            'is_active' => true,
        ]);

        $ids = Permission::whereIn('name', $permissionNames)->pluck('id')->all();
        $role->permissions()->sync($ids);

        return $this->makeUser([
            'role' => 'staff',
            'role_id' => $role->id,
        ]);
    }

    // ---- the half that works ------------------------------------------------

    public function test_a_permission_the_role_lacks_is_refused(): void
    {
        $staff = $this->staffWith([]);

        $this->getJson('/api/v1/admin/products', $this->tokenFor($staff))
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'products.view');
    }

    public function test_granting_that_permission_lets_them_in(): void
    {
        $staff = $this->staffWith(['products.view']);

        $this->getJson('/api/v1/admin/products', $this->tokenFor($staff))->assertOk();
    }

    public function test_a_read_permission_does_not_imply_a_write_one(): void
    {
        $staff = $this->staffWith(['products.view']);

        $this->postJson('/api/v1/admin/products', [], $this->tokenFor($staff))
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'products.create');
    }

    public function test_revoking_a_permission_takes_effect_immediately(): void
    {
        $staff = $this->staffWith(['orders.view']);

        $this->getJson('/api/v1/admin/orders', $this->tokenFor($staff))->assertOk();

        // No cache to bust, no re-login: the guard reads the pivot per request.
        $staff->roleRelation->permissions()->sync([]);

        $this->getJson('/api/v1/admin/orders', $this->tokenFor($staff->fresh()))
            ->assertStatus(403);
    }

    // ---- the half that does not ---------------------------------------------

    public function test_the_shipping_permission_grants_nothing(): void
    {
        // shipping.view and shipping.manage are both offered in the UI.
        $staff = $this->staffWith(['shipping.view', 'shipping.manage']);

        $this->getJson('/api/v1/admin/shipping-zones', $this->tokenFor($staff))
            ->assertStatus(403);
    }

    public function test_the_settings_permissions_grant_nothing(): void
    {
        $staff = $this->staffWith(['settings.view', 'settings.edit']);

        $this->getJson('/api/v1/admin/settings', $this->tokenFor($staff))
            ->assertStatus(403);
    }

    public function test_the_roles_permissions_grant_nothing(): void
    {
        // Granting roles.view to a manager looks like delegating user
        // administration. It does not.
        $staff = $this->staffWith(['roles.view', 'roles.edit', 'roles.create']);

        $this->getJson('/api/v1/admin/roles', $this->tokenFor($staff))
            ->assertStatus(403);
    }

    public function test_the_payout_permissions_grant_nothing(): void
    {
        $staff = $this->staffWith(['payouts.view', 'payouts.process', 'payouts.create']);

        $this->getJson('/api/v1/admin/stores/payouts', $this->tokenFor($staff))
            ->assertStatus(403);
    }

    public function test_the_delivery_permissions_grant_nothing(): void
    {
        $staff = $this->staffWith(['delivery.view', 'delivery.manage', 'delivery.assign', 'delivery.track']);

        $this->getJson('/api/v1/admin/delivery/agents', $this->tokenFor($staff))
            ->assertStatus(403);
    }

    public function test_the_store_permissions_grant_nothing(): void
    {
        $staff = $this->staffWith(['stores.view', 'stores.edit', 'stores.verify', 'stores.manage_status']);

        $this->getJson('/api/v1/admin/stores', $this->tokenFor($staff))
            ->assertStatus(403);
    }

    public function test_the_pricing_permissions_grant_nothing(): void
    {
        $staff = $this->staffWith(['pricing.view', 'pricing.manage']);

        $this->getJson('/api/v1/admin/pricing-configurations', $this->tokenFor($staff))
            ->assertStatus(403);
    }

    // ---- guards naming a permission that does not exist ---------------------

    /**
     * Was: the user routes asked for `users.update`, which has no row, so the
     * guard was unsatisfiable and editing a user was impossible for every
     * staff role however it was configured. They now ask for permissions that
     * exist and can be ticked.
     */
    public function test_editing_a_user_is_grantable(): void
    {
        $this->assertNull(Permission::where('name', 'users.update')->first(),
            'users.update still has no row — the routes must not ask for it');

        $target = $this->makeUser(['role' => 'staff']);

        $without = $this->staffWith(['users.view']);
        $this->putJson('/api/v1/admin/users/'.$target->id, ['name' => 'Nope'], $this->tokenFor($without))
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'users.edit');

        $with = $this->staffWith(['users.view', 'users.edit']);
        $this->putJson('/api/v1/admin/users/'.$target->id, [
            'name' => 'Renamed',
            'email' => $target->email,
        ], $this->tokenFor($with))->assertOk();

        $this->assertSame('Renamed', $target->fresh()->name);
    }

    public function test_toggling_a_user_status_uses_its_own_permission(): void
    {
        $target = $this->makeUser(['role' => 'staff']);

        // users.edit alone must not carry the power to deactivate an account.
        $editor = $this->staffWith(['users.view', 'users.edit']);
        $this->putJson('/api/v1/admin/users/'.$target->id.'/toggle-status', [], $this->tokenFor($editor))
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'users.manage_status');

        $manager = $this->staffWith(['users.view', 'users.manage_status']);
        $this->putJson('/api/v1/admin/users/'.$target->id.'/toggle-status', [], $this->tokenFor($manager))
            ->assertOk();
    }

    public function test_payment_confirmation_is_now_grantable(): void
    {
        // Was: the guard named orders.update_payment, which had no row, so no
        // checkbox could grant it and only an admin could ever confirm a
        // payment. The row now exists and the guard is satisfiable.
        $permission = Permission::where('name', 'orders.update_payment')->first();

        $this->assertNotNull($permission, 'the migration must add this row');
        $this->assertSame('orders', $permission->group);

        $staff = $this->staffWith(['orders.view']);
        $this->postJson('/api/v1/admin/orders/1/confirm-payment', [], $this->tokenFor($staff))
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'orders.update_payment');

        // Granted, the guard lets them through — whatever the controller then
        // decides about order 1 not existing is a different question.
        $granted = $this->staffWith(['orders.view', 'orders.update_payment']);
        $response = $this->postJson('/api/v1/admin/orders/1/confirm-payment', [], $this->tokenFor($granted));

        $this->assertNotSame(403, $response->status(),
            'the permission guard must no longer be what stops them');
    }

    // ---- the round trip: does the screen's Save actually bind? --------------

    /**
     * The exact call AddRolePage makes when you press Save, and then a real
     * request from someone holding that role. If these two agree, the screen is
     * telling the truth about the sections the backend enforces.
     */
    public function test_saving_a_role_in_the_ui_persists_and_binds_immediately(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        $role = Role::create([
            'name' => 'roundtrip_'.uniqid(),
            'display_name' => 'Round Trip',
            'is_active' => true,
        ]);

        $staff = $this->makeUser(['role' => 'staff', 'role_id' => $role->id]);

        // Nothing granted yet.
        $this->getJson('/api/v1/admin/products', $this->tokenFor($staff))->assertStatus(403);

        // PUT /admin/roles/{id} — what the Save button sends.
        $this->putJson("/api/v1/admin/roles/{$role->id}", [
            'display_name' => 'Round Trip',
            'permissions' => ['products.view', 'products.edit'],
        ], $this->tokenFor($admin))->assertOk();

        // Persisted to the pivot, exactly the two named.
        $saved = $role->fresh()->permissions->pluck('name')->sort()->values()->all();
        $this->assertSame(['products.edit', 'products.view'], $saved);

        // And binding on the very next request, with no re-login.
        $this->getJson('/api/v1/admin/products', $this->tokenFor($staff))->assertOk();

        // Still refused for what was not ticked.
        $this->postJson('/api/v1/admin/products', [], $this->tokenFor($staff))
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'products.create');
    }

    public function test_unticking_in_the_ui_revokes_on_the_next_request(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        $role = Role::create([
            'name' => 'roundtrip_'.uniqid(),
            'display_name' => 'Round Trip',
            'is_active' => true,
        ]);
        $role->permissions()->sync(Permission::whereIn('name', ['orders.view'])->pluck('id'));

        $staff = $this->makeUser(['role' => 'staff', 'role_id' => $role->id]);

        $this->getJson('/api/v1/admin/orders', $this->tokenFor($staff))->assertOk();

        // Save with the box cleared — syncPermissions detaches what is absent.
        $this->putJson("/api/v1/admin/roles/{$role->id}", [
            'display_name' => 'Round Trip',
            'permissions' => [],
        ], $this->tokenFor($admin))->assertOk();

        $this->assertCount(0, $role->fresh()->permissions);
        $this->getJson('/api/v1/admin/orders', $this->tokenFor($staff))->assertStatus(403);
    }

    public function test_the_save_refuses_a_permission_that_does_not_exist(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        $role = Role::create([
            'name' => 'roundtrip_'.uniqid(),
            'display_name' => 'Round Trip',
            'is_active' => true,
        ]);

        // Worth knowing, because three UI guards name permissions with no row:
        // they could never be saved onto a role even if a box existed for them.
        $this->putJson("/api/v1/admin/roles/{$role->id}", [
            'display_name' => 'Round Trip',
            'permissions' => ['settings.manage'],
        ], $this->tokenFor($admin))
            ->assertStatus(422)
            ->assertJsonValidationErrors('permissions.0');
    }

    public function test_a_second_save_does_not_duplicate_pivot_rows(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        $role = Role::create([
            'name' => 'roundtrip_'.uniqid(),
            'display_name' => 'Round Trip',
            'is_active' => true,
        ]);

        foreach ([1, 2] as $ignored) {
            $this->putJson("/api/v1/admin/roles/{$role->id}", [
                'display_name' => 'Round Trip',
                'permissions' => ['products.view', 'orders.view'],
            ], $this->tokenFor($admin))->assertOk();
        }

        $this->assertCount(2, $role->fresh()->permissions);
    }

    public function test_only_an_admin_can_change_a_role(): void
    {
        $role = Role::create([
            'name' => 'roundtrip_'.uniqid(),
            'display_name' => 'Round Trip',
            'is_active' => true,
        ]);

        // Even holding every roles.* permission — the endpoint is role-gated.
        $staff = $this->staffWith(['roles.view', 'roles.edit', 'roles.create']);

        $this->putJson("/api/v1/admin/roles/{$role->id}", [
            'display_name' => 'Hijacked',
            'permissions' => ['products.view'],
        ], $this->tokenFor($staff))->assertStatus(403);

        $this->assertSame('Round Trip', $role->fresh()->display_name);
    }

    public function test_a_role_cannot_grant_itself_past_the_admin_wall(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        $role = Role::create([
            'name' => 'roundtrip_'.uniqid(),
            'display_name' => 'Round Trip',
            'is_active' => true,
        ]);
        $staff = $this->makeUser(['role' => 'staff', 'role_id' => $role->id]);

        // Tick every box the screen offers for the admin-only sections.
        $this->putJson("/api/v1/admin/roles/{$role->id}", [
            'display_name' => 'Round Trip',
            'permissions' => [
                'stores.view', 'stores.edit', 'stores.verify', 'stores.manage_status',
                'settings.view', 'settings.edit',
                'shipping.view', 'shipping.manage',
                'delivery.view', 'delivery.manage',
                'pricing.view', 'pricing.manage',
                'payouts.view', 'payouts.process',
                'roles.view', 'roles.edit',
            ],
        ], $this->tokenFor($admin))->assertOk();

        // Saved faithfully — 16 rows on the pivot.
        $this->assertCount(16, $role->fresh()->permissions);

        // And worth precisely nothing, because those routes never ask.
        foreach ([
            '/api/v1/admin/stores',
            '/api/v1/admin/settings',
            '/api/v1/admin/shipping-zones',
            '/api/v1/admin/pricing-configurations',
            '/api/v1/admin/roles',
        ] as $url) {
            $this->getJson($url, $this->tokenFor($staff))
                ->assertStatus(403, "16 permissions granted, still refused at {$url}");
        }
    }

    // ---- a pharmacy's staff, not just its owner ------------------------------

    /**
     * The admin app picks the prescriptions endpoint by role. It used to ask
     * "are you a store_owner?", which is false for the staff a pharmacy hires —
     * so they were sent to /admin/prescriptions, which the admin middleware
     * refuses. These two assertions are why the client now asks "do you belong
     * to a shop?" instead.
     */
    public function test_a_pharmacys_staff_can_open_their_own_prescription_queue(): void
    {
        $owner = $this->makeUser(['role' => 'store_owner']);

        $store = \App\Models\Store::create([
            'owner_id' => $owner->id,
            'name' => 'Staffed Pharmacy',
            'slug' => 'staffed-pharmacy-'.uniqid(),
            'email' => 'shop'.uniqid().'@stores.test',
            'phone' => '08012345678',
            'address' => '1 Test Road',
            'city' => 'Ikeja',
            'state' => 'Lagos',
            'status' => 'active',
            'verification_status' => \App\Models\Store::VERIFICATION_APPROVED,
        ]);

        // Exactly what StoreOwner\StaffController creates.
        $staff = $this->makeUser([
            'role' => 'staff',
            'store_id' => $store->id,
        ]);

        // The queue their own shop owns — reachable, and resolved by store_id.
        $this->getJson('/api/v1/store/prescriptions', $this->tokenFor($staff))->assertOk();

        // The platform-wide queue stays shut to them, which is why sending
        // them there was the bug.
        $this->getJson('/api/v1/admin/prescriptions', $this->tokenFor($staff))
            ->assertStatus(403);
    }

    public function test_someone_with_no_shop_gets_no_store_queue(): void
    {
        $nobody = $this->makeUser(['role' => 'staff', 'store_id' => null]);

        $this->getJson('/api/v1/store/prescriptions', $this->tokenFor($nobody))
            ->assertStatus(403);
    }

    // ---- admin bypass --------------------------------------------------------

    public function test_an_admin_bypasses_every_check_regardless_of_role_rows(): void
    {
        $admin = $this->makeUser(['role' => 'admin', 'role_id' => null]);

        // No role, therefore no permission rows at all.
        $this->getJson('/api/v1/admin/products', $this->tokenFor($admin))->assertOk();
        $this->getJson('/api/v1/admin/settings', $this->tokenFor($admin))->assertOk();
    }
}
