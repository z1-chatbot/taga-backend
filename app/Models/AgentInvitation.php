<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Carbon\Carbon;

class AgentInvitation extends Model
{
    use HasFactory;

    protected $fillable = [
        'logistics_company_id',
        'email',
        'phone',
        'name',
        'token',
        'status',
        'expires_at',
        'accepted_at',
        'delivery_agent_id',
        'metadata'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    // Relationships
    public function logisticsCompany()
    {
        return $this->belongsTo(LogisticsCompany::class);
    }

    public function deliveryAgent()
    {
        return $this->belongsTo(DeliveryAgent::class);
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending')
                    ->where('expires_at', '>', now());
    }

    public function scopeExpired($query)
    {
        return $query->where('status', 'pending')
                    ->where('expires_at', '<=', now());
    }

    public function scopeAccepted($query)
    {
        return $query->where('status', 'accepted');
    }

    public function scopeForCompany($query, $companyId)
    {
        return $query->where('logistics_company_id', $companyId);
    }

    // Methods
    public function isExpired()
    {
        return $this->status === 'pending' && $this->expires_at <= now();
    }

    public function isValid()
    {
        return $this->status === 'pending' && $this->expires_at > now();
    }

    public function accept(DeliveryAgent $agent)
    {
        $this->update([
            'status' => 'accepted',
            'accepted_at' => now(),
            'delivery_agent_id' => $agent->id
        ]);
    }

    public function cancel()
    {
        $this->update(['status' => 'cancelled']);
    }

    public function markAsExpired()
    {
        if ($this->isExpired()) {
            $this->update(['status' => 'expired']);
        }
    }

    public function resend($expirationDays = 7)
    {
        $this->update([
            'token' => self::generateToken(),
            'expires_at' => Carbon::now()->addDays($expirationDays),
            'status' => 'pending'
        ]);

        // TODO: Send invitation email/SMS
    }

    // Static methods
    public static function generateToken()
    {
        do {
            $token = Str::random(64);
        } while (self::where('token', $token)->exists());

        return $token;
    }

    public static function createInvitation($logisticsCompanyId, $email, $phone, $name, $metadata = [], $expirationDays = 7)
    {
        return self::create([
            'logistics_company_id' => $logisticsCompanyId,
            'email' => $email,
            'phone' => $phone,
            'name' => $name,
            'token' => self::generateToken(),
            'status' => 'pending',
            'expires_at' => Carbon::now()->addDays($expirationDays),
            'metadata' => $metadata
        ]);
    }
}
