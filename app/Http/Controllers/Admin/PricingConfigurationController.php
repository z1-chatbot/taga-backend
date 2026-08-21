<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PricingConfiguration;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PricingConfigurationController extends Controller
{
    /**
     * Get all pricing configurations
     */
    public function index(Request $request): JsonResponse
    {
        $query = PricingConfiguration::query();

        if ($request->has('category')) {
            $query->byCategory($request->category);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $configurations = $query->byPriority()->get();

        return response()->json([
            'success' => true,
            'data' => $configurations
        ]);
    }

    /**
     * Create pricing configuration
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'nullable|string|max:255',
            'type' => 'required|in:percentage,fixed_amount',
            'value' => 'required|numeric|min:0',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'priority' => 'nullable|integer|min:0'
        ]);

        $configuration = PricingConfiguration::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Pricing configuration created successfully',
            'data' => $configuration
        ], 201);
    }

    /**
     * Update pricing configuration
     */
    public function update(Request $request, $id): JsonResponse
    {
        $configuration = PricingConfiguration::find($id);

        if (!$configuration) {
            return response()->json([
                'success' => false,
                'message' => 'Pricing configuration not found'
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'category' => 'nullable|string|max:255',
            'type' => 'sometimes|in:percentage,fixed_amount',
            'value' => 'sometimes|numeric|min:0',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'priority' => 'nullable|integer|min:0'
        ]);

        $configuration->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Pricing configuration updated successfully',
            'data' => $configuration
        ]);
    }

    /**
     * Delete pricing configuration
     */
    public function destroy($id): JsonResponse
    {
        $configuration = PricingConfiguration::find($id);

        if (!$configuration) {
            return response()->json([
                'success' => false,
                'message' => 'Pricing configuration not found'
            ], 404);
        }

        $configuration->delete();

        return response()->json([
            'success' => true,
            'message' => 'Pricing configuration deleted successfully'
        ]);
    }

    /**
     * Toggle active status
     */
    public function toggleStatus($id): JsonResponse
    {
        $configuration = PricingConfiguration::find($id);

        if (!$configuration) {
            return response()->json([
                'success' => false,
                'message' => 'Pricing configuration not found'
            ], 404);
        }

        $configuration->update(['is_active' => !$configuration->is_active]);

        return response()->json([
            'success' => true,
            'message' => 'Status updated successfully',
            'data' => $configuration
        ]);
    }

    /**
     * Preview pricing for a product
     */
    public function previewPricing(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'base_price' => 'required|numeric|min:0',
            'category' => 'nullable|string'
        ]);

        $basePrice = $validated['base_price'];
        $category = $validated['category'] ?? null;

        $finalPrice = PricingConfiguration::applyToPrice($basePrice, $category);
        $markup = $finalPrice - $basePrice;

        $configurations = PricingConfiguration::active()
            ->byPriority()
            ->when($category, function ($q) use ($category) {
                $q->byCategory($category);
            })
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'base_price' => $basePrice,
                'final_price' => $finalPrice,
                'total_markup' => $markup,
                'markup_percentage' => $basePrice > 0 ? round(($markup / $basePrice) * 100, 2) : 0,
                'applied_configurations' => $configurations
            ]
        ]);
    }
}
