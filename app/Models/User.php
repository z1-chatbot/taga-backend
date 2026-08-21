<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'role_id',
        'store_id',
        'phone',
        'is_active',
        'email_verified_at',
        'email_verification_token',
        'email_verification_sent_at',
        'google_id',
        'avatar',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'email_verification_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'email_verification_sent_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the orders for the user.
     */
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get the products for the user (for store owners).
     */
    public function products()
    {
        return $this->hasMany(Product::class, 'user_id');
    }

    /**
     * Get the default address for the user.
     */
    public function defaultAddress()
    {
        return $this->hasOne(Address::class)->where('is_default', true);
    }

    /**
     * Get the reviews for the user.
     */
    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    /**
     * Get the cart items for the user.
     */
    public function cartItems()
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * Get the addresses for the user.
     */
    public function addresses()
    {
        return $this->hasMany(Address::class);
    }

    /**
     * Get the role for the user.
     */
    public function roleRelation()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * Get the store for the user (for store owners).
     */
    public function store()
    {
        return $this->hasOne(\App\Models\Store::class, 'owner_id');
    }

    /**
     * The store this account is confined to, or null if it sees the platform.
     *
     * This is the single answer to "whose data is this person allowed to see".
     * Every admin controller used to decide that for itself, by comparing the
     * legacy `role` string against the literals 'store_owner' and 'staff'. That
     * column now holds the *role's* name, so the four store roles the platform
     * ships — store_manager, store_sales, store_inventory, store_support —
     * matched neither literal and every filter was skipped: a shop manager
     * signing in saw every other pharmacy's products, orders and takings.
     *
     * Scope is decided by the store a person is attached to, not by what their
     * role is called, so a role added later is covered without touching this.
     */
    public function storeScopeId(): ?int
    {
        if ($this->isPlatformAdmin()) {
            return null;
        }

        if ($this->store_id) {
            return (int) $this->store_id;
        }

        return $this->store?->id;
    }

    /**
     * Whether this account governs the whole platform rather than one shop.
     */
    public function isPlatformAdmin(): bool
    {
        return $this->role === 'admin'
            || ($this->roleRelation && $this->roleRelation->name === Role::ADMIN);
    }

    /**
     * True when this account should only ever see one store's data but has no
     * store attached — which must fail closed rather than show everything.
     *
     * Staff were created without a store_id at all, so even a correct filter had
     * nothing to filter by.
     */
    public function isUnscopedNonAdmin(): bool
    {
        return ! $this->isPlatformAdmin() && $this->storeScopeId() === null;
    }

    /**
     * Check if user has a specific permission.
     * Also checks for store-scoped version (e.g., store.products.view for products.view)
     */
    public function hasPermission(string $permissionName): bool
    {
        // Admin role has all permissions
        if ($this->role === 'admin' || $this->isAdmin()) {
            return true;
        }

        if (!$this->roleRelation) {
            return false;
        }

        // Check if role has the permission (this now checks both exact and store-scoped)
        return $this->roleRelation->hasPermission($permissionName);
    }

    /**
     * Check if user has any of the given permissions.
     */
    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if user has all of the given permissions.
     */
    public function hasAllPermissions(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->hasPermission($permission)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check if user is admin.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin' || 
               ($this->roleRelation && $this->roleRelation->name === Role::ADMIN);
    }

    /**
     * Get all permissions for the user.
     */
    public function getAllPermissions()
    {
        if ($this->isAdmin()) {
            return Permission::all();
        }

        if (!$this->roleRelation) {
            return collect([]);
        }

        return $this->roleRelation->permissions;
    }

    /**
     * Check if user has verified their email.
     */
    public function hasVerifiedEmail(): bool
    {
        return !is_null($this->email_verified_at);
    }

    /**
     * Mark the user's email as verified.
     */
    public function markEmailAsVerified(): bool
    {
        return $this->forceFill([
            'email_verified_at' => now(),
            'email_verification_token' => null,
        ])->save();
    }

    /**
     * Generate email verification token.
     */
    public function generateEmailVerificationToken(): string
    {
        $token = bin2hex(random_bytes(32));
        
        $this->forceFill([
            'email_verification_token' => hash('sha256', $token),
            'email_verification_sent_at' => now(),
        ])->save();
        
        return $token;
    }
}
