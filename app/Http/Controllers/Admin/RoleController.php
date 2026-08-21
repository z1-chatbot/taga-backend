<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class RoleController extends Controller
{
    /**
     * Get all roles with their permissions
     */
    public function index(Request $request): JsonResponse
    {
        $query = Role::with('permissions');

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->is_active);
        }

        $roles = $query->ordered()->get()->map(function ($role) {
            return [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->display_name,
                'description' => $role->description,
                'is_active' => $role->is_active,
                'sort_order' => $role->sort_order,
                'users_count' => $role->users()->count(),
                'permissions' => $role->permissions->pluck('name'),
                'permissions_count' => $role->permissions->count(),
                'created_at' => $role->created_at,
                'updated_at' => $role->updated_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $roles
        ]);
    }

    /**
     * Get current user's role with permissions (for permission checking)
     * This endpoint allows staff users to fetch their own role permissions
     */
    public function getMyRole(Request $request): JsonResponse
    {
        $user = $request->user();
        
        // Handle store_owner role by name if role_id is not set
        if (!$user->role_id && $user->role === 'store_owner') {
            $role = Role::with('permissions')->where('name', 'store_owner')->first();
            
            if ($role) {
                // Update user's role_id for future requests
                $user->update(['role_id' => $role->id]);
            }
        } else {
            $role = $user->role_id ? Role::with('permissions')->find($user->role_id) : null;
        }
        
        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'User has no assigned role'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->display_name,
                'permissions' => $role->permissions->map(function ($permission) {
                    return [
                        'id' => $permission->id,
                        'name' => $permission->name,
                        'display_name' => $permission->display_name,
                        'group' => $permission->group,
                    ];
                }),
            ]
        ]);
    }

    /**
     * Get a single role with details (Admin only)
     */
    public function show($id): JsonResponse
    {
        $role = Role::with(['permissions', 'users'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->display_name,
                'description' => $role->description,
                'is_active' => $role->is_active,
                'sort_order' => $role->sort_order,
                'permissions' => $role->permissions->map(function ($permission) {
                    return [
                        'id' => $permission->id,
                        'name' => $permission->name,
                        'display_name' => $permission->display_name,
                        'group' => $permission->group,
                    ];
                }),
                'users' => $role->users->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'is_active' => $user->is_active,
                    ];
                }),
                'created_at' => $role->created_at,
                'updated_at' => $role->updated_at,
            ]
        ]);
    }

    /**
     * Create a new role
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|unique:roles,name|max:255',
            'display_name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0',
            'permissions' => 'array',
            'permissions.*' => 'exists:permissions,name'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $role = Role::create([
            'name' => $request->name,
            'display_name' => $request->display_name,
            'description' => $request->description,
            'is_active' => $request->is_active ?? true,
            'sort_order' => $request->sort_order ?? 0,
        ]);

        if ($request->has('permissions')) {
            $role->syncPermissions($request->permissions);
        }

        $role->load('permissions');

        return response()->json([
            'success' => true,
            'message' => 'Role created successfully',
            'data' => $role
        ], 201);
    }

    /**
     * Update an existing role
     */
    public function update(Request $request, $id): JsonResponse
    {
        $role = Role::findOrFail($id);

        // Prevent editing the admin role
        if ($role->name === Role::ADMIN) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot modify the administrator role'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'string|unique:roles,name,' . $id . '|max:255',
            'display_name' => 'string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0',
            'permissions' => 'array',
            'permissions.*' => 'exists:permissions,name'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $role->update($request->only([
            'name',
            'display_name',
            'description',
            'is_active',
            'sort_order'
        ]));

        if ($request->has('permissions')) {
            $role->syncPermissions($request->permissions);
        }

        $role->load('permissions');

        return response()->json([
            'success' => true,
            'message' => 'Role updated successfully',
            'data' => $role
        ]);
    }

    /**
     * Delete a role
     */
    public function destroy($id): JsonResponse
    {
        $role = Role::findOrFail($id);

        // Prevent deleting the admin role
        if ($role->name === Role::ADMIN) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete the administrator role'
            ], 403);
        }

        // Check if role has users
        if ($role->users()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete role with assigned users. Please reassign users first.'
            ], 422);
        }

        $role->delete();

        return response()->json([
            'success' => true,
            'message' => 'Role deleted successfully'
        ]);
    }

    /**
     * Get all available permissions grouped by category
     */
    public function getPermissions(): JsonResponse
    {
        $permissions = Permission::all()->groupBy('group')->map(function ($group) {
            return $group->map(function ($permission) {
                return [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'display_name' => $permission->display_name,
                    'description' => $permission->description,
                ];
            });
        });

        return response()->json([
            'success' => true,
            'data' => [
                'permissions' => $permissions,
                'groups' => Permission::getGroups()
            ]
        ]);
    }

    /**
     * Toggle role active status
     */
    public function toggleStatus($id): JsonResponse
    {
        $role = Role::findOrFail($id);

        // Prevent deactivating the admin role
        if ($role->name === Role::ADMIN) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot deactivate the administrator role'
            ], 403);
        }

        $role->is_active = !$role->is_active;
        $role->save();

        return response()->json([
            'success' => true,
            'message' => 'Role status updated successfully',
            'data' => $role
        ]);
    }
}
