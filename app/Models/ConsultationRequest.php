<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A shopper's request to speak to a practitioner, serviced as a support ticket.
 *
 * Raised from the floating widget on the storefront. Guests may raise one, so a
 * request is owned either by a `user_id` or by the storefront's guest
 * `session_id` — never both, and never neither.
 */
class ConsultationRequest extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'reference',
        'user_id',
        'session_id',
        'practitioner_type',
        'name',
        'email',
        'phone',
        'preferred_contact',
        'preferred_time',
        'subject',
        'message',
        'status',
        'priority',
        'scheduled_at',
        'assigned_to',
        'resolved_at',
        'last_reply_at',
        'last_reply_by',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'resolved_at' => 'datetime',
        'last_reply_at' => 'datetime',
    ];

    const STATUS_OPEN = 'open';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_SCHEDULED = 'scheduled';
    const STATUS_RESOLVED = 'resolved';
    const STATUS_CLOSED = 'closed';

    const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_IN_PROGRESS,
        self::STATUS_SCHEDULED,
        self::STATUS_RESOLVED,
        self::STATUS_CLOSED,
    ];

    const PRIORITIES = ['low', 'normal', 'high'];

    /**
     * The kinds of practitioner a shopper may ask for.
     *
     * Deliberately a constant rather than a setting: the list is small, changes
     * rarely, and every entry needs a matching person on the other side. Moving
     * it into system settings later needs no schema change — the column is a
     * plain string, validated against whatever this list holds.
     *
     * Keyed slug => label.
     */
    const PRACTITIONER_TYPES = [
        'doctor' => 'Doctor (General Practitioner)',
        'pharmacist' => 'Pharmacist',
        'dentist' => 'Dentist',
        'optometrist' => 'Optometrist',
        'physiotherapist' => 'Physiotherapist',
        'nurse' => 'Nurse',
        'nutritionist' => 'Nutritionist / Dietitian',
        'mental_health' => 'Mental health professional',
        'paediatrician' => 'Paediatrician',
        'other' => 'Not sure / other',
    ];

    public static function practitionerTypeOptions(): array
    {
        return collect(self::PRACTITIONER_TYPES)
            ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
    }

    public function practitionerLabel(): string
    {
        return self::PRACTITIONER_TYPES[$this->practitioner_type] ?? $this->practitioner_type;
    }

    /**
     * A reference the requester can quote back. Ambiguous characters (0/O, 1/I)
     * are left out because these get read down a phone line.
     */
    public static function generateReference(): string
    {
        do {
            // Substituted on the random part only — running it over the whole
            // string turned the CON- prefix itself into C3N-.
            $suffix = str_replace(
                ['0', 'O', '1', 'I'],
                ['2', '3', '4', '5'],
                Str::upper(Str::random(6))
            );

            $reference = 'CON-'.$suffix;
        } while (self::withTrashed()->where('reference', $reference)->exists());

        return $reference;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** The whole thread, internal notes included. Customer endpoints must filter. */
    public function messages()
    {
        return $this->hasMany(ConsultationMessage::class)->orderBy('created_at');
    }

    /** Replies both sides can see. */
    public function publicMessages()
    {
        return $this->messages()->where('is_internal', false);
    }

    public function scopeOpenTickets($query)
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_SCHEDULED]);
    }

    /** Settled tickets take no further replies from either side. */
    public function isSettled(): bool
    {
        return in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_CLOSED], true);
    }

    /**
     * Whether the given caller owns this request.
     *
     * A guest is matched on their storefront session id. Once they sign up the
     * ticket is claimed onto the account, and the session id stops mattering.
     */
    public function isOwnedBy(?User $user, ?string $sessionId): bool
    {
        if ($user && $this->user_id === $user->id) {
            return true;
        }

        return $this->user_id === null
            && $sessionId !== null
            && $this->session_id === $sessionId;
    }
}
