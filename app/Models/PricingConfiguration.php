<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PricingConfiguration extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'category',
        'type',
        'value',
        'description',
        'is_active',
        'priority'
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'is_active' => 'boolean',
        'priority' => 'integer'
    ];

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory($query, $category)
    {
        return $query->where(function ($q) use ($category) {
            $q->where('category', $category)
              ->orWhereNull('category'); // Global configurations
        });
    }

    public function scopeByPriority($query)
    {
        return $query->orderBy('priority', 'desc');
    }

    // Methods
    public static function applyToPrice($basePrice, $category = null)
    {
        // The Pricing screen tells operators that "configurations below will not
        // be applied until dynamic pricing is enabled in System Settings". That
        // was untrue: every markup applied whether the switch was on or off, so
        // turning it off changed nothing and the warning misled about money.
        if (! self::dynamicPricingEnabled()) {
            return round($basePrice, 2);
        }

        $query = static::active()->byPriority();

        if ($category) {
            $query->byCategory($category);
        } else {
            $query->whereNull('category');
        }

        $configurations = $query->get();
        $finalPrice = $basePrice;

        foreach ($configurations as $config) {
            if ($config->type === 'percentage') {
                $finalPrice += ($basePrice * $config->value) / 100;
            } elseif ($config->type === 'fixed_amount') {
                $finalPrice += $config->value;
            }
        }

        return round($finalPrice, 2);
    }

    /**
     * The master switch on the Settings page.
     *
     * Defaults to on, so an operator who never touches it sees exactly the
     * behaviour they had before this became real.
     */
    public static function dynamicPricingEnabled(): bool
    {
        // Stored as JSON, so a saved false can come back as int or string —
        // getBool() owns that coercion for every switch on the Settings page.
        return SystemSetting::getBool(
            SystemSetting::CATEGORY_GENERAL,
            'enable_dynamic_pricing',
            true
        );
    }

    public function applyTo($price)
    {
        if ($this->type === 'percentage') {
            return $price + (($price * $this->value) / 100);
        } elseif ($this->type === 'fixed_amount') {
            return $price + $this->value;
        }

        return $price;
    }

    public function calculateMarkup($price)
    {
        if ($this->type === 'percentage') {
            return ($price * $this->value) / 100;
        } elseif ($this->type === 'fixed_amount') {
            return $this->value;
        }

        return 0;
    }
}
