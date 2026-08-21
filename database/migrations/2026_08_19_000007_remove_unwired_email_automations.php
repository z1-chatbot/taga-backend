<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Four switches with nothing behind them.
 *
 * The Email Automation screen listed sixteen automations. Seven of them could
 * never send anything: three abandoned-cart stages whose job was written but
 * never dispatched, and these four.
 *
 * The cart stages have been given their dispatcher (`cart:remind`, scheduled
 * hourly) and stay. These four are removed instead:
 *
 *   new_product     job and template existed, nothing ever dispatched them
 *   sale_event      same
 *   price_drop      no code at all, only a row
 *   back_in_stock   no code at all, only a row
 *
 * Two of them showed as "on" in the admin, which is worse than absent: an
 * operator had every reason to believe customers were being told about new
 * stock and sales. The jobs, mailables and blade templates for the first two
 * were deleted with this, so restoring the row alone would not bring them back
 * — writing the dispatcher has to come first.
 */
return new class extends Migration
{
    private const KEYS = ['new_product', 'sale_event', 'price_drop', 'back_in_stock'];

    public function up(): void
    {
        DB::table('email_automation_settings')
            ->whereIn('key', self::KEYS)
            ->delete();
    }

    public function down(): void
    {
        $rows = [
            'new_product' => ['New Product Announcement', 'Notify customers when new products are added', false, ['batch_size' => 100]],
            'sale_event' => ['Sale Event Notification', 'Notify customers when sale events start', true, ['send_before_hours' => 2]],
            'price_drop' => ['Price Drop Alert', 'Notify customers when product prices drop', false, ['minimum_drop_percentage' => 10]],
            'back_in_stock' => ['Back in Stock', 'Notify customers when out-of-stock products are available', true, []],
        ];

        foreach ($rows as $key => [$name, $description, $enabled, $config]) {
            $exists = DB::table('email_automation_settings')->where('key', $key)->exists();

            if ($exists) {
                continue;
            }

            DB::table('email_automation_settings')->insert([
                'key' => $key,
                'name' => $name,
                'description' => $description,
                'is_enabled' => $enabled,
                'config' => json_encode($config),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
