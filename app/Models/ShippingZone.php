<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShippingZone extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'origin_state',
        'state',
        'cities',
        'postal_codes',
        'shipping_fee',
        'type',
        'is_free_shipping',
        'estimated_delivery_days',
        'is_active'
    ];

    protected $casts = [
        'cities' => 'array',
        'postal_codes' => 'array',
        'shipping_fee' => 'decimal:2',
        'estimated_delivery_days' => 'integer',
        'is_active' => 'boolean',
        'is_free_shipping' => 'boolean'
    ];

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByState($query, $state)
    {
        return $query->where('state', $state);
    }

    // Methods
    
    /**
     * Find shipping zone by route (origin -> destination)
     * This is the new primary method for route-based shipping
     */
    public static function findByRoute($originState, $destinationState, $city = null, $postalCode = null)
    {
        \Log::info('ShippingZone::findByRoute', [
            'origin' => $originState,
            'destination' => $destinationState,
            'city' => $city
        ]);

        // First, try to find a specific route (origin -> destination) - case-insensitive
        $zones = static::active()
            ->whereRaw('LOWER(origin_state) = ?', [strtolower($originState ?? '')])
            ->whereRaw('LOWER(state) = ?', [strtolower($destinationState)])
            ->get();

        // If no specific route found, try destination-only zones where origin_state is NULL
        if ($zones->isEmpty()) {
            \Log::info('No specific route found, trying destination-only zones (origin_state IS NULL)');
            $zones = static::active()
                ->whereNull('origin_state')
                ->whereRaw('LOWER(state) = ?', [strtolower($destinationState)])
                ->get();
        }

        // If still no zones found, try any zone matching destination regardless of origin_state
        if ($zones->isEmpty()) {
            \Log::info('No null-origin zones found, trying any zone matching destination state');
            $zones = static::active()
                ->whereRaw('LOWER(state) = ?', [strtolower($destinationState)])
                ->get();
        }

        // Filter by city (case-insensitive)
        if ($city && $zones->isNotEmpty()) {
            $zones = $zones->filter(function ($zone) use ($city) {
                // If no cities specified, zone covers all cities in state
                if (!$zone->cities || empty($zone->cities)) {
                    return true;
                }
                // Case-insensitive city matching
                return in_array(strtolower($city), array_map('strtolower', $zone->cities));
            });
        }

        // Filter by postal code
        if ($postalCode && $zones->isNotEmpty()) {
            $zones = $zones->filter(function ($zone) use ($postalCode) {
                // If no postal codes specified, zone covers all postal codes
                if (!$zone->postal_codes || empty($zone->postal_codes)) {
                    return true;
                }
                return in_array($postalCode, $zone->postal_codes);
            });
        }

        // Nothing stops an admin creating two zones for the same route, and
        // whichever row the database happened to return first decided the price.
        // Charge the customer the lowest matching fee, and settle ties by taking
        // the more specific zone (one that names cities) then the newest.
        $zone = $zones->sort(function ($a, $b) {
            return [$a->calculateShippingFee(), empty($a->cities) ? 1 : 0, -$a->id]
                <=> [$b->calculateShippingFee(), empty($b->cities) ? 1 : 0, -$b->id];
        })->first();

        if ($zones->count() > 1) {
            \Log::info('Several shipping zones matched this route', [
                'origin' => $originState,
                'destination' => $destinationState,
                'matched' => $zones->pluck('name', 'id')->toArray(),
                'chosen' => $zone?->id,
            ]);
        }

        if ($zone) {
            \Log::info('Shipping zone found', [
                'zone_id' => $zone->id,
                'zone_name' => $zone->name,
                'fee' => $zone->shipping_fee,
                'is_free' => $zone->is_free_shipping
            ]);
        } else {
            \Log::warning('No shipping zone found for route');
        }

        return $zone;
    }

    /**
     * Legacy method for backward compatibility
     * @deprecated Use findByRoute instead
     */
    public static function findByLocation($state, $city = null, $postalCode = null)
    {
        $zones = static::active()->where('state', $state)->get();

        // Filter by city (case-insensitive)
        if ($city) {
            $zones = $zones->filter(function ($zone) use ($city) {
                // If no cities specified, zone covers all cities in state
                if (!$zone->cities || empty($zone->cities)) {
                    return true;
                }
                // Case-insensitive city matching
                return in_array(strtolower($city), array_map('strtolower', $zone->cities));
            });
        }

        // Filter by postal code
        if ($postalCode) {
            $zones = $zones->filter(function ($zone) use ($postalCode) {
                // If no postal codes specified, zone covers all postal codes
                if (!$zone->postal_codes || empty($zone->postal_codes)) {
                    return true;
                }
                return in_array($postalCode, $zone->postal_codes);
            });
        }

        return $zones->first();
    }

    public function calculateShippingFee()
    {
        // If free shipping is enabled, return 0
        if ($this->is_free_shipping) {
            return 0;
        }
        
        return $this->shipping_fee;
    }

    public function coversCity($city)
    {
        if (!$this->cities || empty($this->cities)) {
            return true; // No specific cities means covers all in state
        }

        return in_array(strtolower($city), array_map('strtolower', $this->cities));
    }

    public function coversPostalCode($postalCode)
    {
        if (!$this->postal_codes || empty($this->postal_codes)) {
            return true; // No specific postal codes means covers all
        }

        return in_array($postalCode, $this->postal_codes);
    }
}
