<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PartnerPharmacy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The partner-pharmacy logo wall.
 *
 * Management is platform-admin only; the storefront reads `active()` through
 * one unauthenticated endpoint.
 */
class PartnerPharmacyController extends Controller
{
    /**
     * Logos are displayed at small sizes against a light ground, so the
     * accepted set is the one that survives that: PNG and WEBP for
     * transparency, SVG deliberately absent.
     *
     * SVG can carry script, and served from api.taga.ng it would run
     * same-origin to the API — the same reasoning as PublicStorageController's
     * allowlist, which would refuse to serve one anyway.
     */
    private const MAX_KB = 1024;

    private function rules(bool $creating): array
    {
        return [
            'name' => ($creating ? 'required' : 'sometimes|required').'|string|max:255',
            'logo' => ($creating ? 'required' : 'nullable').'|image|mimes:png,jpg,jpeg,webp|max:'.self::MAX_KB,
            // Not the `url` rule: a partner that is also a vendor should be
            // able to point at its own shop ("/stores/mercy-pharmacy") without
            // hardcoding the storefront hostname into a database row.
            'link_url' => ['nullable', 'string', 'max:255', 'regex:/^(https?:\/\/|\/)/i'],
            'store_id' => 'nullable|exists:stores,id',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ];
    }

    private function messages(): array
    {
        return [
            'link_url.regex' => 'The link must start with "/" for a page on Taga, or with http:// or https:// for an external site.',
            'logo.max' => 'Logos must be under 1 MB. A wordmark that size is already larger than it will ever be displayed.',
        ];
    }

    /** Admin: every partner, in display order. */
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => PartnerPharmacy::with('store:id,name,slug')->ordered()->get(),
        ]);
    }

    /**
     * Storefront: the ones to show.
     *
     * Uncacheable for the same reason the banners endpoint is: this sits behind
     * an edge cache that will happily hold a plain 200 GET, and a logo added or
     * withdrawn is meant to appear or disappear now. The payload is a handful
     * of rows.
     */
    public function active(): JsonResponse
    {
        $partners = PartnerPharmacy::active()
            ->ordered()
            ->get(['id', 'name', 'logo_path', 'link_url']);

        return response()->json([
            'success' => true,
            'data' => $partners,
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate')
          ->header('Pragma', 'no-cache');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules(true), $this->messages());

        $validated['logo_path'] = $this->storeLogo($request->file('logo'));
        unset($validated['logo']);

        return response()->json([
            'success' => true,
            'message' => 'Partner added.',
            'data' => PartnerPharmacy::create($validated),
        ], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $partner = PartnerPharmacy::find($id);

        if (! $partner) {
            return response()->json(['success' => false, 'message' => 'Partner not found'], 404);
        }

        $validated = $request->validate($this->rules(false), $this->messages());

        if ($request->hasFile('logo')) {
            $this->deleteLogo($partner->logo_path);
            $validated['logo_path'] = $this->storeLogo($request->file('logo'));
        }

        unset($validated['logo']);

        $partner->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Partner updated.',
            'data' => $partner->fresh(),
        ]);
    }

    public function toggleStatus($id): JsonResponse
    {
        $partner = PartnerPharmacy::find($id);

        if (! $partner) {
            return response()->json(['success' => false, 'message' => 'Partner not found'], 404);
        }

        $partner->update(['is_active' => ! $partner->is_active]);

        return response()->json([
            'success' => true,
            'message' => $partner->is_active ? 'Partner is now shown.' : 'Partner is now hidden.',
            'data' => $partner,
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $partner = PartnerPharmacy::find($id);

        if (! $partner) {
            return response()->json(['success' => false, 'message' => 'Partner not found'], 404);
        }

        // The row is going, so the file should go with it — otherwise the
        // public disk accumulates orphans nothing references and nothing will
        // ever clean up.
        $this->deleteLogo($partner->logo_path);
        $partner->delete();

        return response()->json(['success' => true, 'message' => 'Partner removed.']);
    }

    /** Puts an upload on the public disk and returns its disk-relative path. */
    private function storeLogo(UploadedFile $logo): string
    {
        return $logo->store('partners', 'public');
    }

    private function deleteLogo(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
