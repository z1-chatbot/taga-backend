<?php

namespace App\Http\Controllers\StoreOwner;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class StaffController extends Controller
{
    /**
     * Get available roles that store owners can assign to staff
     * Only returns store-specific roles, not admin/platform roles
     */
    public function getAvailableRoles(Request $request)
    {
        // Get only store-specific staff roles (roles that start with 'store_')
        // These roles have store-scoped permissions, not platform-wide access
        $roles = Role::where('is_active', true)
            ->where('name', 'LIKE', 'store_%')
            ->whereNotIn('name', ['store_owner']) // Exclude store_owner role
            ->ordered()
            ->get()
            ->map(function ($role) {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'display_name' => $role->display_name,
                    'description' => $role->description,
                    'permissions' => $role->permissions->pluck('display_name'),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $roles
        ]);
    }

    /**
     * Get all staff members for the store owner's store
     */
    public function index(Request $request)
    {
        $user = $request->user();
        
        // Get the store owned by this user
        $store = Store::where('owner_id', $user->id)->first();
        
        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'No store found for this user'
            ], 404);
        }

        // Get all staff members for this store
        $staff = User::where('role', 'staff')
            ->where('store_id', $store->id)
            ->with('roleRelation')
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role' => $user->role,
                    'role_id' => $user->role_id,
                    'store_id' => $user->store_id,
                    'is_active' => $user->is_active,
                    'created_at' => $user->created_at,
                    'roleRelation' => $user->roleRelation ? [
                        'id' => $user->roleRelation->id,
                        'name' => $user->roleRelation->name,
                        'display_name' => $user->roleRelation->display_name,
                        'description' => $user->roleRelation->description,
                    ] : null,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $staff
        ]);
    }

    /**
     * Create a new staff member for the store
     */
    public function store(Request $request)
    {
        $user = $request->user();
        
        // Get the store owned by this user
        $store = Store::where('owner_id', $user->id)->first();
        
        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'No store found for this user'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'required|string|unique:users,phone',
            'role_id' => 'required|exists:roles,id',
            'password' => 'nullable|string|min:8',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            
            // Custom error message for email uniqueness
            if ($errors->has('email')) {
                $emailErrors = $errors->get('email');
                foreach ($emailErrors as $error) {
                    if (str_contains($error, 'already been taken') || str_contains($error, 'unique')) {
                        return response()->json([
                            'success' => false,
                            'message' => 'This email address is already registered. Please use a different email.',
                            'errors' => $errors
                        ], 422);
                    }
                }
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $errors
            ], 422);
        }

        // Get the role
        try {
            $role = Role::findOrFail($request->role_id);
            
            // Only allow store owners to assign store-specific staff roles
            // Store staff roles start with 'store_' prefix (e.g., store_manager, store_sales)
            if (!str_starts_with($role->name, 'store_') || $role->name === 'store_owner') {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only assign store staff roles. Please select a valid store role.'
                ], 403);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid role selected',
                'error' => 'Role not found'
            ], 404);
        }

        // Generate random password if not provided
        $plainPassword = $request->password ?? Str::random(12);

        try {
            $staff = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'role' => 'staff',
                'role_id' => $request->role_id,
                'store_id' => $store->id, // Assign to store owner's store
                'password' => Hash::make($plainPassword),
                'is_active' => $request->is_active ?? true,
                'email_verified_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Handle duplicate email error at database level
            if ($e->getCode() == 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
                return response()->json([
                    'success' => false,
                    'message' => 'This email address is already registered. Please use a different email.',
                    'errors' => ['email' => ['This email address is already registered.']]
                ], 422);
            }
            throw $e;
        }

        $staff->load('roleRelation');

        // Send welcome email with credentials
        \App\Jobs\SendStaffWelcomeEmail::dispatch($staff, $plainPassword);

        return response()->json([
            'success' => true,
            'message' => 'Staff member created successfully. Welcome email sent.',
            'data' => [
                'id' => $staff->id,
                'name' => $staff->name,
                'email' => $staff->email,
                'phone' => $staff->phone,
                'role' => $staff->role,
                'role_id' => $staff->role_id,
                'store_id' => $staff->store_id,
                'is_active' => $staff->is_active,
                'created_at' => $staff->created_at,
                'roleRelation' => $staff->roleRelation ? [
                    'id' => $staff->roleRelation->id,
                    'name' => $staff->roleRelation->name,
                    'display_name' => $staff->roleRelation->display_name,
                    'description' => $staff->roleRelation->description,
                ] : null,
            ]
        ], 201);
    }

    /**
     * Update a staff member
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        
        // Get the store owned by this user
        $store = Store::where('owner_id', $user->id)->first();
        
        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'No store found for this user'
            ], 404);
        }

        // Find the staff member and ensure they belong to this store
        $staff = User::where('id', $id)
            ->where('role', 'staff')
            ->where('store_id', $store->id)
            ->first();

        if (!$staff) {
            return response()->json([
                'success' => false,
                'message' => 'Staff member not found or does not belong to your store'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $id,
            'phone' => 'sometimes|string|unique:users,phone,' . $id,
            'role_id' => 'sometimes|exists:roles,id',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            
            // Custom error message for email uniqueness
            if ($errors->has('email')) {
                $emailErrors = $errors->get('email');
                foreach ($emailErrors as $error) {
                    if (str_contains($error, 'already been taken') || str_contains($error, 'unique')) {
                        return response()->json([
                            'success' => false,
                            'message' => 'This email address is already registered. Please use a different email.',
                            'errors' => $errors
                        ], 422);
                    }
                }
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $errors
            ], 422);
        }

        // If role_id is being updated, validate it
        if ($request->has('role_id')) {
            $role = Role::find($request->role_id);
            // Only allow store-specific staff roles
            if (!str_starts_with($role->name, 'store_') || $role->name === 'store_owner') {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only assign store staff roles. Please select a valid store role.'
                ], 403);
            }
        }

        try {
            $staff->update($request->only(['name', 'email', 'phone', 'role_id', 'is_active']));
        } catch (\Illuminate\Database\QueryException $e) {
            // Handle duplicate email error at database level
            if ($e->getCode() == 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
                return response()->json([
                    'success' => false,
                    'message' => 'This email address is already registered. Please use a different email.',
                    'errors' => ['email' => ['This email address is already registered.']]
                ], 422);
            }
            throw $e;
        }
        
        $staff->load('roleRelation');

        return response()->json([
            'success' => true,
            'message' => 'Staff member updated successfully',
            'data' => [
                'id' => $staff->id,
                'name' => $staff->name,
                'email' => $staff->email,
                'phone' => $staff->phone,
                'role' => $staff->role,
                'role_id' => $staff->role_id,
                'store_id' => $staff->store_id,
                'is_active' => $staff->is_active,
                'created_at' => $staff->created_at,
                'roleRelation' => $staff->roleRelation ? [
                    'id' => $staff->roleRelation->id,
                    'name' => $staff->roleRelation->name,
                    'display_name' => $staff->roleRelation->display_name,
                    'description' => $staff->roleRelation->description,
                ] : null,
            ]
        ]);
    }

    /**
     * Delete a staff member
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        
        // Get the store owned by this user
        $store = Store::where('owner_id', $user->id)->first();
        
        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'No store found for this user'
            ], 404);
        }

        // Find the staff member and ensure they belong to this store
        $staff = User::where('id', $id)
            ->where('role', 'staff')
            ->where('store_id', $store->id)
            ->first();

        if (!$staff) {
            return response()->json([
                'success' => false,
                'message' => 'Staff member not found or does not belong to your store'
            ], 404);
        }

        $staff->delete();

        return response()->json([
            'success' => true,
            'message' => 'Staff member deleted successfully'
        ]);
    }

    /**
     * Reset staff member password
     */
    public function resetPassword(Request $request, $id)
    {
        $user = $request->user();
        
        // Get the store owned by this user
        $store = Store::where('owner_id', $user->id)->first();
        
        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'No store found for this user'
            ], 404);
        }

        // Find the staff member and ensure they belong to this store
        $staff = User::where('id', $id)
            ->where('role', 'staff')
            ->where('store_id', $store->id)
            ->first();

        if (!$staff) {
            return response()->json([
                'success' => false,
                'message' => 'Staff member not found or does not belong to your store'
            ], 404);
        }

        // Generate new password
        $newPassword = Str::random(12);
        $staff->password = Hash::make($newPassword);
        $staff->save();

        // Send password reset email
        \App\Jobs\SendStaffWelcomeEmail::dispatch($staff, $newPassword);

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully. New credentials sent to staff member.'
        ]);
    }
}
