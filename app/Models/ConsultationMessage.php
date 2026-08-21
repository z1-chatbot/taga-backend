<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry in a consultation thread.
 *
 * Internal notes share the table with replies: they are the same conversation
 * from the staff side, and keeping them together is what makes the ticket
 * readable in order. `is_internal` is the only thing keeping them off the
 * customer's screen, so every customer-facing query must filter on it.
 */
class ConsultationMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'consultation_request_id',
        'author_type',
        'user_id',
        'author_name',
        'body',
        'is_internal',
    ];

    protected $casts = [
        'is_internal' => 'boolean',
    ];

    const AUTHOR_CUSTOMER = 'customer';
    const AUTHOR_ADMIN = 'admin';

    public function request()
    {
        return $this->belongsTo(ConsultationRequest::class, 'consultation_request_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
