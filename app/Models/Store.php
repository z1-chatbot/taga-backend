<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Store extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'owner_id',
        'name',
        'slug',
        'description',
        'logo',
        'banner',
        'phone',
        'email',
        'state',
        'city',
        'account_name',
        'account_number',
        'bank_name',
        'bank_code',
        'address',
        'business_hours',
        'commission_rate',
        'status',
        'settings',
        // Pharmacy licensing
        'pharmacy_license_number',
        'pharmacy_license_expiry',
        'pharmacy_license_document',
        'premises_registration_number',
        'superintendent_pharmacist_name',
        'superintendent_pharmacist_license',
        'superintendent_pharmacist_phone',
        'verification_status',
        'verified_at',
        'verified_by',
        'verification_notes',
        'can_sell_prescription',
        'can_sell_controlled',
        'licence_reminder_stage'
    ];

    protected $casts = [
        'address' => 'array',
        'business_hours' => 'array',
        'settings' => 'array',
        'commission_rate' => 'decimal:2',
        'pharmacy_license_expiry' => 'date',
        'verified_at' => 'datetime',
        'can_sell_prescription' => 'boolean',
        'can_sell_controlled' => 'boolean'
    ];

    const VERIFICATION_UNSUBMITTED = 'unsubmitted';
    const VERIFICATION_PENDING = 'pending';
    const VERIFICATION_APPROVED = 'approved';
    const VERIFICATION_REJECTED = 'rejected';

    /**
     * An approved licence that has not lapsed.
     *
     * Expiry is checked separately from verification_status because a licence can
     * lapse long after approval — a store that was verified last year is not
     * automatically still licensed today.
     */
    public function isLicenceValid(): bool
    {
        if ($this->verification_status !== self::VERIFICATION_APPROVED) {
            return false;
        }

        return ! $this->pharmacy_license_expiry || ! $this->pharmacy_license_expiry->isPast();
    }

    /**
     * Whether this store may sell anything at all.
     *
     * A pharmacy can register, set itself up and build a catalogue while its
     * licence is being reviewed — but nothing it lists is purchasable until that
     * licence is approved and current. Regulated stock has its own further
     * grants on top (can_sell_prescription, can_sell_controlled); this is the
     * floor beneath them, and it applies to plain over-the-counter stock too.
     *
     * Before this existed only Rx and controlled listings were gated, so an
     * unvetted pharmacy could register and start taking money for OTC medicine
     * the same afternoon.
     */
    public function canSell(): bool
    {
        return $this->status === 'active' && $this->isLicenceValid();
    }

    /**
     * Why this store cannot sell, in words a shop owner can act on.
     */
    public function sellingBlockedReason(): ?string
    {
        if ($this->canSell()) {
            return null;
        }

        if ($this->status !== 'active') {
            return 'This store is not active.';
        }

        return match ($this->verification_status) {
            self::VERIFICATION_UNSUBMITTED => 'This store has not submitted its pharmacy licence yet.',
            self::VERIFICATION_PENDING => "This store's pharmacy licence is still being reviewed.",
            self::VERIFICATION_REJECTED => "This store's pharmacy licence was not accepted.",
            default => "This store's pharmacy licence has expired.",
        };
    }

    public function scopePendingVerification($query)
    {
        return $query->where('verification_status', self::VERIFICATION_PENDING);
    }

    /**
     * Stores whose stock may actually be bought — see canSell().
     */
    public function scopeSellable($query)
    {
        return $query->where('status', 'active')
            ->where('verification_status', self::VERIFICATION_APPROVED)
            ->where(function ($q) {
                $q->whereNull('pharmacy_license_expiry')
                  ->orWhereDate('pharmacy_license_expiry', '>=', now()->toDateString());
            });
    }

    public function prescriptions()
    {
        return $this->hasMany(Prescription::class);
    }

    // Relationships
    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function payouts()
    {
        return $this->hasMany(StorePayout::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }


    // Accessors
    public function getTotalProductsAttribute()
    {
        return $this->products()->count();
    }

    public function getTotalOrdersAttribute()
    {
        return $this->orders()->count();
    }

    public function getTotalRevenueAttribute()
    {
        return $this->orders()
            ->where('payment_status', 'paid')
            ->sum('total_amount');
    }

    public function getPendingPayoutAttribute()
    {
        $totalRevenue = $this->total_revenue;
        $paidPayouts = $this->payouts()
            ->where('status', 'completed')
            ->sum('net_amount');
        
        return $totalRevenue - $paidPayouts;
    }

    // Methods
    public function generateSlug()
    {
        // Same separator handling as categories — a shop called "Chemist/Plus"
        // would otherwise become "chemistplus".
        $slug = \App\Support\Slug::make($this->name);
        $count = static::where('slug', 'like', "{$slug}%")->count();
        
        return $count ? "{$slug}-{$count}" : $slug;
    }

    /**
     * The platform's cut of $amount — deducted from what this pharmacy is paid,
     * never added to it. Callers subtract the result and store it as
     * `commission_deducted`.
     */
    public function calculateCommission($amount)
    {
        return ($amount * $this->commission_rate) / 100;
    }


    public function suspend()
    {
        $this->update(['status' => 'suspended']);
    }

    public function activate()
    {
        $this->update(['status' => 'active']);
    }
}
