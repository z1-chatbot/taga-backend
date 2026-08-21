<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ProductionSeeder extends Seeder
{
    /**
     * Seeds the essential data a fresh Taga install needs:
     * admin user, roles, system settings, the pharmacy category tree, and a small
     * set of sample products spanning the different product types.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@taga.com'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'phone' => '+2348012345678',
                'email_verified_at' => now(),
                'is_active' => true,
            ]
        );

        $this->call([
            RolesAndPermissionsSeeder::class,   // role-based access control
            StoreStaffRolesSeeder::class,       // store-specific staff roles
            SystemSettingsSeeder::class,        // system configuration
            EmailAutomationSeeder::class,       // email automation settings
            PharmacyCategorySeeder::class,      // the 14-category medication blueprint
            CategoryAttributeSeeder::class,     // per-category flexible attributes
        ]);

        if (Product::count() === 0) {
            $this->seedSampleProducts();
        }
    }

    /**
     * A deliberately varied sample: an Rx medicine, an OTC medicine, a wellness
     * supplement, a medical device and a hygiene supply — so every product_type path
     * (dosable vs non-dosable, Rx vs non-Rx) has real data to exercise it.
     */
    private function seedSampleProducts(): void
    {
        $samples = [
            [
                'category' => 'cardiovascular',
                'name' => 'Amlodipine 5mg Tablets',
                'generic_name' => 'Amlodipine Besylate',
                'brand_name' => 'Norvasc',
                'manufacturer' => 'Pfizer',
                'active_ingredients' => ['Amlodipine Besylate 5mg'],
                'description' => 'A calcium channel blocker used to treat high blood pressure and angina. Taken once daily.',
                'short_description' => 'Calcium channel blocker for hypertension',
                'price' => 3500,
                'sku' => 'RX-AMLO-5-30',
                'stock_quantity' => 120,
                'strength' => '5mg',
                'dosage_form' => 'Tablet',
                'pack_size' => '30 tablets',
                'route_of_administration' => 'Oral',
                'drug_schedule' => 'Prescription Only (POM)',
                'nafdac_number' => 'A4-0123',
                'storage_conditions' => 'Store below 25°C',
                'directions_for_use' => 'Take one tablet daily, or as directed by your doctor.',
                'side_effects' => 'Ankle swelling, flushing, headache, dizziness.',
                'warnings' => 'Do not stop taking suddenly without medical advice.',
                'contraindications' => 'Severe hypotension, known hypersensitivity to amlodipine.',
                'is_featured' => true,
            ],
            [
                'category' => 'pain-fever-relief',
                'name' => 'Paracetamol 500mg Tablets',
                'generic_name' => 'Paracetamol',
                'brand_name' => 'Panadol',
                'manufacturer' => 'GSK',
                'active_ingredients' => ['Paracetamol 500mg'],
                'description' => 'Relieves mild to moderate pain and reduces fever. Suitable for headaches, toothache and cold symptoms.',
                'short_description' => 'Pain and fever relief',
                'price' => 800,
                'sku' => 'OTC-PARA-500-20',
                'stock_quantity' => 500,
                'strength' => '500mg',
                'dosage_form' => 'Tablet',
                'pack_size' => '20 tablets',
                'route_of_administration' => 'Oral',
                'storage_conditions' => 'Store in a cool dry place',
                'directions_for_use' => 'Adults: 1-2 tablets every 4-6 hours. Maximum 8 tablets in 24 hours.',
                'warnings' => 'Do not exceed the stated dose. Contains paracetamol — do not use with other paracetamol products.',
                'is_featured' => true,
            ],
            [
                'category' => 'immune-boosters',
                'name' => 'Vitamin C 1000mg Effervescent',
                'generic_name' => 'Ascorbic Acid',
                'manufacturer' => 'Emzor',
                'active_ingredients' => ['Ascorbic Acid 1000mg'],
                'description' => 'High-strength vitamin C to support normal immune system function.',
                'short_description' => 'Immune support supplement',
                'price' => 2500,
                'sku' => 'WEL-VITC-1000-10',
                'stock_quantity' => 200,
                'strength' => '1000mg',
                'dosage_form' => 'Tablet',
                'pack_size' => '10 sachets',
                'route_of_administration' => 'Oral',
                'storage_conditions' => 'Store in a cool dry place',
                'directions_for_use' => 'Dissolve one tablet in a glass of water daily.',
            ],
            [
                // Device: no strength/dosage — proves non-dosable products work.
                'category' => 'glucometers-strips',
                'name' => 'Accu-Chek Active Blood Glucose Meter',
                'manufacturer' => 'Roche',
                'description' => 'Compact blood glucose monitoring system for home use. Results in 5 seconds with a small blood sample.',
                'short_description' => 'Home blood glucose monitor',
                'price' => 18500,
                'sku' => 'DEV-ACCU-ACT',
                'stock_quantity' => 40,
                'storage_conditions' => 'Store in a cool dry place',
                'is_featured' => true,
            ],
            [
                'category' => 'sanitary-pads',
                'name' => 'Always Ultra Thin Sanitary Pads',
                'manufacturer' => 'Procter & Gamble',
                'description' => 'Ultra-thin sanitary pads with wings for reliable everyday protection.',
                'short_description' => 'Ultra thin pads with wings',
                'price' => 1200,
                'sku' => 'SUP-ALW-UT-10',
                'stock_quantity' => 300,
            ],
        ];

        foreach ($samples as $sample) {
            $category = Category::where('slug', $sample['category'])->first();

            if (! $category) {
                $this->command->warn("Skipped sample product: category '{$sample['category']}' not found.");
                continue;
            }

            unset($sample['category']);

            Product::create(array_merge($sample, [
                'category_id' => $category->id,
                // Inherit the category's regulatory defaults.
                'requires_prescription' => $category->requires_prescription,
                'is_controlled_substance' => $category->is_controlled_substance,
                'is_active' => true,
            ]));
        }
    }
}
