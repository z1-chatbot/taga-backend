<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A kind of practitioner a shopper can ask to speak to.
 *
 * Curated by an administrator. Every entry needs a matching person on the other
 * side, so this is a deliberate list rather than free text — but which kinds
 * the platform offers changes as it signs up new practitioners, and that should
 * not need a deployment.
 *
 * Consultations store the slug, not a foreign key. That is on purpose: a
 * consultation is a record of what was asked for at the time, and it has to
 * stay readable after the type it named is renamed or withdrawn.
 */
class PractitionerType extends Model
{
    protected $fillable = [
        'slug',
        'label',
        'description',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        // By hand first, then alphabetically — an admin who has not bothered
        // ordering them still gets a list somebody can scan, rather than
        // whatever order they happened to be created in.
        return $query->orderBy('sort_order')->orderBy('label');
    }

    /** The slugs an incoming consultation may name. */
    public static function selectableSlugs(): array
    {
        return static::active()->pluck('slug')->all();
    }

    /** Slug => label, for the storefront's picker. */
    public static function options(): array
    {
        return static::active()
            ->ordered()
            ->get(['slug', 'label'])
            ->map(fn (self $type) => ['value' => $type->slug, 'label' => $type->label])
            ->all();
    }

    /**
     * The label for a slug, including one no longer offered.
     *
     * A consultation asking for a type since withdrawn still has to render as
     * something a person can read. Falling back to a tidied slug beats showing
     * "mental_health" in an inbox.
     */
    public static function labelFor(?string $slug): string
    {
        if (! $slug) {
            return 'Not specified';
        }

        return static::where('slug', $slug)->value('label')
            ?? Str::headline($slug);
    }

    /** The members of staff who answer for this specialty. */
    public function practitioners()
    {
        return $this->belongsToMany(User::class, 'practitioner_type_user')
            ->withTimestamps();
    }

    /** Whether any consultation has ever asked for this type. */
    public function isInUse(): bool
    {
        return ConsultationRequest::where('practitioner_type', $this->slug)->exists();
    }
}
