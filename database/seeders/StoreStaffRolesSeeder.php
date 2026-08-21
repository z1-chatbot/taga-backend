<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;
use App\Models\Permission;

class StoreStaffRolesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * 
     * Create store-specific staff roles with permissions scoped to their own store only.
     * These roles are different from admin roles which have platform-wide access.
     */
    public function run(): void
    {
        // Create store-specific permissions
        $storePermissions = [
            // Store Products - scoped to store
            ['name' => 'store.products.view', 'display_name' => 'View Store Products', 'group' => 'store_products', 'description' => 'View products in own store'],
            ['name' => 'store.products.create', 'display_name' => 'Create Store Products', 'group' => 'store_products', 'description' => 'Add products to own store'],
            ['name' => 'store.products.edit', 'display_name' => 'Edit Store Products', 'group' => 'store_products', 'description' => 'Modify products in own store'],
            ['name' => 'store.products.manage_inventory', 'display_name' => 'Manage Store Inventory', 'group' => 'store_products', 'description' => 'Update stock in own store'],
            
            // Store Orders - scoped to store
            ['name' => 'store.orders.view', 'display_name' => 'View Store Orders', 'group' => 'store_orders', 'description' => 'View orders for own store'],
            ['name' => 'store.orders.view_details', 'display_name' => 'View Store Order Details', 'group' => 'store_orders', 'description' => 'View full order details for own store'],
            ['name' => 'store.orders.update_status', 'display_name' => 'Update Store Order Status', 'group' => 'store_orders', 'description' => 'Change order status for own store'],
            
            // Store Coupons - scoped to store
            ['name' => 'store.coupons.view', 'display_name' => 'View Store Coupons', 'group' => 'store_coupons', 'description' => 'View coupons for own store'],
            ['name' => 'store.coupons.create', 'display_name' => 'Create Store Coupons', 'group' => 'store_coupons', 'description' => 'Create coupons for own store'],
            ['name' => 'store.coupons.edit', 'display_name' => 'Edit Store Coupons', 'group' => 'store_coupons', 'description' => 'Modify coupons for own store'],
            
            // Store Reviews - scoped to store
            ['name' => 'store.reviews.view', 'display_name' => 'View Store Reviews', 'group' => 'store_reviews', 'description' => 'View reviews for own store products'],
            ['name' => 'store.reviews.respond', 'display_name' => 'Respond to Store Reviews', 'group' => 'store_reviews', 'description' => 'Reply to reviews for own store'],
            
            // Store Reports - scoped to store
            ['name' => 'store.reports.view', 'display_name' => 'View Store Reports', 'group' => 'store_reports', 'description' => 'View reports for own store'],
        ];

        foreach ($storePermissions as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission['name']],
                $permission
            );
        }

        // Create Store Staff Roles (different from admin roles)
        
        // Store Manager - can manage everything in their store
        $storeManagerRole = Role::updateOrCreate(
            ['name' => 'store_manager'],
            [
                'display_name' => 'Store Manager',
                'description' => 'Can manage all aspects of their assigned store',
                'is_active' => true,
                'sort_order' => 20
            ]
        );

        $storeManagerRole->syncPermissions([
            'store.products.view',
            'store.products.create',
            'store.products.edit',
            'store.products.manage_inventory',
            'store.orders.view',
            'store.orders.view_details',
            'store.orders.update_status',
            'store.coupons.view',
            'store.coupons.create',
            'store.coupons.edit',
            'store.reviews.view',
            'store.reviews.respond',
            'store.reports.view',
        ]);

        // Store Sales Staff - can handle orders and view products
        $storeSalesRole = Role::updateOrCreate(
            ['name' => 'store_sales'],
            [
                'display_name' => 'Store Sales Staff',
                'description' => 'Can manage orders and view products in their store',
                'is_active' => true,
                'sort_order' => 21
            ]
        );

        $storeSalesRole->syncPermissions([
            'store.products.view',
            'store.orders.view',
            'store.orders.view_details',
            'store.orders.update_status',
            'store.coupons.view',
        ]);

        // Store Inventory Staff - can manage products and stock
        $storeInventoryRole = Role::updateOrCreate(
            ['name' => 'store_inventory'],
            [
                'display_name' => 'Store Inventory Staff',
                'description' => 'Can manage products and inventory in their store',
                'is_active' => true,
                'sort_order' => 22
            ]
        );

        $storeInventoryRole->syncPermissions([
            'store.products.view',
            'store.products.create',
            'store.products.edit',
            'store.products.manage_inventory',
            'store.orders.view',
        ]);

        // Store Support Staff - can view orders and respond to reviews
        $storeSupportRole = Role::updateOrCreate(
            ['name' => 'store_support'],
            [
                'display_name' => 'Store Support Staff',
                'description' => 'Can handle customer support for their store',
                'is_active' => true,
                'sort_order' => 23
            ]
        );

        $storeSupportRole->syncPermissions([
            'store.products.view',
            'store.orders.view',
            'store.orders.view_details',
            'store.reviews.view',
            'store.reviews.respond',
        ]);

        $this->command->info('Store staff roles created successfully!');
        $this->command->info('✅ Store Manager - Full store management');
        $this->command->info('✅ Store Sales Staff - Orders and sales');
        $this->command->info('✅ Store Inventory Staff - Products and stock');
        $this->command->info('✅ Store Support Staff - Customer support');
        $this->command->info('');
        $this->command->info('Note: These roles have store-scoped permissions only, not platform-wide access.');
    }
}
