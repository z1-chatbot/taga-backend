<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A customer-uploaded prescription authorising Rx line items.
 *
 * Review is currently performed by the selling store. `reviewed_by_type` distinguishes
 * a store reviewer from a platform pharmacist so the platform-side workflow can be
 * introduced later without a schema change.
 */
class Prescription extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'session_id',
        'order_id',
        'store_id',
        'file_path',
        'original_filename',
        'mime_type',
        'file_size',
        'patient_name',
        'doctor_name',
        'doctor_license',
        'hospital_name',
        'issued_date',
        'expires_at',
        'status',
        'reviewed_by_type',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
        'notes',
    ];

    protected $casts = [
        'issued_date' => 'date',
        'expires_at' => 'date',
        'reviewed_at' => 'datetime',
        'file_size' => 'integer',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_EXPIRED = 'expired';

    const REVIEWER_STORE = 'store';
    const REVIEWER_PLATFORM = 'platform';

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeForStore($query, $storeId)
    {
        return $query->where('store_id', $storeId);
    }

    /**
     * Approved and not past its validity date.
     */
    public function isUsable(): bool
    {
        if ($this->status !== self::STATUS_APPROVED) {
            return false;
        }

        return ! $this->expires_at || ! $this->expires_at->isPast();
    }

    public function approve(?User $reviewer = null, ?string $notes = null): bool
    {
        return $this->update([
            'status' => self::STATUS_APPROVED,
            'reviewed_by' => $reviewer?->id,
            'reviewed_by_type' => self::REVIEWER_STORE,
            'reviewed_at' => now(),
            'notes' => $notes ?? $this->notes,
        ]);
    }

    public function reject(?User $reviewer = null, ?string $reason = null): bool
    {
        return $this->update([
            'status' => self::STATUS_REJECTED,
            'reviewed_by' => $reviewer?->id,
            'reviewed_by_type' => self::REVIEWER_STORE,
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }
}
