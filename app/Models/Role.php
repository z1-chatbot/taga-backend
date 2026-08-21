<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'display_name',
        'description',
        'is_active',
        'sort_order'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer'
    ];

    // Role constants
    const ADMIN = 'admin';
    const MANAGER = 'manager';
    const SALES = 'sales';
    const SUPPORT = 'support';
    const INVENTORY = 'inventory';
    const MARKETING = 'marketing';

    /**
     * Get the users for this role.
     */
    public function users()
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get the permissions for this role.
     */
    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'role_permission')
                    ->withTimestamps();
    }

    /**
     * Check if role has a specific permission.
     * Also checks for store-scoped version (e.g., store.products.view for products.view)
     */
    public function hasPermission($permissionName): bool
    {
        // Check for exact permission match
        if ($this->permissions()->where('name', $permissionName)->exists()) {
            return true;
        }
        
        // Check for store-scoped version of the permission
        // e.g., if checking 'products.view', also check 'store.products.view'
        $storeScopedPermission = 'store.' . $permissionName;
        if ($this->permissions()->where('name', $storeScopedPermission)->exists()) {
            return true;
        }
        
        return false;
    }

    /**
     * Give permission to role.
     */
    public function givePermission($permission)
    {
        if (is_string($permission)) {
            $permission = Permission::where('name', $permission)->firstOrFail();
        }
        
        return $this->permissions()->syncWithoutDetaching([$permission->id]);
    }

    /**
     * Revoke permission from role.
     */
    public function revokePermission($permission)
    {
        if (is_string($permission)) {
            $permission = Permission::where('name', $permission)->firstOrFail();
        }
        
        return $this->permissions()->detach($permission->id);
    }

    /**
     * Sync permissions for role.
     */
    public function syncPermissions(array $permissions)
    {
        $permissionIds = Permission::whereIn('name', $permissions)->pluck('id')->toArray();
        return $this->permissions()->sync($permissionIds);
    }

    /**
     * Scope to get active roles.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to order by sort order.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('display_name');
    }
}
