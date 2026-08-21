<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\DeliveryAgent;
use App\Models\LogisticsCompany;

class DeliveryAgentSeeder extends Seeder
{
    public function run(): void
    {
        // Get the first logistics company
        $company = LogisticsCompany::first();
        
        if (!$company) {
            echo "⚠️ Please run LogisticsCompanySeeder first!\n";
            return;
        }

        // Create test delivery agents
        DeliveryAgent::create([
            'logistics_company_id' => $company->id,
            'name' => 'John Doe',
            'email' => 'john@agent.com',
            'phone' => '08011111111',
            'password' => Hash::make('password'),
            'vehicle_type' => 'Motorcycle',
            'vehicle_number' => 'ABC-123-XY',
            'license_number' => 'LIC123456',
            'bank_name' => 'GTBank',
            'account_number' => '0123456789',
            'account_name' => 'John Doe',
            'service_areas' => [
                ['state' => 'Lagos', 'cities' => ['Ikeja', 'Lekki', 'Victoria Island']]
            ],
            'status' => 'available',
            'is_verified' => true,
            'verified_at' => now(),
            'rating' => 4.5,
            'total_deliveries' => 0,
            'pending_balance' => 0,
            'available_balance' => 0,
            'total_earned' => 0,
            'total_paid_out' => 0
        ]);

        DeliveryAgent::create([
            'logistics_company_id' => $company->id,
            'name' => 'Jane Smith',
            'email' => 'jane@agent.com',
            'phone' => '08022222222',
            'password' => Hash::make('password'),
            'vehicle_type' => 'Van',
            'vehicle_number' => 'XYZ-456-AB',
            'license_number' => 'LIC789012',
            'bank_name' => 'Access Bank',
            'account_number' => '9876543210',
            'account_name' => 'Jane Smith',
            'service_areas' => [
                ['state' => 'Lagos', 'cities' => ['Surulere', 'Yaba', 'Ikoyi']]
            ],
            'status' => 'available',
            'is_verified' => true,
            'verified_at' => now(),
            'rating' => 4.8,
            'total_deliveries' => 0,
            'pending_balance' => 0,
            'available_balance' => 0,
            'total_earned' => 0,
            'total_paid_out' => 0
        ]);

        DeliveryAgent::create([
            'logistics_company_id' => $company->id,
            'name' => 'Mike Johnson',
            'email' => 'mike@agent.com',
            'phone' => '08033333333',
            'password' => Hash::make('password'),
            'vehicle_type' => 'Motorcycle',
            'vehicle_number' => 'DEF-789-CD',
            'license_number' => 'LIC345678',
            'bank_name' => 'Zenith Bank',
            'account_number' => '5555666677',
            'account_name' => 'Mike Johnson',
            'service_areas' => [
                ['state' => 'Abuja', 'cities' => ['Wuse', 'Garki', 'Maitama']]
            ],
            'status' => 'available',
            'is_verified' => true,
            'verified_at' => now(),
            'rating' => 4.2,
            'total_deliveries' => 0,
            'pending_balance' => 0,
            'available_balance' => 0,
            'total_earned' => 0,
            'total_paid_out' => 0
        ]);

        echo "✅ Delivery agents seeded successfully!\n";
        echo "📧 Agent Login: john@agent.com | Password: password\n";
        echo "📧 Agent Login: jane@agent.com | Password: password\n";
        echo "📧 Agent Login: mike@agent.com | Password: password\n";
    }
}
