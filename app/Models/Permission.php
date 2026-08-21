<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'display_name',
        'group',
        'description'
    ];

    // Permission groups
    const GROUP_DASHBOARD = 'dashboard';
    const GROUP_PRODUCTS = 'products';
    const GROUP_ORDERS = 'orders';
    const GROUP_USERS = 'users';
    const GROUP_REVIEWS = 'reviews';
    const GROUP_COUPONS = 'coupons';
    const GROUP_SALES = 'sales';
    const GROUP_SETTINGS = 'settings';
    const GROUP_REPORTS = 'reports';
    const GROUP_ROLES = 'roles';

    /**
     * Get the roles that have this permission.
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_permission')
                    ->withTimestamps();
    }

    /**
     * Scope to filter by group.
     */
    public function scopeByGroup($query, $group)
    {
        return $query->where('group', $group);
    }

    /**
     * Get all permission groups.
     */
    public static function getGroups(): array
    {
        return [
            self::GROUP_DASHBOARD => 'Dashboard',
            self::GROUP_PRODUCTS => 'Products',
            self::GROUP_ORDERS => 'Orders',
            self::GROUP_USERS => 'Users',
            self::GROUP_REVIEWS => 'Reviews',
            self::GROUP_COUPONS => 'Coupons',
            self::GROUP_SALES => 'Sales Events',
            self::GROUP_SETTINGS => 'Settings',
            self::GROUP_REPORTS => 'Reports & Analytics',
            self::GROUP_ROLES => 'Roles & Permissions'
        ];
    }
}
