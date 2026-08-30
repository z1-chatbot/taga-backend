<?php

namespace App\Services;

use App\Models\ShippingRate;
use App\Models\DeliverySetting;

class ShippingCalculator
{
    public function calculateFee($fromState, $toState, $subtotal = 0)
    {
        // Check free shipping threshold
        $freeShippingThreshold = DeliverySetting::getValue('free_shipping_threshold', 0);
        if ($freeShippingThreshold > 0 && $subtotal >= $freeShippingThreshold) {
            return 0;
        }

        // Get shipping rate
        $fee = ShippingRate::getShippingFee($fromState, $toState);
        
        return $fee;
    }

    public function getEstimatedDays($fromState, $toState)
    {
        $estimate = ShippingRate::getEstimatedDays($fromState, $toState);
        return $estimate ? $estimate['max'] : 7;
    }
}
