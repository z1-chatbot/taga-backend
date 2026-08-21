<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Confines an admin-console query to the caller's own store.
 *
 * The permission middleware answers "may this person open the products page";
 * it says nothing about *whose* products they then see. Role::hasPermission()
 * deliberately lets a store-scoped permission satisfy the global one — holding
 * `store.products.view` passes a `permission:products.view` check — so every
 * endpoint behind such a guard has to narrow the result itself. Doing that by
 * hand in thirty-odd places is how four store roles ended up seeing the whole
 * platform.
 */
trait ScopesToStore
{
    /**
     * Narrow a query to the caller's store, if they belong to one.
     *
     * Fails closed: an account that is not a platform admin and has no store
     * attached matches nothing, rather than matching everything.
     */
    protected function scopeQueryToStore($query, Request $request, string $column = 'store_id')
    {
        $user = $request->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isPlatformAdmin()) {
            // A platform admin may narrow to one store on purpose.
            if ($request->filled($column)) {
                $query->where($column, $request->input($column));
            }

            return $query;
        }

        $storeId = $user->storeScopeId();

        if ($storeId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where($column, $storeId);
    }

    /**
     * Narrow an Order query to orders containing the caller's own stock.
     *
     * An order has no store column of its own — it is tied to a shop through the
     * products on its lines.
     */
    protected function scopeOrdersToStore($query, Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        $storeId = $user->isPlatformAdmin()
            ? $request->input('store_id')
            : $user->storeScopeId();

        if ($user->isPlatformAdmin() && ! $request->filled('store_id')) {
            return $query;
        }

        if ($storeId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('items.product', fn ($q) => $q->where('store_id', $storeId));
    }

    /**
     * Whether the caller may act on something belonging to this store.
     */
    protected function callerOwnsStore(Request $request, $storeId): bool
    {
        $user = $request->user();

        if (! $user) {
            return false;
        }

        if ($user->isPlatformAdmin()) {
            return true;
        }

        $scope = $user->storeScopeId();

        return $scope !== null && (int) $storeId === $scope;
    }

    /**
     * The refusal used when a record belongs to somebody else's store.
     *
     * Deliberately the same response as a record that does not exist, so ids
     * cannot be probed for what other pharmacies are selling.
     */
    protected function notFoundForCaller(string $subject = 'Record')
    {
        return response()->json([
            'success' => false,
            'message' => $subject.' not found',
        ], 404);
    }
}
