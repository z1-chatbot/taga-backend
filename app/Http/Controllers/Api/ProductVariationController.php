<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariation;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProductVariationController extends Controller
{
    /**
     * Get all variations for a product (admin)
     */
    public function index($productId): JsonResponse
    {
        $product = Product::with('store')->find($productId);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        // Check authorization
        $user = Auth::user();
        if ($product->store && $product->store->owner_id !== $user->id && $user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $variations = $product->variations()->ordered()->get();

        return response()->json([
            'success' => true,
            'data' => $variations
        ]);
    }

    /**
     * Get active variations for a product (public)
     */
    public function activeVariations($productId): JsonResponse
    {
        $product = Product::find($productId);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        $variations = $product->activeVariations;

        return response()->json([
            'success' => true,
            'data' => $variations
        ]);
    }

    /**
     * Get a single variation
     */
    public function show($productId, $variationId): JsonResponse
    {
        $variation = ProductVariation::where('id', $variationId)
            ->where('product_id', $productId)
            ->first();

        if (!$variation) {
            return response()->json([
                'success' => false,
                'message' => 'Variation not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $variation
        ]);
    }

    /**
     * Create a new variation
     */
    public function store(Request $request, $productId): JsonResponse
    {
        $product = Product::with('store')->find($productId);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        // Check authorization
        $user = Auth::user();
        if ($product->store && $product->store->owner_id !== $user->id && $user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $validated = $request->validate([
            'sku' => 'required|string|unique:product_variations,sku',
            'name' => 'required|string|max:255',
            'strength' => 'nullable|string|max:100',
            'pack_size' => 'nullable|string|max:100',
            'dosage_form' => 'nullable|string|max:100',
            'batch_number' => 'nullable|string|max:100',
            'expiry_date' => 'nullable|date|after:today',
            'other_specs' => 'nullable|array',
            'price' => 'required|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'required|integer|min:0',
            'weight' => 'nullable|numeric|min:0',
            'images' => 'nullable|array',
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer'
        ]);

        $validated['product_id'] = $productId;

        $variation = ProductVariation::create($validated);

        // Update product has_variations flag
        if (!$product->has_variations) {
            $product->update(['has_variations' => true]);
        }

        // Set as default if it's the first variation
        if ($product->variations()->count() === 1) {
            $product->update(['default_variation_id' => $variation->id]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Variation created successfully',
            'data' => $variation
        ], 201);
    }

    /**
     * Update a variation
     */
    public function update(Request $request, $productId, $variationId): JsonResponse
    {
        $product = Product::with('store')->find($productId);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        // Check authorization
        $user = Auth::user();
        if ($product->store && $product->store->owner_id !== $user->id && $user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $variation = ProductVariation::where('id', $variationId)
            ->where('product_id', $productId)
            ->first();

        if (!$variation) {
            return response()->json([
                'success' => false,
                'message' => 'Variation not found'
            ], 404);
        }

        $validated = $request->validate([
            'sku' => 'sometimes|string|unique:product_variations,sku,' . $variationId,
            'name' => 'sometimes|string|max:255',
            'strength' => 'nullable|string|max:100',
            'pack_size' => 'nullable|string|max:100',
            'dosage_form' => 'nullable|string|max:100',
            'batch_number' => 'nullable|string|max:100',
            'expiry_date' => 'nullable|date|after:today',
            'other_specs' => 'nullable|array',
            'price' => 'sometimes|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'sometimes|integer|min:0',
            'weight' => 'nullable|numeric|min:0',
            'images' => 'nullable|array',
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer'
        ]);

        $variation->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Variation updated successfully',
            'data' => $variation->fresh()
        ]);
    }

    /**
     * Delete a variation
     */
    public function destroy($productId, $variationId): JsonResponse
    {
        $product = Product::with('store')->find($productId);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        // Check authorization
        $user = Auth::user();
        if ($product->store && $product->store->owner_id !== $user->id && $user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $variation = ProductVariation::where('id', $variationId)
            ->where('product_id', $productId)
            ->first();

        if (!$variation) {
            return response()->json([
                'success' => false,
                'message' => 'Variation not found'
            ], 404);
        }

        // If this is the default variation, clear it
        if ($product->default_variation_id == $variationId) {
            $product->update(['default_variation_id' => null]);
        }

        $variation->delete();

        // Update has_variations flag if no variations left
        if ($product->variations()->count() === 0) {
            $product->update(['has_variations' => false]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Variation deleted successfully'
        ]);
    }

    /**
     * Bulk create variations
     */
    public function bulkStore(Request $request, $productId): JsonResponse
    {
        $product = Product::with('store')->find($productId);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        // Check authorization
        $user = Auth::user();
        if ($product->store && $product->store->owner_id !== $user->id && $user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $request->validate([
            'variations' => 'required|array|min:1',
            'variations.*.sku' => 'required|string|unique:product_variations,sku',
            'variations.*.name' => 'required|string|max:255',
            'variations.*.price' => 'required|numeric|min:0',
            'variations.*.stock_quantity' => 'required|integer|min:0'
        ]);

        $createdVariations = [];

        DB::beginTransaction();
        try {
            foreach ($request->variations as $variationData) {
                $variationData['product_id'] = $productId;
                $variation = ProductVariation::create($variationData);
                $createdVariations[] = $variation;
            }

            // Update product has_variations flag
            if (!$product->has_variations) {
                $product->update(['has_variations' => true]);
            }

            // Set first variation as default if no default exists
            if (!$product->default_variation_id && count($createdVariations) > 0) {
                $product->update(['default_variation_id' => $createdVariations[0]->id]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => count($createdVariations) . ' variations created successfully',
                'data' => $createdVariations
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create variations: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Set default variation
     */
    public function setDefault($productId, $variationId): JsonResponse
    {
        $product = Product::with('store')->find($productId);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        // Check authorization
        $user = Auth::user();
        if ($product->store && $product->store->owner_id !== $user->id && $user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $variation = ProductVariation::where('id', $variationId)
            ->where('product_id', $productId)
            ->first();

        if (!$variation) {
            return response()->json([
                'success' => false,
                'message' => 'Variation not found'
            ], 404);
        }

        $product->update(['default_variation_id' => $variationId]);

        return response()->json([
            'success' => true,
            'message' => 'Default variation set successfully',
            'data' => $variation
        ]);
    }

    /**
     * Generate unique SKU
     */
    public function generateSku($productId): JsonResponse
    {
        $product = Product::find($productId);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        // Generate SKU based on product name and random string
        $baseSku = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $product->name), 0, 6));
        $randomString = strtoupper(substr(md5(uniqid()), 0, 6));
        $sku = $baseSku . '-' . $randomString;

        // Ensure uniqueness
        while (ProductVariation::where('sku', $sku)->exists()) {
            $randomString = strtoupper(substr(md5(uniqid()), 0, 6));
            $sku = $baseSku . '-' . $randomString;
        }

        return response()->json([
            'success' => true,
            'data' => ['sku' => $sku]
        ]);
    }
}
