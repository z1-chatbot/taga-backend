<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliveryProof extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'delivery_agent_id',
        'type',
        'photos',
        'signature_image',
        'recipient_name',
        'recipient_phone',
        'notes',
        'location',
        'verified_at'
    ];

    protected $casts = [
        'photos' => 'array',
        'verified_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    // Relationships
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function deliveryAgent()
    {
        return $this->belongsTo(DeliveryAgent::class);
    }

    // Scopes
    public function scopePickup($query)
    {
        return $query->where('type', 'pickup');
    }

    public function scopeDelivery($query)
    {
        return $query->where('type', 'delivery');
    }

    public function scopeVerified($query)
    {
        return $query->whereNotNull('verified_at');
    }

    // Methods
    public function verify()
    {
        $this->update(['verified_at' => now()]);
    }

    public function isVerified()
    {
        return !is_null($this->verified_at);
    }
}
