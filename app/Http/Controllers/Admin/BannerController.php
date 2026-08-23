<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class BannerController extends Controller
{
    /**
     * Shared field rules. `$creating` flips the two fields that are mandatory
     * on create but optional on edit, so the two lists cannot drift apart.
     */
    private function rules(bool $creating): array
    {
        return [
            'title' => ($creating ? 'required' : 'sometimes|required') . '|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'image' => ($creating ? 'required' : 'nullable') . '|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            // Deliberately not the `url` rule. Most banners point somewhere on
            // the storefront, and `url` rejects "/products?category=vitamins" —
            // which forced admins to paste a full absolute URL and so hardcode
            // the hostname into the link. Both forms are accepted; anything
            // else (a bare "products", a "javascript:" scheme) is not.
            'link_url' => ['nullable', 'string', 'max:255', 'regex:/^(https?:\/\/|\/)/i'],
            'button_text' => 'nullable|string|max:50',
            'bg_color' => ['nullable', Rule::in(array_keys(Banner::THEMES))],
            'position' => ($creating ? 'required' : 'sometimes|required') . '|in:home,products,both',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after:start_date',
        ];
    }

    private function messages(): array
    {
        return [
            'link_url.regex' => 'The link must start with "/" for a page on Taga, or with http:// or https:// for an external site.',
        ];
    }

    /**
     * Put an upload on the public disk and hand back its stored path.
     *
     * Uploads land in `storage/app/public/banners` and are served through the
     * `public/storage` symlink, matching store logos and product images. The
     * column holds the disk-relative path — the model turns it into a URL.
     */
    private function storeImage(UploadedFile $image): string
    {
        return $image->store('banners', 'public');
    }

    /** Remove a banner image, tolerating the legacy absolute-URL form. */
    private function deleteImage(?string $imageUrl): void
    {
        if (! $imageUrl) {
            return;
        }

        // Pre-migration rows stored a full URL against a file in `public/banners`.
        if (filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            $legacy = public_path('banners/' . basename(parse_url($imageUrl, PHP_URL_PATH)));
            if (is_file($legacy)) {
                @unlink($legacy);
            }

            return;
        }

        Storage::disk('public')->delete($imageUrl);
    }

    /**
     * The background palette, for the admin picker.
     *
     * Served rather than hardcoded in the admin bundle so the list of options
     * and the colours the storefront actually paints cannot drift apart — the
     * previous dropdown was exactly that drift, offering "Blue", "Purple" and
     * "Pink" that had all been quietly rewritten to the same near-black.
     */
    public function themes(): JsonResponse
    {
        $themes = [];
        foreach (Banner::THEMES as $slug => $theme) {
            $themes[] = ['value' => $slug, 'label' => $theme['label'], 'hex' => $theme['hex']];
        }

        return response()->json(['success' => true, 'data' => $themes]);
    }

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
     * Get active banners for the storefront.
     *
     * This is public and unauthenticated, so it stays cheap and quiet — it used
     * to write two `Log::info` lines per request, one of which loaded and dumped
     * every active banner purely to describe what the query was about to do.
     */
    public function getActive(Request $request): JsonResponse
    {
        $position = $request->get('position', 'home');

        $banners = Banner::active()
                        ->byPosition($position)
                        ->ordered()
                        ->get();

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
        $validated = $request->validate($this->rules(true), $this->messages());

        $validated['image_url'] = $this->storeImage($request->file('image'));
        unset($validated['image']);

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

        $validated = $request->validate($this->rules(false), $this->messages());

        if ($request->hasFile('image')) {
            $this->deleteImage($banner->image_url);
            $validated['image_url'] = $this->storeImage($request->file('image'));
        }

        unset($validated['image']);

        $banner->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Banner updated successfully',
            'data' => $banner->fresh()
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

        $this->deleteImage($banner->image_url);

        $banner->delete();

        return response()->json([
            'success' => true,
            'message' => 'Banner deleted successfully'
        ]);
    }
}
