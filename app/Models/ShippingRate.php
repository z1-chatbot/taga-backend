<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShippingRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'logistics_company_id',
        'delivery_agent_id',
        'from_state',
        'to_state',
        'base_rate',
        'per_kg_rate',
        'weight_threshold',
        'estimated_days_min',
        'estimated_days_max',
        'is_interstate',
        'is_active',
        'priority'
    ];

    protected $casts = [
        'base_rate' => 'decimal:2',
        'per_kg_rate' => 'decimal:2',
        'weight_threshold' => 'decimal:2',
        'estimated_days_min' => 'integer',
        'estimated_days_max' => 'integer',
        'is_interstate' => 'boolean',
        'is_active' => 'boolean',
        'priority' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    // Relationships
    public function logisticsCompany()
    {
        return $this->belongsTo(LogisticsCompany::class);
    }

    // Scopes
    public function deliveryAgent()
    {
        return $this->belongsTo(DeliveryAgent::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeGlobal($query)
    {
        // Belongs to nobody in particular, so it applies to everybody. Both
        // owner columns have to be null: a rider's rate is not a global one.
        return $query->whereNull('logistics_company_id')->whereNull('delivery_agent_id');
    }

    public function scopeForCompany($query, $companyId)
    {
        return $query->where('logistics_company_id', $companyId)->whereNull('delivery_agent_id');
    }

    /** Terms agreed with one rider, which no other courier gets. */
    public function scopeForAgent($query, $agentId)
    {
        return $query->where('delivery_agent_id', $agentId);
    }

    public function scopeInterstate($query)
    {
        return $query->where('is_interstate', true);
    }

    public function scopeIntrastate($query)
    {
        return $query->where('is_interstate', false);
    }

    public function scopeForRoute($query, $fromState, $toState)
    {
        return $query->where('from_state', $fromState)
                    ->where('to_state', $toState);
    }

    // Methods
    public function calculateShippingFee($weight = 0)
    {
        $fee = $this->base_rate;

        // Add per-kg charge if weight exceeds threshold
        if ($weight > $this->weight_threshold) {
            $excessWeight = $weight - $this->weight_threshold;
            $fee += ($excessWeight * $this->per_kg_rate);
        }

        return round($fee, 2);
    }

    public function getEstimatedDeliveryRange()
    {
        if ($this->estimated_days_min === $this->estimated_days_max) {
            return $this->estimated_days_min . ' day' . ($this->estimated_days_min > 1 ? 's' : '');
        }

        return $this->estimated_days_min . '-' . $this->estimated_days_max . ' days';
    }

    // Static methods

    /**
     * Find the best matching rate for a route.
     * Priority: company-specific rate > global rate > reverse route > intrastate fallback
     */
    /**
     * The rate that applies to this journey, most specific first.
     *
     * A rider's own rate beats their company's, which beats the global one.
     * Terms agreed with one courier are exactly that — the reason to record
     * them is that they differ from what everyone else gets.
     */
    public static function findRate($fromState, $toState, $companyId = null, $agentId = null)
    {
        // 0. Terms agreed with this rider in particular.
        if ($agentId) {
            $rate = self::active()->forAgent($agentId)->forRoute($fromState, $toState)->first();
            if ($rate) return $rate;

            // Same journey, stated the other way round.
            $rate = self::active()->forAgent($agentId)->forRoute($toState, $fromState)->first();
            if ($rate) return $rate;

            if (strtolower($fromState) === strtolower($toState)) {
                $rate = self::active()->forAgent($agentId)->intrastate()->where('from_state', $fromState)->first();
                if ($rate) return $rate;
            }
        }

        // 1. Try company-specific rate for this route
        if ($companyId) {
            $rate = self::active()->forCompany($companyId)->forRoute($fromState, $toState)->first();
            if ($rate) return $rate;

            // Try reverse route for company
            $rate = self::active()->forCompany($companyId)->forRoute($toState, $fromState)->first();
            if ($rate) return $rate;

            // Try company intrastate
            if (strtolower($fromState) === strtolower($toState)) {
                $rate = self::active()->forCompany($companyId)->intrastate()->where('from_state', $fromState)->first();
                if ($rate) return $rate;
            }
        }

        // 2. Fall back to global rate
        $rate = self::active()->global()->forRoute($fromState, $toState)->first();
        if ($rate) return $rate;

        // Try reverse route globally
        $rate = self::active()->global()->forRoute($toState, $fromState)->first();
        if ($rate) return $rate;

        // Try global intrastate
        if (strtolower($fromState) === strtolower($toState)) {
            $rate = self::active()->global()->intrastate()->where('from_state', $fromState)->first();
            if ($rate) return $rate;
        }

        return null;
    }

    /**
     * Get shipping fee for a route, optionally for a specific company.
     * Company-specific rates take precedence over global rates.
     */
    public static function getShippingFee($fromState, $toState, $weight = 0, $companyId = null)
    {
        $rate = self::findRate($fromState, $toState, $companyId);
        return $rate ? $rate->calculateShippingFee($weight) : 0;
    }

    public static function getEstimatedDays($fromState, $toState, $companyId = null)
    {
        $rate = self::findRate($fromState, $toState, $companyId);

        return $rate ? [
            'min' => $rate->estimated_days_min,
            'max' => $rate->estimated_days_max,
            'range' => $rate->getEstimatedDeliveryRange()
        ] : null;
    }
}
