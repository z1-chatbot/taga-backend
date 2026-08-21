<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'image_path',
        'image_url',
        'sort_order',
        'is_primary',
        'alt_text'
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_primary' => 'boolean'
    ];

    protected $appends = ['full_url'];

    /**
     * Get the product that owns the image
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the full URL for the image
     */
    public function getFullUrlAttribute()
    {
        if ($this->image_url) {
            return $this->image_url;
        }

        // Generate URL from path
        if ($this->image_path) {
            // If it's already a full URL
            if (filter_var($this->image_path, FILTER_VALIDATE_URL)) {
                return $this->image_path;
            }

            // If it's a storage path
            if (Storage::disk('public')->exists($this->image_path)) {
                return Storage::disk('public')->url($this->image_path);
            }

            // Fallback to asset path
            return asset('storage/' . $this->image_path);
        }

        return null;
    }

    /**
     * Scope to get primary image
     */
    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }

    /**
     * Scope to order by sort order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}
