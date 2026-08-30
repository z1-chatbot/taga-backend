<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ShippingRate;

class ShippingRatesSeeder extends Seeder
{
    public function run()
    {
        $rates = [
            // Lagos intrastate
            ['from_state' => 'Lagos', 'to_state' => 'Lagos', 'base_rate' => 1500, 'estimated_days_min' => 1, 'estimated_days_max' => 2, 'is_interstate' => false],
            
            // Lagos to nearby states
            ['from_state' => 'Lagos', 'to_state' => 'Ogun', 'base_rate' => 2500, 'min_days' => 2, 'max_days' => 3],
            ['from_state' => 'Lagos', 'to_state' => 'Oyo', 'base_rate' => 3000, 'min_days' => 2, 'max_days' => 4],
            ['from_state' => 'Lagos', 'to_state' => 'Osun', 'base_rate' => 3500, 'min_days' => 3, 'max_days' => 5],
            
            // Lagos to South-South
            ['from_state' => 'Lagos', 'to_state' => 'Rivers', 'base_rate' => 4500, 'min_days' => 3, 'max_days' => 5],
            ['from_state' => 'Lagos', 'to_state' => 'Delta', 'base_rate' => 4000, 'min_days' => 3, 'max_days' => 5],
            
            // Lagos to South-East
            ['from_state' => 'Lagos', 'to_state' => 'Anambra', 'base_rate' => 4500, 'min_days' => 3, 'max_days' => 5],
            ['from_state' => 'Lagos', 'to_state' => 'Enugu', 'base_rate' => 4500, 'min_days' => 3, 'max_days' => 5],
            ['from_state' => 'Lagos', 'to_state' => 'Imo', 'base_rate' => 4500, 'min_days' => 3, 'max_days' => 5],
            
            // Lagos to North-Central
            ['from_state' => 'Lagos', 'to_state' => 'Abuja', 'base_rate' => 5000, 'min_days' => 4, 'max_days' => 6],
            ['from_state' => 'Lagos', 'to_state' => 'Kwara', 'base_rate' => 4000, 'min_days' => 3, 'max_days' => 5],
            
            // Lagos to North-West
            ['from_state' => 'Lagos', 'to_state' => 'Kano', 'base_rate' => 6000, 'min_days' => 5, 'max_days' => 7],
            ['from_state' => 'Lagos', 'to_state' => 'Kaduna', 'base_rate' => 5500, 'min_days' => 4, 'max_days' => 6],
        ];

        foreach ($rates as $rate) {
            ShippingRate::create($rate);
        }
    }
}
