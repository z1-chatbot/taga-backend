<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Banner extends Model
{
    use HasFactory;

    /**
     * Background themes, keyed by the slug stored in `bg_color`.
     *
     * This column used to hold raw Tailwind gradient class pairs
     * ("from-blue-600 to-blue-800"). That could never work on the storefront:
     * Tailwind only emits classes it can see as literal strings in source, and
     * a value that arrives from the database appears nowhere in source — so
     * every banner would have rendered with no background at all. The house
     * style also forbids gradients outright (see the note at the top of
     * `frontend/src/index.css`), so the themes are flat grounds.
     *
     * Storing a slug rather than a colour means the palette can be retuned in
     * one place without rewriting rows, and the API stays presentation-agnostic.
     * `hex` is resolved server-side so both the storefront and the admin
     * preview draw from one source of truth instead of two drifting copies.
     */
    public const THEMES = [
        'ink'   => ['label' => 'Ink',    'hex' => '#14201c'],
        'slate' => ['label' => 'Slate',  'hex' => '#4c5c56'],
        'moss'  => ['label' => 'Moss',   'hex' => '#1b6b4c'],
        'clay'  => ['label' => 'Clay',   'hex' => '#c2472c'],
        'rust'  => ['label' => 'Rust',   'hex' => '#a32a12'],
        'ochre' => ['label' => 'Ochre',  'hex' => '#7a5a12'],
        'plum'  => ['label' => 'Plum',   'hex' => '#4c2a4d'],
        'teal'  => ['label' => 'Teal',   'hex' => '#1d4e4b'],
    ];

    public const DEFAULT_THEME = 'ink';

    protected $fillable = [
        'title',
        'subtitle',
        'description',
        'image_url',
        'link_url',
        'button_text',
        'bg_color',
        'position',
        'sort_order',
        'is_active',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'sort_order' => 'integer',
    ];

    /**
     * `image_url` holds a disk-relative path; clients need an absolute one.
     * `bg_color_hex` saves every client from duplicating the palette.
     */
    protected $appends = ['full_url', 'bg_color_hex'];

    /**
     * Absolute URL for the banner image.
     *
     * Images now live on the `public` disk under `storage/app/public/banners`,
     * like every other user-visible upload in the app. They used to be written
     * straight into `public/banners/` with an absolute URL baked into the
     * column, which had two problems: the files sat outside `storage/` and
     * outside git, so any clean redeploy of the public directory silently took
     * every banner image with it and left live rows pointing at 404s; and the
     * baked-in absolute URL froze the hostname at upload time, so a domain
     * change orphaned every existing banner.
     *
     * Rows written before that change still hold a full URL. Those are returned
     * untouched rather than rewritten, so nothing that is currently working
     * breaks — the migration moves the files, and this is the safety net for
     * anything it could not find.
     */
    public function getFullUrlAttribute(): ?string
    {
        $value = $this->image_url;

        if (! $value) {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }

        return Storage::disk('public')->url($value);
    }

    /** The theme's flat ground colour, falling back when the slug is unknown. */
    public function getBgColorHexAttribute(): string
    {
        $theme = self::THEMES[$this->bg_color] ?? self::THEMES[self::DEFAULT_THEME];

        return $theme['hex'];
    }

    /**
     * Scope to get only active banners
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
                     ->where(function($q) {
                         $q->whereNull('start_date')
                           ->orWhere('start_date', '<=', now());
                     })
                     ->where(function($q) {
                         $q->whereNull('end_date')
                           ->orWhere('end_date', '>=', now());
                     });
    }

    /**
     * Scope to get banners by position
     */
    public function scopeByPosition($query, $position)
    {
        return $query->where(function($q) use ($position) {
            $q->where('position', $position)
              ->orWhere('position', 'both');
        });
    }

    /**
     * Scope to order by sort order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order', 'asc')
                     ->orderBy('created_at', 'desc');
    }
}
