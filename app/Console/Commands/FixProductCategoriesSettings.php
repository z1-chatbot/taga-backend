<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\SystemSetting;

class FixProductCategoriesSettings extends Command
{
    protected $signature = 'settings:fix-categories';
    protected $description = 'Fix product_categories in system settings to use snake_case';

    public function handle()
    {
        $this->info('Fixing product_categories in system settings...');
        
        // Get current value
        $currentValue = SystemSetting::getValue('product_attributes', 'product_categories', []);
        
        $this->line('Current value type: ' . gettype($currentValue));
        
        // Decode if it's a string
        if (is_string($currentValue)) {
            $currentValue = json_decode($currentValue, true);
        }
        
        if (!is_array($currentValue)) {
            $this->error('Could not parse current value as array');
            return 1;
        }
        
        $this->line('Current categories:');
        foreach ($currentValue as $cat) {
            $this->line("  - {$cat}");
        }
        
        // Convert to snake_case
        $normalizedCategories = array_map(function($category) {
            return strtolower(str_replace(' ', '_', trim($category)));
        }, $currentValue);
        
        $this->line("\nNormalized categories:");
        foreach ($normalizedCategories as $cat) {
            $this->line("  - {$cat}");
        }
        
        // Update the system setting
        SystemSetting::setValue(
            SystemSetting::CATEGORY_PRODUCT_ATTRIBUTES,
            'product_categories',
            $normalizedCategories,
            'Product Categories',
            'Available product category options'
        );
        
        $this->info("\n✅ Successfully updated product_categories to snake_case format!");
        
        // Verify
        $newValue = SystemSetting::getValue('product_attributes', 'product_categories', []);
        if (is_string($newValue)) {
            $newValue = json_decode($newValue, true);
        }
        
        $this->line("\nVerification - New values:");
        foreach ($newValue as $cat) {
            $this->line("  - {$cat}");
        }
        
        return 0;
    }
}
