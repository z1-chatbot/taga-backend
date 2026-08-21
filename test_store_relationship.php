<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Store Owners: " . App\Models\User::where('role', 'store_owner')->count() . PHP_EOL;
echo "Stores: " . App\Models\Store::count() . PHP_EOL;

$user = App\Models\User::where('role', 'store_owner')->first();
if ($user) {
    echo "User ID: " . $user->id . PHP_EOL;
    echo "User Email: " . $user->email . PHP_EOL;
    echo "Has Store: " . ($user->store ? 'YES (Store ID: ' . $user->store->id . ')' : 'NO') . PHP_EOL;
    
    if ($user->store) {
        echo "Store Name: " . $user->store->name . PHP_EOL;
        echo "Store Owner ID: " . $user->store->owner_id . PHP_EOL;
    }
} else {
    echo "No store owners found" . PHP_EOL;
}
