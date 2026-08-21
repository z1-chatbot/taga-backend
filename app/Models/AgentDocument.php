<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class AgentDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'delivery_agent_id',
        'document_type',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
        'verification_status',
        'rejection_reason',
        'verified_by',
        'verified_at'
    ];

    protected $casts = [
        'file_size' => 'integer',
        'verified_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    // Relationships
    public function deliveryAgent()
    {
        return $this->belongsTo(DeliveryAgent::class);
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('verification_status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('verification_status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('verification_status', 'rejected');
    }

    public function scopeForAgent($query, $agentId)
    {
        return $query->where('delivery_agent_id', $agentId);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('document_type', $type);
    }

    // Methods
    public function approve($userId)
    {
        $this->update([
            'verification_status' => 'approved',
            'verified_by' => $userId,
            'verified_at' => now(),
            'rejection_reason' => null
        ]);

        // Check if all required documents are approved
        $this->deliveryAgent->checkVerificationStatus();
    }

    public function reject($userId, $reason)
    {
        $this->update([
            'verification_status' => 'rejected',
            'verified_by' => $userId,
            'verified_at' => now(),
            'rejection_reason' => $reason
        ]);
    }

    public function getFileUrl()
    {
        return Storage::url($this->file_path);
    }

    public function delete()
    {
        // Delete the file from storage
        if (Storage::exists($this->file_path)) {
            Storage::delete($this->file_path);
        }

        return parent::delete();
    }

    // Document type constants
    const TYPE_GOVERNMENT_ID = 'government_id';
    const TYPE_DRIVERS_LICENSE = 'drivers_license';
    const TYPE_VEHICLE_REGISTRATION = 'vehicle_registration';
    const TYPE_PROFILE_PHOTO = 'profile_photo';
    const TYPE_INSURANCE = 'insurance';
    const TYPE_OTHER = 'other';

    public static function getDocumentTypes()
    {
        return [
            self::TYPE_GOVERNMENT_ID,
            self::TYPE_DRIVERS_LICENSE,
            self::TYPE_VEHICLE_REGISTRATION,
            self::TYPE_PROFILE_PHOTO,
            self::TYPE_INSURANCE,
            self::TYPE_OTHER
        ];
    }

    public static function getRequiredDocuments()
    {
        return [
            self::TYPE_GOVERNMENT_ID,
            self::TYPE_DRIVERS_LICENSE,
            self::TYPE_PROFILE_PHOTO
        ];
    }
}
