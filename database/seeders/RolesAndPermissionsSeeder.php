<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;
use App\Models\Permission;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create permissions
        $permissions = [
            // Dashboard
            ['name' => 'dashboard.view', 'display_name' => 'View Dashboard', 'group' => 'dashboard', 'description' => 'Access to admin dashboard'],
            ['name' => 'dashboard.analytics', 'display_name' => 'View Analytics', 'group' => 'dashboard', 'description' => 'View detailed analytics and reports'],
            
            // Products
            ['name' => 'products.view', 'display_name' => 'View Products', 'group' => 'products', 'description' => 'View product listings'],
            ['name' => 'products.create', 'display_name' => 'Create Products', 'group' => 'products', 'description' => 'Add new products'],
            ['name' => 'products.edit', 'display_name' => 'Edit Products', 'group' => 'products', 'description' => 'Modify existing products'],
            ['name' => 'products.delete', 'display_name' => 'Delete Products', 'group' => 'products', 'description' => 'Remove products'],
            ['name' => 'products.manage_inventory', 'display_name' => 'Manage Inventory', 'group' => 'products', 'description' => 'Update stock quantities'],
            
            // Orders
            ['name' => 'orders.view', 'display_name' => 'View Orders', 'group' => 'orders', 'description' => 'View order listings'],
            ['name' => 'orders.view_details', 'display_name' => 'View Order Details', 'group' => 'orders', 'description' => 'View full order information'],
            ['name' => 'orders.update_status', 'display_name' => 'Update Order Status', 'group' => 'orders', 'description' => 'Change order status'],
            ['name' => 'orders.cancel', 'display_name' => 'Cancel Orders', 'group' => 'orders', 'description' => 'Cancel customer orders'],
            ['name' => 'orders.refund', 'display_name' => 'Process Refunds', 'group' => 'orders', 'description' => 'Issue refunds to customers'],
            
            // Users
            ['name' => 'users.view', 'display_name' => 'View Users', 'group' => 'users', 'description' => 'View user listings'],
            ['name' => 'users.create', 'display_name' => 'Create Users', 'group' => 'users', 'description' => 'Add new users'],
            ['name' => 'users.edit', 'display_name' => 'Edit Users', 'group' => 'users', 'description' => 'Modify user information'],
            ['name' => 'users.delete', 'display_name' => 'Delete Users', 'group' => 'users', 'description' => 'Remove users'],
            ['name' => 'users.manage_status', 'display_name' => 'Manage User Status', 'group' => 'users', 'description' => 'Activate/deactivate users'],
            
            // Reviews
            ['name' => 'reviews.view', 'display_name' => 'View Reviews', 'group' => 'reviews', 'description' => 'View product reviews'],
            ['name' => 'reviews.approve', 'display_name' => 'Approve Reviews', 'group' => 'reviews', 'description' => 'Approve pending reviews'],
            ['name' => 'reviews.delete', 'display_name' => 'Delete Reviews', 'group' => 'reviews', 'description' => 'Remove reviews'],
            
            // Coupons
            ['name' => 'coupons.view', 'display_name' => 'View Coupons', 'group' => 'coupons', 'description' => 'View coupon listings'],
            ['name' => 'coupons.create', 'display_name' => 'Create Coupons', 'group' => 'coupons', 'description' => 'Create new coupons'],
            ['name' => 'coupons.edit', 'display_name' => 'Edit Coupons', 'group' => 'coupons', 'description' => 'Modify existing coupons'],
            ['name' => 'coupons.delete', 'display_name' => 'Delete Coupons', 'group' => 'coupons', 'description' => 'Remove coupons'],
            
            // Sales Events
            ['name' => 'sales.view', 'display_name' => 'View Sales Events', 'group' => 'sales', 'description' => 'View sales event listings'],
            ['name' => 'sales.create', 'display_name' => 'Create Sales Events', 'group' => 'sales', 'description' => 'Create new sales events'],
            ['name' => 'sales.edit', 'display_name' => 'Edit Sales Events', 'group' => 'sales', 'description' => 'Modify existing sales events'],
            ['name' => 'sales.delete', 'display_name' => 'Delete Sales Events', 'group' => 'sales', 'description' => 'Remove sales events'],
            
            // Settings
            ['name' => 'settings.view', 'display_name' => 'View Settings', 'group' => 'settings', 'description' => 'View system settings'],
            ['name' => 'settings.edit', 'display_name' => 'Edit Settings', 'group' => 'settings', 'description' => 'Modify system settings'],
            
            // Reports
            ['name' => 'reports.view', 'display_name' => 'View Reports', 'group' => 'reports', 'description' => 'Access reports and analytics'],
            ['name' => 'reports.export', 'display_name' => 'Export Data', 'group' => 'reports', 'description' => 'Export data to CSV/JSON'],
            
            // Roles & Permissions (Admin only)
            ['name' => 'roles.view', 'display_name' => 'View Roles', 'group' => 'roles', 'description' => 'View role listings'],
            ['name' => 'roles.create', 'display_name' => 'Create Roles', 'group' => 'roles', 'description' => 'Create new roles'],
            ['name' => 'roles.edit', 'display_name' => 'Edit Roles', 'group' => 'roles', 'description' => 'Modify role permissions'],
            ['name' => 'roles.delete', 'display_name' => 'Delete Roles', 'group' => 'roles', 'description' => 'Remove roles'],
            
            // Marketplace - Stores
            ['name' => 'stores.view', 'display_name' => 'View Stores', 'group' => 'stores', 'description' => 'View store listings'],
            ['name' => 'stores.create', 'display_name' => 'Create Stores', 'group' => 'stores', 'description' => 'Create new stores'],
            ['name' => 'stores.edit', 'display_name' => 'Edit Stores', 'group' => 'stores', 'description' => 'Modify store information'],
            ['name' => 'stores.delete', 'display_name' => 'Delete Stores', 'group' => 'stores', 'description' => 'Remove stores'],
            ['name' => 'stores.verify', 'display_name' => 'Verify Stores', 'group' => 'stores', 'description' => 'Verify store accounts'],
            ['name' => 'stores.manage_status', 'display_name' => 'Manage Store Status', 'group' => 'stores', 'description' => 'Suspend/activate stores'],
            
            // Marketplace - Delivery
            ['name' => 'delivery.view', 'display_name' => 'View Delivery', 'group' => 'delivery', 'description' => 'View delivery companies and agents'],
            ['name' => 'delivery.manage', 'display_name' => 'Manage Delivery', 'group' => 'delivery', 'description' => 'Manage logistics companies and agents'],
            ['name' => 'delivery.assign', 'display_name' => 'Assign Delivery', 'group' => 'delivery', 'description' => 'Assign orders to delivery agents'],
            ['name' => 'delivery.track', 'display_name' => 'Track Delivery', 'group' => 'delivery', 'description' => 'View delivery tracking'],
            
            // Marketplace - Shipping
            ['name' => 'shipping.view', 'display_name' => 'View Shipping Zones', 'group' => 'shipping', 'description' => 'View shipping zones'],
            ['name' => 'shipping.manage', 'display_name' => 'Manage Shipping', 'group' => 'shipping', 'description' => 'Manage shipping zones and fees'],
            
            // Marketplace - Pricing
            ['name' => 'pricing.view', 'display_name' => 'View Pricing', 'group' => 'pricing', 'description' => 'View pricing configurations'],
            ['name' => 'pricing.manage', 'display_name' => 'Manage Pricing', 'group' => 'pricing', 'description' => 'Manage pricing rules'],
            
            // Marketplace - Payouts
            ['name' => 'payouts.view', 'display_name' => 'View Payouts', 'group' => 'payouts', 'description' => 'View store payouts'],
            ['name' => 'payouts.create', 'display_name' => 'Create Payouts', 'group' => 'payouts', 'description' => 'Generate store payouts'],
            ['name' => 'payouts.process', 'display_name' => 'Process Payouts', 'group' => 'payouts', 'description' => 'Process and complete payouts'],
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission['name']],
                $permission
            );
        }

        // Create roles
        $adminRole = Role::updateOrCreate(
            ['name' => Role::ADMIN],
            [
                'display_name' => 'Administrator',
                'description' => 'Full system access with all permissions',
                'is_active' => true,
                'sort_order' => 1
            ]
        );

        $managerRole = Role::updateOrCreate(
            ['name' => Role::MANAGER],
            [
                'display_name' => 'Manager',
                'description' => 'Can manage products, orders, and users',
                'is_active' => true,
                'sort_order' => 2
            ]
        );

        $salesRole = Role::updateOrCreate(
            ['name' => Role::SALES],
            [
                'display_name' => 'Sales Representative',
                'description' => 'Can manage orders and view products',
                'is_active' => true,
                'sort_order' => 3
            ]
        );

        $supportRole = Role::updateOrCreate(
            ['name' => Role::SUPPORT],
            [
                'display_name' => 'Customer Support',
                'description' => 'Can view orders and manage customer issues',
                'is_active' => true,
                'sort_order' => 4
            ]
        );

        $inventoryRole = Role::updateOrCreate(
            ['name' => Role::INVENTORY],
            [
                'display_name' => 'Inventory Manager',
                'description' => 'Can manage product inventory and stock',
                'is_active' => true,
                'sort_order' => 5
            ]
        );

        $marketingRole = Role::updateOrCreate(
            ['name' => Role::MARKETING],
            [
                'display_name' => 'Marketing Manager',
                'description' => 'Can manage coupons, sales events, and promotions',
                'is_active' => true,
                'sort_order' => 6
            ]
        );

        $storeOwnerRole = Role::updateOrCreate(
            ['name' => 'store_owner'],
            [
                'display_name' => 'Store Owner',
                'description' => 'Can manage their own store, products, and orders',
                'is_active' => true,
                'sort_order' => 7
            ]
        );

        $deliveryAgentRole = Role::updateOrCreate(
            ['name' => 'delivery_agent'],
            [
                'display_name' => 'Delivery Agent',
                'description' => 'Can view assigned deliveries and update delivery status',
                'is_active' => true,
                'sort_order' => 8
            ]
        );

        // Assign permissions to roles
        
        // Admin gets all permissions (handled in User model)
        
        // Manager permissions
        $managerRole->syncPermissions([
            'dashboard.view',
            'dashboard.analytics',
            'products.view',
            'products.create',
            'products.edit',
            'products.manage_inventory',
            'orders.view',
            'orders.view_details',
            'orders.update_status',
            'orders.cancel',
            'orders.refund',
            'users.view',
            'users.create',
            'users.edit',
            'users.manage_status',
            'reviews.view',
            'reviews.approve',
            'reviews.delete',
            'coupons.view',
            'coupons.create',
            'coupons.edit',
            'sales.view',
            'sales.create',
            'sales.edit',
            'reports.view',
            'reports.export',
            // Marketplace permissions
            'stores.view',
            'stores.create',
            'stores.edit',
            'stores.verify',
            'stores.manage_status',
            'delivery.view',
            'delivery.manage',
            'delivery.assign',
            'delivery.track',
            'shipping.view',
            'shipping.manage',
            'pricing.view',
            'pricing.manage',
            'payouts.view',
            'payouts.create',
            'payouts.process',
        ]);

        // Sales Representative permissions
        $salesRole->syncPermissions([
            'dashboard.view',
            'products.view',
            'orders.view',
            'orders.view_details',
            'orders.update_status',
            'users.view',
            'coupons.view',
            'sales.view',
            'reports.view',
        ]);

        // Customer Support permissions
        $supportRole->syncPermissions([
            'dashboard.view',
            'products.view',
            'orders.view',
            'orders.view_details',
            'orders.update_status',
            'users.view',
            'reviews.view',
            'reviews.approve',
        ]);

        // Inventory Manager permissions
        $inventoryRole->syncPermissions([
            'dashboard.view',
            'products.view',
            'products.create',
            'products.edit',
            'products.manage_inventory',
            'orders.view',
            'reports.view',
        ]);

        // Marketing Manager permissions
        $marketingRole->syncPermissions([
            'dashboard.view',
            'dashboard.analytics',
            'products.view',
            'coupons.view',
            'coupons.create',
            'coupons.edit',
            'coupons.delete',
            'sales.view',
            'sales.create',
            'sales.edit',
            'sales.delete',
            'reports.view',
            'reports.export',
        ]);

        // Store Owner permissions
        $storeOwnerRole->syncPermissions([
            'dashboard.view',
            'dashboard.analytics',
            'products.view',
            'products.create',
            'products.edit',
            'products.delete',
            'products.manage_inventory',
            'orders.view',
            'orders.view_details',
            'orders.update_status',
            'orders.cancel',
            'orders.refund',
            'coupons.view',
            'coupons.create',
            'coupons.edit',
            'coupons.delete',
            'sales.view',
            'sales.create',
            'sales.edit',
            'sales.delete',
            'reports.view',
            'reports.export',
            'reviews.view',
            'reviews.respond',
            // Store-specific permissions
            'stores.view',
            'stores.edit', // Can edit their own store
            'payouts.view',
            'payouts.request',
        ]);

        // Delivery Agent permissions
        $deliveryAgentRole->syncPermissions([
            'dashboard.view',
            'orders.view',
            'orders.view_details',
            'delivery.view',
            'delivery.track',
        ]);

        $this->command->info('Roles and permissions seeded successfully!');
        $this->command->info('✅ Added marketplace roles: Store Owner, Delivery Agent');
        $this->command->info('✅ Added marketplace permissions: Stores, Delivery, Shipping, Pricing, Payouts');
    }
}
