<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class BannerController extends Controller
{
    /**
     * Get all banners (admin)
     */
    public function index(Request $request): JsonResponse
    {
        $query = Banner::query();

        if ($request->has('position') && $request->position !== '') {
            $query->byPosition($request->position);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        $banners = $query->ordered()->get();

        return response()->json([
            'success' => true,
            'data' => $banners
        ]);
    }

    /**
     * Get active banners for frontend
     */
    public function getActive(Request $request): JsonResponse
    {
        $position = $request->get('position', 'products');
        
        \Log::info('Banner getActive called', [
            'requested_position' => $position,
            'all_active_banners' => Banner::active()->get()->pluck('position', 'id')->toArray(),
        ]);
        
        $banners = Banner::active()
                        ->byPosition($position)
                        ->ordered()
                        ->get();
        
        \Log::info('Banners returned', [
            'position' => $position,
            'count' => $banners->count(),
            'banner_ids' => $banners->pluck('id')->toArray(),
            'banner_positions' => $banners->pluck('position', 'id')->toArray(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $banners
        ]);
    }

    /**
     * Get single banner
     */
    public function show($id): JsonResponse
    {
        $banner = Banner::find($id);

        if (!$banner) {
            return response()->json([
                'success' => false,
                'message' => 'Banner not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $banner
        ]);
    }

    /**
     * Create new banner
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'link_url' => 'nullable|url',
            'button_text' => 'nullable|string|max:50',
            'bg_color' => 'nullable|string|max:100',
            'position' => 'required|in:home,products,both',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after:start_date',
        ]);

        // Handle image upload
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $filename = 'banner_' . time() . '_' . Str::random(10) . '.' . $image->getClientOriginalExtension();
            
            // Create directory if it doesn't exist
            $directory = public_path('banners');
            if (!file_exists($directory)) {
                mkdir($directory, 0775, true);
            }
            
            // Move file to public/banners/
            $image->move($directory, $filename);
            
            // Return the full URL
            $validated['image_url'] = url('banners/' . $filename);
        }

        $banner = Banner::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Banner created successfully',
            'data' => $banner
        ], 201);
    }

    /**
     * Update banner
     */
    public function update(Request $request, $id): JsonResponse
    {
        $banner = Banner::find($id);

        if (!$banner) {
            return response()->json([
                'success' => false,
                'message' => 'Banner not found'
            ], 404);
        }

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'link_url' => 'nullable|url',
            'button_text' => 'nullable|string|max:50',
            'bg_color' => 'nullable|string|max:100',
            'position' => 'sometimes|required|in:home,products,both',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after:start_date',
        ]);

        // Handle image upload
        if ($request->hasFile('image')) {
            // Delete old image file
            if ($banner->image_url) {
                $oldFilename = basename(parse_url($banner->image_url, PHP_URL_PATH));
                $oldFilePath = public_path('banners/' . $oldFilename);
                if (file_exists($oldFilePath)) {
                    unlink($oldFilePath);
                }
            }

            $image = $request->file('image');
            $filename = 'banner_' . time() . '_' . Str::random(10) . '.' . $image->getClientOriginalExtension();
            
            // Create directory if it doesn't exist
            $directory = public_path('banners');
            if (!file_exists($directory)) {
                mkdir($directory, 0775, true);
            }
            
            // Move file to public/banners/
            $image->move($directory, $filename);
            
            // Return the full URL
            $validated['image_url'] = url('banners/' . $filename);
        }

        $banner->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Banner updated successfully',
            'data' => $banner
        ]);
    }

    /**
     * Toggle banner status
     */
    public function toggleStatus($id): JsonResponse
    {
        $banner = Banner::find($id);

        if (!$banner) {
            return response()->json([
                'success' => false,
                'message' => 'Banner not found'
            ], 404);
        }

        $banner->is_active = !$banner->is_active;
        $banner->save();

        return response()->json([
            'success' => true,
            'message' => 'Banner status updated successfully',
            'data' => $banner
        ]);
    }

    /**
     * Delete banner
     */
    public function destroy($id): JsonResponse
    {
        $banner = Banner::find($id);

        if (!$banner) {
            return response()->json([
                'success' => false,
                'message' => 'Banner not found'
            ], 404);
        }

        // Delete image file
        if ($banner->image_url) {
            $filename = basename(parse_url($banner->image_url, PHP_URL_PATH));
            $filePath = public_path('banners/' . $filename);
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }

        $banner->delete();

        return response()->json([
            'success' => true,
            'message' => 'Banner deleted successfully'
        ]);
    }
}
