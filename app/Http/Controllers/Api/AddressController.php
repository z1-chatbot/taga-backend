<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AddressController extends Controller
{
    /**
     * Get all addresses for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $addresses = $request->user()->addresses()->orderBy('is_default', 'desc')->orderBy('created_at', 'desc')->get();

            return response()->json([
                'success' => true,
                'data' => $addresses
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch addresses: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a new address.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'address_line_1' => 'required|string|max:255',
                'address_line_2' => 'nullable|string|max:255',
                'city' => 'required|string|max:100',
                'state' => 'required|string|max:100',
                'postal_code' => 'nullable|string|max:20',
                'country' => 'required|string|max:100',
                'phone' => 'nullable|string|max:20',
                'is_default' => 'boolean',
            ]);

            $validated['user_id'] = $request->user()->id;

            // The column is NOT NULL with no default while the rule above is
            // `nullable`, so omitting it — normal for most Nigerian addresses —
            // failed at the database with a 500.
            $validated['postal_code'] = $validated['postal_code'] ?? '';

            // If this is the user's first address, make it default
            if ($request->user()->addresses()->count() === 0) {
                $validated['is_default'] = true;
            }

            // "One default per user" cannot be an index (see the migration that
            // drops unique_default_address), so it is enforced here.
            $address = DB::transaction(function () use ($validated, $request) {
                if (! empty($validated['is_default'])) {
                    $request->user()->addresses()
                        ->where('is_default', true)
                        ->update(['is_default' => false]);
                }

                return Address::create($validated);
            });

            return response()->json([
                'success' => true,
                'data' => $address,
                'message' => 'Address created successfully'
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Rethrown so it still renders as a 422 carrying field errors.
            // The generic catch below would otherwise turn every validation
            // failure into a 500, leaving the client unable to tell the user
            // which field was wrong.
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create address: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Show a specific address.
     */
    public function show(Request $request, $id): JsonResponse
    {
        try {
            $address = $request->user()->addresses()->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $address
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Address not found'
            ], 404);
        }
    }

    /**
     * Update an address.
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $address = $request->user()->addresses()->findOrFail($id);

            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'address_line_1' => 'required|string|max:255',
                'address_line_2' => 'nullable|string|max:255',
                'city' => 'required|string|max:100',
                'state' => 'required|string|max:100',
                'postal_code' => 'nullable|string|max:20',
                'country' => 'required|string|max:100',
                'phone' => 'nullable|string|max:20',
                'is_default' => 'boolean',
            ]);

            $address->update($validated);

            return response()->json([
                'success' => true,
                'data' => $address,
                'message' => 'Address updated successfully'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Rethrown so it still renders as a 422 carrying field errors.
            // The generic catch below would otherwise turn every validation
            // failure into a 500, leaving the client unable to tell the user
            // which field was wrong.
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update address: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete an address.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        try {
            $address = $request->user()->addresses()->findOrFail($id);
            
            // Don't allow deletion of the only address
            if ($request->user()->addresses()->count() === 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete your only address'
                ], 400);
            }

            // If deleting default address, make another address default
            if ($address->is_default) {
                $nextAddress = $request->user()->addresses()
                    ->where('id', '!=', $address->id)
                    ->first();
                
                if ($nextAddress) {
                    $nextAddress->update(['is_default' => true]);
                }
            }

            $address->delete();

            return response()->json([
                'success' => true,
                'message' => 'Address deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete address: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Set an address as default.
     */
    public function setDefault(Request $request, $id): JsonResponse
    {
        try {
            $address = $request->user()->addresses()->findOrFail($id);

            // Demote the current default first, or the user ends up with two.
            DB::transaction(function () use ($request, $address) {
                $request->user()->addresses()
                    ->where('is_default', true)
                    ->where('id', '!=', $address->id)
                    ->update(['is_default' => false]);

                $address->update(['is_default' => true]);
            });

            return response()->json([
                'success' => true,
                'data' => $address->fresh(),
                'message' => 'Default address updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to set default address: ' . $e->getMessage()
            ], 500);
        }
    }
}
