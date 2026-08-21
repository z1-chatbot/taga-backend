<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

trait StoreScoped
{
    /**
     * Boot the store scoped trait for a model.
     * 
     * Automatically scope queries to the authenticated user's store if they are store staff.
     */
    protected static function bootStoreScoped()
    {
        // Only apply scoping when there's an authenticated admin user
        static::addGlobalScope('store', function (Builder $builder) {
            $user = auth('admin')->user();
            
            if (!$user) {
                return;
            }

            // If user is store staff (has store_id), scope to their store
            if ($user->store_id) {
                $builder->where('store_id', $user->store_id);
            }
            
            // If user is store owner, scope to their store
            if ($user->role === 'store_owner' && $user->store) {
                $builder->where('store_id', $user->store->id);
            }
        });
    }

    /**
     * Get query without store scope (for admins)
     */
    public static function withoutStoreScope()
    {
        return static::withoutGlobalScope('store');
    }
}
