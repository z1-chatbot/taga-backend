<?php

namespace App\Services;

use App\Models\ShippingRate;
use App\Models\DeliverySetting;

class ShippingCalculator
{
    public function calculateFee($fromState, $toState, $weight = 0, $subtotal = 0)
    {
        // Check free shipping threshold
        $freeShippingThreshold = DeliverySetting::getValue('free_shipping_threshold', 0);
        if ($freeShippingThreshold > 0 && $subtotal >= $freeShippingThreshold) {
            return 0;
        }

        // Get shipping rate
        $fee = ShippingRate::getShippingFee($fromState, $toState, $weight);
        
        return $fee;
    }

    public function calculateCODFee($orderTotal)
    {
        $codEnabled = DeliverySetting::getValue('enable_cod_fee', false);
        if (!$codEnabled) {
            return 0;
        }

        $feeType = DeliverySetting::getValue('cod_fee_type', 'percentage');
        
        if ($feeType === 'flat') {
            return DeliverySetting::getValue('cod_flat_fee', 100);
        }
        
        $percentage = DeliverySetting::getValue('cod_fee_percentage', 2);
        return ($orderTotal * $percentage) / 100;
    }

    public function getEstimatedDays($fromState, $toState)
    {
        $estimate = ShippingRate::getEstimatedDays($fromState, $toState);
        return $estimate ? $estimate['max'] : 7;
    }
}
