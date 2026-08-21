<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    use HasFactory;

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
