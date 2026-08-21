<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Config fields the Email Automation screen offered and nothing read.
 *
 *   delay_minutes    welcome_email, abandoned_cart_1h / _24h / _3d
 *   send_time        daily_sales_report
 *   check_frequency  low_stock_alert
 *   threshold        low_stock_alert
 *
 * The delays were the worst of them, because the stage names already state the
 * timing: `abandoned_cart_24h` carrying an editable "delay 1440 minutes" invites
 * someone to set 90 and wonder for a week why nothing changed. Timing comes from
 * the stage; frequency and send time come from the schedule in
 * routes/console.php; and the low-stock number now comes from the Settings page
 * so the alert and every dashboard count agree.
 *
 * Only the fields that genuinely drive behaviour are left: recipient_emails,
 * include_comparison, delay_days.
 */
return new class extends Migration
{
    private const DEAD_KEYS = ['delay_minutes', 'send_time', 'check_frequency', 'threshold'];

    public function up(): void
    {
        foreach (DB::table('email_automation_settings')->get() as $row) {
            $config = json_decode($row->config ?? '[]', true);

            if (! is_array($config) || $config === []) {
                continue;
            }

            $cleaned = array_diff_key($config, array_flip(self::DEAD_KEYS));

            if ($cleaned === $config) {
                continue;
            }

            DB::table('email_automation_settings')
                ->where('id', $row->id)
                ->update([
                    'config' => json_encode((object) $cleaned),
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Restores the values the seeder used to ship, for the rows that had them.
     */
    public function down(): void
    {
        $restore = [
            'welcome_email' => ['delay_minutes' => 0],
            'abandoned_cart_1h' => ['delay_minutes' => 60],
            'abandoned_cart_24h' => ['delay_minutes' => 1440],
            'abandoned_cart_3d' => ['delay_minutes' => 4320],
            'low_stock_alert' => ['threshold' => 10, 'check_frequency' => 'daily'],
            'daily_sales_report' => ['send_time' => '08:00'],
        ];

        foreach ($restore as $key => $fields) {
            $row = DB::table('email_automation_settings')->where('key', $key)->first();

            if (! $row) {
                continue;
            }

            $config = json_decode($row->config ?? '[]', true);
            $config = is_array($config) ? $config : [];

            DB::table('email_automation_settings')
                ->where('id', $row->id)
                ->update([
                    'config' => json_encode($fields + $config),
                    'updated_at' => now(),
                ]);
        }
    }
};
