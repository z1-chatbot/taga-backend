<?php

namespace App\Services;

use App\Models\OrderShipment;
use App\Models\DeliveryAgent;
use App\Models\LogisticsCompany;

class AgentAssignmentService
{
    public function findAvailableAgent($state, $city = null)
    {
        // First try to find independent agents
        $agent = DeliveryAgent::whereNull('logistics_company_id')
            ->where('status', 'available')
            ->where('is_verified', true)
            ->get()
            ->filter(fn($a) => $a->coversArea($state, $city))
            ->sortByDesc('rating')
            ->first();

        if ($agent) {
            return $agent;
        }

        // Try logistics company agents
        $companies = LogisticsCompany::active()->get();
        foreach ($companies as $company) {
            if ($company->coversArea($state, $city)) {
                $agent = $company->getAvailableAgents($state, $city)->first();
                if ($agent) {
                    return $agent;
                }
            }
        }

        return null;
    }

    public function assignAgentToShipment(OrderShipment $shipment, DeliveryAgent $agent)
    {
        $shipment->assignAgent($agent);
        $agent->assignOrder($shipment->order);
        
        return $shipment;
    }

    public function autoAssignAgent(OrderShipment $shipment)
    {
        $shippingAddress = $shipment->order->shipping_address;
        $state = $shippingAddress['state'] ?? null;
        $city = $shippingAddress['city'] ?? null;

        if (!$state) {
            return false;
        }

        $agent = $this->findAvailableAgent($state, $city);
        
        if ($agent) {
            $this->assignAgentToShipment($shipment, $agent);
            return true;
        }

        return false;
    }
}
