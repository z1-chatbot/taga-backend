<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class SystemSettingsController extends Controller
{
    /**
     * Get all system settings grouped by category
     */
    public function index(): JsonResponse
    {
        $settings = SystemSetting::ordered()
                                ->get()
                                ->groupBy('category');

        return response()->json([
            'success' => true,
            'data' => $settings
        ]);
    }

    /**
     * Get settings by category
     */
    public function getByCategory($category): JsonResponse
    {
        $settings = SystemSetting::byCategory($category)
                                 ->ordered()
                                 ->get();

        return response()->json([
            'success' => true,
            'data' => $settings
        ]);
    }

    /**
     * Get all settings for admin (including inactive ones)
     */
    public function getAllForAdmin(): JsonResponse
    {
        $settings = SystemSetting::ordered()
                                ->get()
                                ->groupBy('category');

        return response()->json([
            'success' => true,
            'data' => $settings
        ]);
    }

    /**
     * Create new setting
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'category' => ['required', Rule::in([
                SystemSetting::CATEGORY_PRODUCT_ATTRIBUTES,
                SystemSetting::CATEGORY_SALE_EVENT_TYPES,
                SystemSetting::CATEGORY_COUPON_TYPES,
                SystemSetting::CATEGORY_GENERAL
            ])],
            'key' => 'required|string|max:100',
            'value' => 'required',
            'label' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => ['required', Rule::in([
                SystemSetting::TYPE_ARRAY,
                SystemSetting::TYPE_STRING,
                SystemSetting::TYPE_BOOLEAN,
                SystemSetting::TYPE_NUMBER
            ])],
            'sort_order' => 'nullable|integer|min:0'
        ]);

        // Check if key already exists in category
        $exists = SystemSetting::where('category', $request->category)
                               ->where('key', $request->key)
                               ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Setting with this key already exists in this category'
            ], 422);
        }

        $setting = SystemSetting::create([
            'category' => $request->category,
            'key' => $request->key,
            'value' => $request->value,
            'label' => $request->label,
            'description' => $request->description,
            'type' => $request->type,
            'is_active' => $request->get('is_active', true),
            'sort_order' => $request->get('sort_order', 0)
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Setting created successfully',
            'data' => $setting
        ], 201);
    }

    /**
     * Update setting
     */
    public function update(Request $request, $id): JsonResponse
    {
        $setting = SystemSetting::find($id);

        if (!$setting) {
            return response()->json([
                'success' => false,
                'message' => 'Setting not found'
            ], 404);
        }

        $request->validate([
            'value' => 'required',
            'label' => 'required|string|max:255',
            'description' => 'nullable|string',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean'
        ]);

        $setting->update([
            'value' => $request->value,
            'label' => $request->label,
            'description' => $request->description,
            'sort_order' => $request->get('sort_order', $setting->sort_order),
            'is_active' => $request->get('is_active', $setting->is_active)
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Setting updated successfully',
            'data' => $setting
        ]);
    }

    /**
     * Delete setting
     */
    public function destroy($id): JsonResponse
    {
        $setting = SystemSetting::find($id);

        if (!$setting) {
            return response()->json([
                'success' => false,
                'message' => 'Setting not found'
            ], 404);
        }

        $setting->delete();

        return response()->json([
            'success' => true,
            'message' => 'Setting deleted successfully'
        ]);
    }

    /**
     * Toggle setting active status
     */
    public function toggleStatus($id): JsonResponse
    {
        $setting = SystemSetting::find($id);

        if (!$setting) {
            return response()->json([
                'success' => false,
                'message' => 'Setting not found'
            ], 404);
        }

        $setting->update(['is_active' => !$setting->is_active]);

        return response()->json([
            'success' => true,
            'message' => 'Setting status updated successfully',
            'data' => $setting
        ]);
    }

    /**
     * Get product attributes for forms
     */
    public function getProductAttributes(): JsonResponse
    {
        $attributes = SystemSetting::getProductAttributes();

        return response()->json([
            'success' => true,
            'data' => $attributes
        ]);
    }

    /**
     * Get sale event types for forms
     */
    public function getSaleEventTypes(): JsonResponse
    {
        $types = SystemSetting::getSaleEventTypes();

        return response()->json([
            'success' => true,
            'data' => $types
        ]);
    }

    /**
     * Get coupon types for forms
     */
    public function getCouponTypes(): JsonResponse
    {
        $types = SystemSetting::getCouponTypes();

        return response()->json([
            'success' => true,
            'data' => $types
        ]);
    }

    /**
     * Initialize default settings
     */
    public function initializeDefaults(): JsonResponse
    {
        try {
            SystemSetting::initializeDefaults();

            return response()->json([
                'success' => true,
                'message' => 'Default settings initialized successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to initialize default settings: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk update settings
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        $request->validate([
            'settings' => 'required|array',
            'settings.*.id' => 'required|exists:system_settings,id',
            'settings.*.value' => 'required',
            'settings.*.label' => 'required|string|max:255',
            'settings.*.sort_order' => 'nullable|integer|min:0',
            'settings.*.is_active' => 'boolean'
        ]);

        try {
            foreach ($request->settings as $settingData) {
                $setting = SystemSetting::find($settingData['id']);
                if ($setting) {
                    $setting->update([
                        'value' => $settingData['value'],
                        'label' => $settingData['label'],
                        'description' => $settingData['description'] ?? $setting->description,
                        'sort_order' => $settingData['sort_order'] ?? $setting->sort_order,
                        'is_active' => $settingData['is_active'] ?? $setting->is_active
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Settings updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update settings: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add new option to array setting
     */
    public function addArrayOption(Request $request, $id): JsonResponse
    {
        $setting = SystemSetting::find($id);

        if (!$setting) {
            return response()->json([
                'success' => false,
                'message' => 'Setting not found'
            ], 404);
        }

        if ($setting->type !== SystemSetting::TYPE_ARRAY) {
            return response()->json([
                'success' => false,
                'message' => 'Setting is not an array type'
            ], 422);
        }

        $request->validate([
            'option' => 'required|string|max:100'
        ]);

        $currentValue = $setting->value;
        if (!is_array($currentValue)) {
            $currentValue = [];
        }

        $newOption = $request->option;
        if (!in_array($newOption, $currentValue)) {
            $currentValue[] = $newOption;
            $setting->update(['value' => $currentValue]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Option added successfully',
            'data' => $setting
        ]);
    }

    /**
     * Remove option from array setting
     */
    public function removeArrayOption(Request $request, $id): JsonResponse
    {
        $setting = SystemSetting::find($id);

        if (!$setting) {
            return response()->json([
                'success' => false,
                'message' => 'Setting not found'
            ], 404);
        }

        if ($setting->type !== SystemSetting::TYPE_ARRAY) {
            return response()->json([
                'success' => false,
                'message' => 'Setting is not an array type'
            ], 422);
        }

        $request->validate([
            'option' => 'required|string'
        ]);

        $currentValue = $setting->value;
        if (is_array($currentValue)) {
            $currentValue = array_values(array_filter($currentValue, function($item) use ($request) {
                return $item !== $request->option;
            }));
            $setting->update(['value' => $currentValue]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Option removed successfully',
            'data' => $setting
        ]);
    }

    /**
     * Get public settings (for frontend use)
     */
    /**
     * The settings the admin frontend reads on every page load.
     *
     * Narrower than it was: six keys that were served here drove nothing
     * anywhere in the platform, and two of them (store_verification_required,
     * enable_multi_vendor) implied switches that do not exist. They were
     * removed rather than left to mislead — see the 2026_08_19 migration.
     */
    public function getPublicSettings(): JsonResponse
    {
        // Fetch settings directly from database to ensure fresh data
        $settings = [
            'currency_symbol' => SystemSetting::getCurrencySymbol(),
            'free_shipping_threshold' => (float) SystemSetting::getValue(SystemSetting::CATEGORY_GENERAL, 'free_shipping_threshold', 50000),
            'default_tax_rate' => (float) SystemSetting::getValue(SystemSetting::CATEGORY_GENERAL, 'default_tax_rate', 7.5),
            'low_stock_threshold' => (int) SystemSetting::getValue(SystemSetting::CATEGORY_GENERAL, 'low_stock_threshold', 10),
            'max_products_per_store' => (int) SystemSetting::getValue(SystemSetting::CATEGORY_GENERAL, 'max_products_per_store', 1000),
            // One helper, so the rate shown here is the rate a new pharmacy
            // is actually created on. The old fallback of 15 disagreed with the
            // stored value and with the column default of 0.00.
            'default_commission_rate' => SystemSetting::defaultCommissionRate(),
            'enable_dynamic_pricing' => (bool) SystemSetting::getValue(SystemSetting::CATEGORY_GENERAL, 'enable_dynamic_pricing', true),
            'min_payout_amount' => (float) SystemSetting::getValue(SystemSetting::CATEGORY_GENERAL, 'min_payout_amount', 10000),
        ];

        // Log for debugging
        \Log::info('Public Settings API called', ['settings' => $settings]);

        return response()->json([
            'success' => true,
            'data' => $settings
        ]);
    }
}
