<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class UserController extends Controller
{
    /**
     * Account management belongs to the platform, not to a shop.
     *
     * Nothing here is scoped to a store — index() lists every customer on the
     * platform with their name, email and phone, and update()/destroy() reach
     * any non-admin account, including other pharmacies' owners. There is no
     * store.users.* permission, so no shipped role grants this; a custom role
     * created in the Roles page could, and it must not open the whole directory.
     */
    private function denyStoreAccount(): ?JsonResponse
    {
        $user = request()->user();

        if ($user && $user->isPlatformAdmin()) {
            return null;
        }

        if ($user && $user->storeScopeId() !== null) {
            return response()->json([
                'success' => false,
                'message' => 'Account management is handled by the platform, not by individual stores.',
                'code' => 'platform_only',
            ], 403);
        }

        return null;
    }

    /**
     * Display a listing of users with filtering and pagination
     */
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->denyStoreAccount()) {
            return $denied;
        }

        /*
         * Every account, administrators included.
         *
         * This used to be `->where('role', '!=', 'admin')`, which hid admin
         * accounts outright — so creating an administrator through this very
         * page produced a user that then could not be found on it. The account
         * existed and the list simply never showed it.
         *
         * Hiding them was not a safeguard either, because the destructive
         * actions were guarded separately (and toggleStatus was not guarded at
         * all until now). Only platform staff reach this endpoint: a store
         * account is refused by denyStoreAccount() above, and the route
         * requires users.view.
         */
        $query = User::with('roleRelation');

        // Apply filters
        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($request->filled('role')) {
            $role = $request->get('role');

            // Match the string column or the related role. Staff accounts have
            // both set and agree, but a row whose `role` string drifted from
            // its role_id would otherwise be unfindable by either name.
            $query->where(function ($q) use ($role) {
                $q->where('role', $role)
                    ->orWhereHas('roleRelation', fn ($r) => $r->where('name', $role));
            });
        }

        if ($request->filled('role_id')) {
            $query->where('role_id', $request->get('role_id'));
        }

        if ($request->filled('status')) {
            $isActive = $request->get('status') === 'active';
            $query->where('is_active', $isActive);
        }

        if ($request->filled('verified')) {
            if ($request->get('verified') === 'verified') {
                $query->whereNotNull('email_verified_at');
            } else {
                $query->whereNull('email_verified_at');
            }
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->get('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->get('date_to'));
        }

        // Add order statistics
        $query->withCount(['orders as orders_count'])
              ->withSum(['orders as total_spent' => function($q) {
                  $q->where('payment_status', 'paid');
              }], 'total_amount');

        // Pagination
        $perPage = $request->get('per_page', 20);
        $users = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $users->items(),
            'pagination' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ]
        ]);
    }

    /**
     * Display the specified user
     */
    public function show($id): JsonResponse
    {
        if ($denied = $this->denyStoreAccount()) {
            return $denied;
        }

        $user = User::with(['orders' => function($query) {
                        $query->latest()->limit(10);
                    }])
                    ->withCount('orders')
                    ->withSum(['orders as total_spent' => function($q) {
                        $q->where('payment_status', 'paid');
                    }], 'total_amount')
                    ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $user
        ]);
    }

    /**
     * Update the specified user
     */
    public function update(Request $request, $id): JsonResponse
    {
        if ($denied = $this->denyStoreAccount()) {
            return $denied;
        }

        $user = User::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $id,
            'phone' => 'sometimes|nullable|string|max:20',
            /*
             * Was `in:admin,customer,vendor`. Two faults in one line: "vendor"
             * is not a role this system has ever had, and every real staff role
             * (manager, support, store_owner and the rest) was rejected — so
             * changing a staff member's role here always failed validation.
             *
             * It also let anyone holding users.edit set a role of "admin",
             * which a manager holds. Granting yourself or a colleague admin was
             * a single PUT away. The rule below accepts the roles that exist,
             * and the check after it is what stops a non-admin handing out
             * administrator.
             */
            'role' => ['sometimes', Rule::in(self::assignableRoleNames())],
            'role_id' => 'sometimes|nullable|exists:roles,id',
            'is_active' => 'sometimes|boolean',
            'password' => 'sometimes|string|min:8|confirmed',
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

        // Granting administrator is an administrator's decision.
        if (($request->input('role') === 'admin' || $this->rolesToAdmin($request))
            && ! $request->user()?->isPlatformAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Only an administrator can grant administrator access.',
            ], 403);
        }

        $data = $request->only(['name', 'email', 'phone', 'role', 'role_id', 'is_active']);

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        try {
            $user->update($data);
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

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'data' => $user->fresh()->load('roleRelation')
        ]);
    }

    /**
     * Create a new staff user (Admin only)
     */
    public function createStaff(Request $request): JsonResponse
    {
        if ($denied = $this->denyStoreAccount()) {
            return $denied;
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:20',
            'role_id' => 'required|exists:roles,id',
            // Which shop this person works for. Staff were created without one
            // at all, so even a correct store filter had nothing to filter by
            // and they fell through to seeing the whole platform.
            'store_id' => 'nullable|exists:stores,id',
            'password' => 'required|string|min:8|confirmed',
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

        // Get the role to set the legacy role field
        try {
            $role = Role::findOrFail($request->role_id);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid role selected. Please ensure roles are properly seeded in the database.',
                'error' => 'Role not found'
            ], 404);
        }

        // A role whose permissions are all store-scoped describes somebody who
        // works in one shop, and they are useless — and unsafe — without one.
        $isStoreRole = $role->permissions()->where('name', 'like', 'store.%')->exists();

        if ($isStoreRole && ! $request->filled('store_id')) {
            return response()->json([
                'success' => false,
                'message' => "The {$role->display_name} role works within a single store. Choose which store this person belongs to.",
                'code' => 'store_required_for_role',
            ], 422);
        }

        // Store plain password before hashing to send in email
        $plainPassword = $request->password;

        try {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'role' => $role->name, // Use the actual role name (e.g., 'store_owner', 'staff', etc.)
                'role_id' => $request->role_id,
                'store_id' => $isStoreRole ? $request->store_id : null,
                'password' => Hash::make($request->password),
                'is_active' => $request->is_active ?? true,
                'email_verified_at' => now(), // Staff users are pre-verified
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

        $user->load('roleRelation');

        // Send welcome email with credentials
        \App\Jobs\SendStaffWelcomeEmail::dispatch($user, $plainPassword);

        return response()->json([
            'success' => true,
            'message' => 'Staff user created successfully. Welcome email sent.',
            'data' => $user
        ], 201);
    }

    /**
     * Get available roles for assignment (excludes store staff roles)
     */
    /**
     * Roles, for a dropdown.
     *
     * Two callers want two different lists, so `?all=1` picks which.
     *
     * Assignment (the default) excludes store-specific roles: those are handed
     * out on the store's own staff page, not here.
     *
     * Filtering wants everything, because the user list *does* contain store
     * staff — a shop's manager or sales assistant is a user like any other. A
     * filter offering only the assignable roles cannot find them, which is how
     * the dropdown ended up listing two roles, one of which ("vendor") has
     * never existed in this system and so always returned nothing.
     */

    /**
     * Role names this endpoint will accept on a user.
     *
     * Read from the roles table rather than written out, so a role added to the
     * seeder is immediately assignable instead of being rejected by a list
     * nobody remembered to update. 'customer' is added explicitly: customers
     * carry role='customer' with no row in `roles`, so it exists as a value
     * without existing as a record.
     */
    private static function assignableRoleNames(): array
    {
        return Role::query()->pluck('name')->push('customer')->unique()->values()->all();
    }

    /**
     * Whether this request would put the user into the admin role by id.
     *
     * Checked separately from the `role` string because role_id is the other
     * way in: setting role_id to the admin role grants exactly the same access
     * without the word "admin" appearing anywhere in the payload.
     */
    private function rolesToAdmin(Request $request): bool
    {
        if (! $request->filled('role_id')) {
            return false;
        }

        return Role::where('id', $request->input('role_id'))->value('name') === 'admin';
    }

    public function getRoles(Request $request): JsonResponse
    {
        $query = Role::active()->ordered();

        if (! $request->boolean('all')) {
            $query->where(function ($q) {
                $q->where('name', 'not like', 'store_%')
                    ->orWhere('name', '=', 'store_owner'); // Keep store_owner for admin assignment
            });
        }

        return response()->json([
            'success' => true,
            'data' => $query->get(['id', 'name', 'display_name', 'description']),
        ]);
    }

    /**
     * Toggle user active status
     */
    public function toggleStatus(Request $request, $id): JsonResponse
    {
        if ($denied = $this->denyStoreAccount()) {
            return $denied;
        }

        $user = User::findOrFail($id);

        /*
         * Only an administrator may switch another administrator off.
         *
         * There was no check here at all. `users.manage_status` belongs to the
         * manager role as well, so any manager could deactivate an admin
         * account — and now that administrators are visible in the list above,
         * that would have been one click away. Deletion was already guarded;
         * deactivation locks someone out just as effectively.
         */
        if ($user->role === 'admin' && ! $request->user()?->isPlatformAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Only an administrator can change another administrator\'s status.',
            ], 403);
        }

        $user->update([
            'is_active' => !$user->is_active
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User status updated successfully',
            'data' => $user->fresh()
        ]);
    }

    /**
     * Remove the specified user
     */
    public function destroy($id): JsonResponse
    {
        if ($denied = $this->denyStoreAccount()) {
            return $denied;
        }

        $user = User::findOrFail($id);

        // Prevent deletion of admin users (optional safety check)
        if ($user->role === 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete admin users'
            ], 403);
        }

        // Check if user has orders
        if ($user->orders()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete user with existing orders. Deactivate instead.'
            ], 409);
        }

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully'
        ]);
    }

    /**
     * Get user statistics
     */
    public function stats(): JsonResponse
    {
        if ($denied = $this->denyStoreAccount()) {
            return $denied;
        }

        $today = Carbon::today();
        $thisMonth = Carbon::now()->startOfMonth();

        $stats = [
            'total_users' => User::count(),
            'active_users' => User::where('is_active', true)->count(),
            'verified_users' => User::whereNotNull('email_verified_at')->count(),
            'new_today' => User::whereDate('created_at', $today)->count(),
            'new_this_month' => User::where('created_at', '>=', $thisMonth)->count(),
            'customers' => User::where('role', 'customer')->count(),
            'admins' => User::where('role', 'admin')->count(),
            'vendors' => User::where('role', 'vendor')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Change user password
     */
    public function changePassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        // Verify current password
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Current password is incorrect'
            ], 401);
        }

        // Update password
        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully'
        ]);
    }
}
