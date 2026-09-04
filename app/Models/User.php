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
        'auth_provider',
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

    /** How an account proves who it is. See the auth_provider column. */
    public const AUTH_PASSWORD = 'password';

    public const AUTH_GOOGLE = 'google';

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
     * Whether this account signs in through Google rather than with a password.
     *
     * Reads the stored provider, never the password column. An absent password
     * happens to mean the same thing today, but it is an absence standing in for
     * a fact: anything else that ever produces a passwordless account would
     * start being told to sign in with Google.
     *
     * `auth_provider` is only ever set at creation. Linking Google to an account
     * that already has a password does not change it — that account still has
     * its password and still gets a password form.
     */
    public function signsInWithGoogle(): bool
    {
        return $this->auth_provider === self::AUTH_GOOGLE;
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
    /**
     * The specialties this member of staff answers consultations for.
     *
     * Empty for everyone who is not a practitioner, which is almost everyone.
     */
    public function practitionerTypes()
    {
        return $this->belongsToMany(PractitionerType::class, 'practitioner_type_user')
            ->withTimestamps();
    }

    /** True when this account exists to answer the consultation queue. */
    public function isPractitioner(): bool
    {
        return $this->role === Role::PRACTITIONER
            || ($this->roleRelation && $this->roleRelation->name === Role::PRACTITIONER);
    }

    /**
     * The slugs this account may act on, or null when it may act on everything.
     *
     * Null rather than "all the slugs": a practitioner with no specialty set
     * yet must see nothing, and an empty list has to stay distinguishable from
     * no restriction at all.
     */
    public function consultationScope(): ?array
    {
        if ($this->isPlatformAdmin() || $this->hasPermission('consultations.manage')) {
            return null;
        }

        return $this->practitionerTypes()->pluck('slug')->all();
    }

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
