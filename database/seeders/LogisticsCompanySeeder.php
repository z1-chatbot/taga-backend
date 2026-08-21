<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\LogisticsCompany;

class LogisticsCompanySeeder extends Seeder
{
    public function run(): void
    {
        // Create test logistics company
        LogisticsCompany::create([
            'name' => 'Swift Logistics',
            'code' => 'SWIFT001',
            'description' => 'Fast and reliable delivery services across Nigeria',
            'logo' => null,
            'contact_email' => 'contact@swiftlogistics.com',
            'contact_phone' => '08012345678',
            'admin_email' => 'admin@swiftlogistics.com',
            'admin_password' => Hash::make('password'),
            'service_areas' => [
                ['state' => 'Lagos', 'cities' => ['Ikeja', 'Lekki', 'Victoria Island', 'Surulere']],
                ['state' => 'Abuja', 'cities' => ['Wuse', 'Garki', 'Maitama']],
                ['state' => 'Rivers', 'cities' => ['Port Harcourt', 'Obio-Akpor']]
            ],
            'pricing_structure' => [
                'base_rate' => 1000,
                'per_km_rate' => 50,
                'commission_percentage' => 15
            ],
            'is_active' => true,
            'available_balance' => 0,
            'pending_balance' => 0,
            'total_earned' => 0
        ]);

        // Create another test company
        LogisticsCompany::create([
            'name' => 'Express Couriers',
            'code' => 'EXPRESS001',
            'description' => 'Same-day delivery specialists',
            'logo' => null,
            'contact_email' => 'info@expresscouriers.com',
            'contact_phone' => '08098765432',
            'admin_email' => 'admin@expresscouriers.com',
            'admin_password' => Hash::make('password'),
            'service_areas' => [
                ['state' => 'Lagos', 'cities' => ['Yaba', 'Ikoyi', 'Ajah']],
                ['state' => 'Oyo', 'cities' => ['Ibadan', 'Ogbomosho']]
            ],
            'pricing_structure' => [
                'base_rate' => 1200,
                'per_km_rate' => 60,
                'commission_percentage' => 12
            ],
            'is_active' => true,
            'available_balance' => 0,
            'pending_balance' => 0,
            'total_earned' => 0
        ]);

        echo "✅ Logistics companies seeded successfully!\n";
        echo "📧 Login: admin@swiftlogistics.com | Password: password\n";
        echo "📧 Login: admin@expresscouriers.com | Password: password\n";
    }
}
