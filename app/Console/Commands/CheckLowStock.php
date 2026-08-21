<?php

namespace App\Console\Commands;

use App\Jobs\SendLowStockAlert;
use App\Models\EmailAutomationSetting;
use App\Models\Product;
use App\Models\SystemSetting;
use Illuminate\Console\Command;

class CheckLowStock extends Command
{
    protected $signature = 'stock:check-low';

    protected $description = 'Email each pharmacy about its own stock running low';

    /**
     * Warn whoever can actually restock the shelf.
     *
     * This used to build one list of every low product on the platform and post
     * it to a hand-typed recipient list — so a pharmacy was never told its own
     * stock was running out, and whoever was on that list received other
     * people's inventory. Now each pharmacy is emailed about its own products
     * only, and stock the platform owns goes to the configured admins.
     *
     * The threshold comes from the Settings page (SystemSetting), not from this
     * automation's own config, so the number an operator sets is the number
     * that triggers the email.
     */
    public function handle()
    {
        if (! EmailAutomationSetting::isEnabled(EmailAutomationSetting::LOW_STOCK_ALERT)) {
            $this->warn('Low stock alerts are switched off in email automation settings.');

            return 0;
        }

        $threshold = SystemSetting::lowStockThreshold();
        $this->info("Checking for stock at or below {$threshold} units...");

        // Out of stock is a different problem with a different message; this
        // alert is a warning to restock, which is only actionable while there
        // is still something on the shelf.
        $lowStock = Product::query()
            ->where('is_active', true)
            ->where('stock_quantity', '>', 0)
            ->where('stock_quantity', '<=', $threshold)
            ->with('store')
            ->get();

        if ($lowStock->isEmpty()) {
            $this->info('Nothing is running low.');

            return 0;
        }

        $sent = 0;

        // Grouped through a closure with an explicit sentinel: groupBy() casts a
        // null key to the empty string, so `$storeId === null` never matches and
        // platform-owned stock would silently fall through to the store branch.
        foreach ($lowStock->groupBy(fn (Product $product) => $product->store_id ?? 0) as $storeId => $products) {
            $lines = $products->map(fn (Product $product) => [
                'name' => $product->name,
                'sku' => $product->sku,
                'stock' => $product->stock_quantity,
            ])->values()->all();

            // 0 is the sentinel for stock the platform owns itself.
            if ((int) $storeId === 0) {
                $sent += $this->alertPlatformAdmins($lines, $threshold);

                continue;
            }

            $store = $products->first()->store;

            if (! $store) {
                $this->warn("Store {$storeId} no longer exists; skipping ".count($lines).' product(s).');

                continue;
            }

            $recipient = $store->email ?: $store->owner?->email;

            if (! $recipient) {
                $this->warn("{$store->name} has no email address; skipping ".count($lines).' product(s).');

                continue;
            }

            SendLowStockAlert::dispatch($lines, $threshold, $recipient, $store->name);
            $this->info("Queued {$store->name}: ".count($lines).' product(s) -> '.$recipient);
            $sent++;
        }

        $this->info("Low stock alerts queued: {$sent}");

        return 0;
    }

    /**
     * Platform-owned stock has no pharmacy to tell, so it goes to whoever is
     * listed in the automation settings.
     *
     * @param  array<int, array{name: string, sku: ?string, stock: int}>  $lines
     */
    private function alertPlatformAdmins(array $lines, int $threshold): int
    {
        $config = EmailAutomationSetting::getConfig(
            EmailAutomationSetting::LOW_STOCK_ALERT,
            ['recipient_emails' => []]
        );

        $recipients = array_filter($config['recipient_emails'] ?? []);

        if (empty($recipients)) {
            $this->warn(
                count($lines).' platform-owned product(s) are low, but no admin recipients are '
                .'configured in email automation settings.'
            );

            return 0;
        }

        foreach ($recipients as $email) {
            SendLowStockAlert::dispatch($lines, $threshold, $email);
            $this->info('Queued platform stock: '.count($lines).' product(s) -> '.$email);
        }

        return count($recipients);
    }
}
