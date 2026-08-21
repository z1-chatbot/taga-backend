<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Update the hold period to 0
DB::table('delivery_settings')
    ->where('key', 'earnings_hold_period_hours')
    ->update(['value' => '0']);

echo "✅ Updated earnings_hold_period_hours to 0\n";

// Verify
$setting = DB::table('delivery_settings')
    ->where('key', 'earnings_hold_period_hours')
    ->first();

echo "Current value: " . $setting->value . "\n";
