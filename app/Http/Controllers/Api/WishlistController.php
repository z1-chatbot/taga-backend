<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wishlist;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class WishlistController extends Controller
{
    /**
     * Get user's wishlist
     */
    public function index(Request $request): JsonResponse
    {
        $wishlist = Wishlist::where('user_id', $request->user()->id)
                           ->with('product')
                           ->get();

        return response()->json([
            'success' => true,
            'data' => $wishlist
        ]);
    }

    /**
     * Add product to wishlist
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'product_id' => 'required|exists:products,id'
        ]);

        $wishlist = Wishlist::firstOrCreate([
            'user_id' => $request->user()->id,
            'product_id' => $request->product_id
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Product added to wishlist',
            'data' => $wishlist->load('product')
        ]);
    }

    /**
     * Add the product if it is not saved, remove it if it is.
     *
     * The storefront's heart button calls this on every tap. The route was
     * registered but the method was never written, so the call returned a 500
     * and the button silently did nothing.
     */
    public function toggle(Request $request): JsonResponse
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
        ]);

        $existing = Wishlist::where('user_id', $request->user()->id)
            ->where('product_id', $request->product_id)
            ->first();

        if ($existing) {
            $existing->delete();

            return response()->json([
                'success' => true,
                'message' => 'Removed from your saved items',
                'data' => ['in_wishlist' => false],
            ]);
        }

        $wishlist = Wishlist::create([
            'user_id' => $request->user()->id,
            'product_id' => $request->product_id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Saved to your list',
            'data' => [
                'in_wishlist' => true,
                'item' => $wishlist->load('product'),
            ],
        ]);
    }

    /**
     * Remove product from wishlist
     */
    public function destroy(Request $request, $productId): JsonResponse
    {
        $deleted = Wishlist::where('user_id', $request->user()->id)
                          ->where('product_id', $productId)
                          ->delete();

        if ($deleted) {
            return response()->json([
                'success' => true,
                'message' => 'Product removed from wishlist'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Product not found in wishlist'
        ], 404);
    }
}
