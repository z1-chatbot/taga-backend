<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'email',
        'type',
        'subject',
        'body',
        'status',
        'error_message',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_SENT = 'sent';
    const STATUS_FAILED = 'failed';

    /**
     * Get the user that owns the email log.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Mark email as sent.
     */
    public function markAsSent()
    {
        $this->update([
            'status' => self::STATUS_SENT,
            'sent_at' => now(),
        ]);
    }

    /**
     * Mark email as failed.
     */
    public function markAsFailed(string $errorMessage)
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Log an email.
     */
    public static function logEmail(
        string $email,
        string $type,
        string $subject,
        ?string $body = null,
        ?int $userId = null
    ) {
        return static::create([
            'user_id' => $userId,
            'email' => $email,
            'type' => $type,
            'subject' => $subject,
            'body' => $body,
            'status' => self::STATUS_PENDING,
        ]);
    }
}
