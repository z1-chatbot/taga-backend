<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the Automation Control Center.
 *
 * Sixteen settings, of which four were second copies of controls that already
 * work elsewhere (dynamic pricing and the low stock threshold live on the
 * Settings page; low stock and daily sales notifications live on the Email
 * Automation page) and three were category masters that switched nothing.
 *
 * What the rest automated was not appropriate to this business:
 *
 *  - Five seasonal sales published live platform-wide discounts of 25%–50% on
 *    fixed calendar dates. On a marketplace selling other people's medicine,
 *    "30% off everything on 1 January" is not a decision for a cron job.
 *  - Clearance discounted slow-moving stock by 40%. Slow-moving stock in a
 *    pharmacy usually means near expiry, which the expiry settings already
 *    handle properly.
 *  - Auto-reorder wrote a suggested quantity to the log and stopped there.
 *  - updateProductStatus reactivated any inactive product that had stock, so a
 *    listing deliberately pulled — a recalled batch, a suspended pharmacy —
 *    came back on its own twice a day.
 *
 * The one part worth keeping, activating sale events an admin scheduled, is now
 * a plain scheduled task in routes/console.php alongside the deactivation that
 * was already there.
 *
 * The table held no rows, so nothing is lost. down() recreates the structure
 * only; the code that read it is gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('automation_settings');
    }

    public function down(): void
    {
        if (Schema::hasTable('automation_settings')) {
            return;
        }

        // Raw SQL rather than a Blueprint: the original carries enums and a
        // json_valid CHECK that the fluent builder does not reproduce, and a
        // down() that restores a different table is worse than none.
        DB::statement(<<<'SQL'
            CREATE TABLE `automation_settings` (
              `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
              `key` varchar(255) NOT NULL,
              `value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`value`)),
              `type` enum('boolean','integer','decimal','string','array','json') NOT NULL DEFAULT 'string',
              `category` enum('sales_automation','inventory_automation','pricing_automation','notification_automation','marketing_automation') NOT NULL,
              `description` text DEFAULT NULL,
              `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
              `created_at` timestamp NULL DEFAULT NULL,
              `updated_at` timestamp NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `automation_settings_key_unique` (`key`),
              KEY `automation_settings_category_is_enabled_index` (`category`,`is_enabled`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }
};
