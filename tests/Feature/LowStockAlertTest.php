<?php

namespace Tests\Feature;

use App\Jobs\SendLowStockAlert;
use App\Models\EmailAutomationSetting;
use App\Models\Product;
use App\Models\Store;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Low stock alerts go to whoever can restock the shelf.
 *
 * The old command built one list of every low product on the platform and
 * posted it to a hand-typed recipient list, so a pharmacy was never told its
 * own stock was running out and whoever was on that list saw other people's
 * inventory. The threshold also came from the automation's own config rather
 * than the Settings page, so the number an operator set changed nothing.
 */
class LowStockAlertTest extends TestCase
{
    private function setThreshold(int $value): void
    {
        SystemSetting::updateOrCreate(
            ['category' => SystemSetting::CATEGORY_GENERAL, 'key' => 'low_stock_threshold'],
            [
                'value' => $value,
                'type' => SystemSetting::TYPE_NUMBER,
                'label' => 'Low Stock Alert Threshold',
                'is_active' => true,
            ]
        );
    }

    private function enableAlerts(array $adminRecipients = []): void
    {
        EmailAutomationSetting::updateOrCreate(
            ['key' => EmailAutomationSetting::LOW_STOCK_ALERT],
            [
                'name' => 'Low Stock Alert',
                'is_enabled' => true,
                'config' => ['recipient_emails' => $adminRecipients],
            ]
        );
    }

    private function makeStore(string $name, string $email): Store
    {
        return Store::create([
            'owner_id' => $this->makeUser(['role' => 'store_owner'])->id,
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name).'-'.uniqid(),
            'email' => $email,
            'phone' => '08012345678',
            'address' => '1 Test Road',
            'city' => 'Ikeja',
            'state' => 'Lagos',
            'status' => 'active',
            'verification_status' => Store::VERIFICATION_APPROVED,
        ]);
    }

    public function test_each_pharmacy_is_told_about_its_own_stock_only(): void
    {
        Queue::fake();
        $this->setThreshold(10);
        $this->enableAlerts();

        $one = $this->makeStore('Ada Pharmacy', 'ada@pharmacy.test');
        $two = $this->makeStore('Bola Chemists', 'bola@pharmacy.test');

        Product::factory()->create(['store_id' => $one->id, 'name' => 'Ada Low', 'stock_quantity' => 3, 'is_active' => true]);
        Product::factory()->create(['store_id' => $two->id, 'name' => 'Bola Low', 'stock_quantity' => 2, 'is_active' => true]);

        $this->artisan('stock:check-low')->assertExitCode(0);

        Queue::assertPushed(SendLowStockAlert::class, 2);

        Queue::assertPushed(SendLowStockAlert::class, function (SendLowStockAlert $job) use ($one) {
            return $job->recipientEmail === $one->email
                && $job->storeName === 'Ada Pharmacy'
                && count($job->lowStockProducts) === 1
                && $job->lowStockProducts[0]['name'] === 'Ada Low';
        });

        Queue::assertPushed(SendLowStockAlert::class, function (SendLowStockAlert $job) use ($two) {
            return $job->recipientEmail === $two->email
                && count($job->lowStockProducts) === 1
                && $job->lowStockProducts[0]['name'] === 'Bola Low';
        });
    }

    public function test_the_settings_page_threshold_is_what_triggers_the_email(): void
    {
        Queue::fake();
        $this->enableAlerts();

        $store = $this->makeStore('Threshold Pharmacy', 'threshold@pharmacy.test');
        Product::factory()->create(['store_id' => $store->id, 'stock_quantity' => 8, 'is_active' => true]);

        $this->setThreshold(5);
        $this->artisan('stock:check-low')->assertExitCode(0);
        Queue::assertNothingPushed();

        $this->setThreshold(10);
        $this->artisan('stock:check-low')->assertExitCode(0);
        Queue::assertPushed(SendLowStockAlert::class, function (SendLowStockAlert $job) {
            return $job->threshold === 10;
        });
    }

    public function test_platform_owned_stock_goes_to_the_configured_admins(): void
    {
        Queue::fake();
        $this->setThreshold(10);
        $this->enableAlerts(['ops@taga.test']);

        Product::factory()->create(['store_id' => null, 'name' => 'Platform Low', 'stock_quantity' => 1, 'is_active' => true]);

        $this->artisan('stock:check-low')->assertExitCode(0);

        Queue::assertPushed(SendLowStockAlert::class, function (SendLowStockAlert $job) {
            return $job->recipientEmail === 'ops@taga.test'
                && $job->storeName === null
                && $job->lowStockProducts[0]['name'] === 'Platform Low';
        });
    }

    public function test_out_of_stock_is_not_a_restock_warning(): void
    {
        // Zero is a different problem with a different message; this alert is
        // only actionable while there is still something on the shelf.
        Queue::fake();
        $this->setThreshold(10);
        $this->enableAlerts();

        $store = $this->makeStore('Empty Pharmacy', 'empty@pharmacy.test');
        Product::factory()->create(['store_id' => $store->id, 'stock_quantity' => 0, 'is_active' => true]);

        $this->artisan('stock:check-low')->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_nothing_is_sent_when_the_automation_is_switched_off(): void
    {
        Queue::fake();
        $this->setThreshold(10);

        EmailAutomationSetting::updateOrCreate(
            ['key' => EmailAutomationSetting::LOW_STOCK_ALERT],
            ['name' => 'Low Stock Alert', 'is_enabled' => false, 'config' => ['recipient_emails' => []]]
        );

        $store = $this->makeStore('Quiet Pharmacy', 'quiet@pharmacy.test');
        Product::factory()->create(['store_id' => $store->id, 'stock_quantity' => 1, 'is_active' => true]);

        $this->artisan('stock:check-low')->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_the_dashboards_use_the_same_threshold(): void
    {
        // The figure an operator sets must be the figure they then see counted.
        $this->setThreshold(20);

        $store = $this->makeStore('Dashboard Pharmacy', 'dash@pharmacy.test');
        Product::factory()->create(['store_id' => $store->id, 'stock_quantity' => 15, 'is_active' => true]);

        $this->assertSame(1, Product::active()->lowStock()->count(), '15 is low when the threshold is 20');

        $this->setThreshold(10);

        $this->assertSame(0, Product::active()->lowStock()->count(), '15 is not low when the threshold is 10');
    }
}
