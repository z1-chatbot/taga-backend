<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A pharmacy whose logo Taga displays.
 *
 * Curated by a platform administrator, and independent of whether that pharmacy
 * holds a vendor account — see the migration for why this is not the `stores`
 * table. `store_id` links the two when they happen to be the same shop.
 */
class PartnerPharmacy extends Model
{
    protected $table = 'partner_pharmacies';

    protected $fillable = [
        'name',
        'logo_path',
        'link_url',
        'store_id',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'store_id' => 'integer',
    ];

    /** `logo_path` is disk-relative; every client needs an absolute URL. */
    protected $appends = ['logo_url'];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Absolute URL for the logo.
     *
     * Built from the `public` disk, whose URL is configured as APP_URL./media
     * rather than /storage — Hostinger blocks the /storage prefix at the web
     * server, ahead of PHP. See App\Http\Controllers\PublicStorageController.
     */
    public function getLogoUrlAttribute(): ?string
    {
        if (! $this->logo_path) {
            return null;
        }

        return Storage::disk('public')->url($this->logo_path);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Display order: what the admin set, then oldest first on a tie.
     *
     * Oldest rather than newest, unlike banners. A logo wall is a roster and
     * reads as one — a partner should not jump the queue by being edited, and
     * long-standing partners staying put is the behaviour people expect from a
     * list of who you work with.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order', 'asc')->orderBy('id', 'asc');
    }
}
