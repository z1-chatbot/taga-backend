<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
|
| These lived in app/Console/Kernel.php, which Laravel 11 and 12 do not load —
| the slim skeleton registers the framework's own console kernel and never
| looks at a custom one. `schedule:list` reported "No scheduled tasks have been
| defined" while that file sat there full of them, so none of this had ever
| run: no daily sales report, no low stock alerts, no licence expiry warnings,
| no earnings released from hold.
|
| This file is loaded explicitly by bootstrap/app.php via
| withRouting(commands: ...), so tasks defined here genuinely register. Verify
| with `php artisan schedule:list` after any change.
|
| On the server, one cron entry drives everything:
|
|     * * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
|
*/

/*
 * Drain the queue.
 *
 * QUEUE_CONNECTION is `database`, so every ShouldQueue job — which is every
 * email the platform sends — waits in the `jobs` table until a worker picks it
 * up. Shared hosting will not keep a `queue:work` daemon alive, so without this
 * nothing is ever delivered.
 *
 * --stop-when-empty exits as soon as the backlog clears rather than idling, and
 * --max-time keeps the run comfortably inside the next tick so runs cannot pile
 * up on each other.
 */
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3 --quiet')
    ->everyMinute()
    ->name('drain-queue')
    ->withoutOverlapping();

// Clean up expired cart items daily
Schedule::call(function () {
    \App\Models\Cart::where('created_at', '<', now()->subDays(7))->delete();
})->daily()->name('cleanup-expired-carts');

// Clean up abandoned payment transactions
Schedule::call(function () {
    \App\Models\PaymentTransaction::where('status', 'pending')
        ->where('created_at', '<', now()->subHours(2))
        ->update(['status' => 'abandoned']);
})->hourly()->name('cleanup-abandoned-payments');

/*
 * Abandoned basket reminders.
 *
 * Hourly, because the first stage is an hour old — anything coarser makes that
 * stage meaningless. The command decides which stage each basket is due and
 * refuses to repeat one, so running it often is safe.
 */
Schedule::command('cart:remind')
    ->hourly()
    ->name('abandoned-cart-reminders')
    ->withoutOverlapping();

// Send daily sales report
Schedule::command('sales:daily-report')
    ->dailyAt('08:00')
    ->name('daily-sales-report')
    ->withoutOverlapping();

// Email each pharmacy about its own stock running low
Schedule::command('stock:check-low')
    ->dailyAt('09:00')
    ->name('low-stock-alerts')
    ->withoutOverlapping();

/*
 * Open and close promotions on time.
 *
 * The only part of the old "sales automation" worth keeping. An admin schedules
 * a sale for next Monday and something has to make it live on the day; the rest
 * of that job invented discounts of its own, which is not a thing a cron should
 * decide on a marketplace selling other people's medicine.
 */
Schedule::call(function () {
    \App\Models\SaleEvent::where('auto_activate', true)
        ->where('is_active', false)
        ->where('start_date', '<=', now())
        ->where('end_date', '>=', now())
        ->update(['is_active' => true]);

    \App\Models\Coupon::where('is_active', true)
        ->where('valid_until', '<', now())
        ->update(['is_active' => false]);

    \App\Models\SaleEvent::where('is_active', true)
        ->where('end_date', '<', now())
        ->update(['is_active' => false]);
})->everyFiveMinutes()->name('open-and-close-promotions');

// Backup database daily
Schedule::command('backup:run')
    ->dailyAt('02:00')
    ->name('database-backup');

// Clear application cache weekly
Schedule::command('cache:clear')
    ->weekly()
    ->sundays()
    ->at('03:00')
    ->name('clear-cache');

// Optimize application weekly
Schedule::command('optimize')
    ->weekly()
    ->sundays()
    ->at('03:30')
    ->name('optimize-app');

/*
 * Sitemap generation is a stub — it counts rows and logs them without writing
 * a sitemap. Left registered so the intent is not lost, but it produces no
 * file; anything relying on sitemap.xml still needs building.
 */
Schedule::call(function () {
    $products = \App\Models\Product::active()->get(['id', 'updated_at']);
    $categories = \App\Models\Category::active()->get(['id', 'updated_at']);

    \Log::info('Sitemap generated', [
        'products_count' => $products->count(),
        'categories_count' => $categories->count(),
    ]);
})->weekly()->name('generate-sitemap');

// Process refunds and disputes
Schedule::call(function () {
    $pendingRefunds = \App\Models\PaymentTransaction::where('status', 'pending')
        ->where('payment_method', 'refund')
        ->where('created_at', '<', now()->subDay())
        ->count();

    if ($pendingRefunds > 0) {
        \Log::warning("Pending refunds alert: {$pendingRefunds} refunds need attention");
    }
})->dailyAt('10:00')->name('refund-monitoring');

// Warn pharmacies before their licence lapses — selling stops the day it does,
// and without this the first they know is the orders drying up.
Schedule::command('licences:warn-expiring')
    ->dailyAt('08:00')
    ->name('licence-expiry-warnings');

// Release pending agent/company earnings whose hold period has elapsed
Schedule::call(function () {
    $pendingEarnings = \App\Models\AgentEarning::where('status', 'pending')
        ->where('available_at', '<=', now())
        ->get();

    $released = 0;
    foreach ($pendingEarnings as $earning) {
        $earning->makeAvailable();
        $released++;
    }

    if ($released > 0) {
        \Log::info("Released {$released} pending earnings to available balance");
    }
})->everyFiveMinutes()->name('release-pending-earnings');
