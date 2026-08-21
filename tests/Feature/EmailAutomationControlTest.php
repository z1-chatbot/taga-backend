<?php

namespace Tests\Feature;

use App\Models\EmailAutomationSetting;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The admin's Email Automation screen and the jobs agree.
 *
 * Two separate questions, and both have to hold: does the toggle in the browser
 * reach the database, and does the job actually consult it? A switch that saves
 * but gates nothing is the failure mode this whole audit kept turning up.
 */
class EmailAutomationControlTest extends TestCase
{
    private function adminHeaders(): array
    {
        return $this->tokenFor($this->makeUser(['role' => 'admin']));
    }

    private function automation(string $key, bool $enabled = true, array $config = []): EmailAutomationSetting
    {
        return EmailAutomationSetting::updateOrCreate(
            ['key' => $key],
            ['name' => ucfirst(str_replace('_', ' ', $key)), 'is_enabled' => $enabled, 'config' => $config]
        );
    }

    // ---- the screen reaches the database -------------------------------------

    public function test_the_screen_lists_the_automations(): void
    {
        $this->automation('welcome_email');

        $this->getJson('/api/v1/admin/email-automation', $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_toggling_in_the_admin_persists(): void
    {
        // Demonstrated on a campaign: transactional email has no toggle at all,
        // which the always-on tests below cover separately.
        $setting = $this->automation('low_stock_alert', true);

        $this->putJson('/api/v1/admin/email-automation/low_stock_alert/toggle', [], $this->adminHeaders())
            ->assertOk();

        $this->assertFalse((bool) $setting->fresh()->is_enabled, 'the toggle must reach the database');

        $this->putJson('/api/v1/admin/email-automation/low_stock_alert/toggle', [], $this->adminHeaders())
            ->assertOk();

        $this->assertTrue((bool) $setting->fresh()->is_enabled, 'and must toggle back');
    }

    public function test_config_saved_from_the_admin_persists(): void
    {
        $this->automation('low_stock_alert', true, ['recipient_emails' => []]);

        $this->putJson('/api/v1/admin/email-automation/low_stock_alert', [
            'config' => ['recipient_emails' => ['ops@taga.test']],
        ], $this->adminHeaders())->assertOk();

        $this->assertSame(
            ['ops@taga.test'],
            EmailAutomationSetting::where('key', 'low_stock_alert')->first()->config['recipient_emails']
        );
    }

    public function test_the_screen_is_closed_to_non_admins(): void
    {
        $this->automation('welcome_email');

        $this->getJson(
            '/api/v1/admin/email-automation',
            $this->tokenFor($this->makeUser(['role' => 'customer']))
        )->assertForbidden();
    }

    // ---- the jobs consult what was saved -------------------------------------

    public function test_the_welcome_email_sends_whatever_the_row_says(): void
    {
        // It used to be switchable. It is not any more: a new account that
        // verifies and hears nothing looks broken.
        Mail::fake();
        $user = $this->makeUser(['role' => 'customer']);

        $this->automation('welcome_email', false);

        (new \App\Jobs\SendWelcomeEmail($user))->handle();

        Mail::assertSent(\App\Mail\WelcomeEmail::class);
    }

    public function test_the_cart_reminder_sends_whatever_the_row_says(): void
    {
        // Also always-on: leaving a basket behind is ordinary shopper
        // behaviour, and the reminder is part of the shop, not a campaign.
        Mail::fake();
        $user = $this->makeUser(['role' => 'customer']);
        $lines = [['name' => 'Paracetamol', 'quantity' => 1, 'price' => 900.0, 'image' => null]];

        $this->automation('abandoned_cart_1h', false);

        (new \App\Jobs\SendCartReminderEmail($user, $lines, 900.0, '1h'))->handle();

        Mail::assertSent(\App\Mail\CartReminderEmail::class);
    }

    public function test_switching_the_low_stock_alert_off_stops_the_whole_run(): void
    {
        Queue::fake();

        $store = Store::create([
            'owner_id' => $this->makeUser(['role' => 'store_owner'])->id,
            'name' => 'Toggle Pharmacy',
            'slug' => 'toggle-'.uniqid(),
            'email' => 'toggle@pharmacy.test',
            'phone' => '08012345678',
            'address' => '1 Test Road',
            'city' => 'Ikeja',
            'state' => 'Lagos',
            'status' => 'active',
            'verification_status' => Store::VERIFICATION_APPROVED,
        ]);

        SystemSetting::updateOrCreate(
            ['category' => SystemSetting::CATEGORY_GENERAL, 'key' => 'low_stock_threshold'],
            ['value' => 10, 'type' => SystemSetting::TYPE_NUMBER, 'label' => 'Low Stock', 'is_active' => true]
        );

        Product::factory()->create(['store_id' => $store->id, 'stock_quantity' => 2, 'is_active' => true]);

        $this->automation('low_stock_alert', false);
        $this->artisan('stock:check-low')->assertExitCode(0);
        Queue::assertNothingPushed();

        $this->automation('low_stock_alert', true);
        $this->artisan('stock:check-low')->assertExitCode(0);
        Queue::assertPushed(\App\Jobs\SendLowStockAlert::class);
    }

    /**
     * Order status emails resolve their automation key at runtime
     * ('order_status_'.$status). Every one of those keys is transactional, so a
     * customer is told what happened to their order no matter what the rows say.
     */
    public function test_order_status_emails_send_for_every_status(): void
    {
        $order = Order::factory()->create();

        foreach (['confirmed', 'processing', 'shipped', 'delivered', 'cancelled'] as $status) {
            Mail::fake();
            $this->automation('order_status_'.$status, false);

            (new \App\Jobs\SendOrderStatusEmail($order, $status))->handle();

            // assertSent's second argument is a callback, not a message, so the
            // status is asserted through the mailable itself.
            Mail::assertSent(
                \App\Mail\OrderStatusEmail::class,
                fn ($mail) => $mail->statusType === $status
            );
        }
    }

    // ---- no switch without a dispatcher --------------------------------------

    /**
     * Every automation on the screen can actually send something.
     *
     * Seven could not. Three were abandoned-cart stages whose job existed but
     * was never dispatched — they now have `cart:remind`. The other four were
     * removed outright. This fails if a switch reappears without the code to
     * honour it, which is the shape of every settings bug in this codebase.
     */
    public function test_every_automation_has_something_that_can_send_it(): void
    {
        $removed = ['new_product', 'sale_event', 'price_drop', 'back_in_stock'];

        foreach ($removed as $key) {
            $this->assertNotContains(
                $key,
                array_column(EmailAutomationSetting::defaults(), 'key'),
                "{$key} was removed because nothing dispatched it; re-adding the switch "
                    .'means writing the dispatcher first'
            );
        }
    }

    public function test_the_abandoned_cart_stages_now_have_a_dispatcher(): void
    {
        // The gap this whole exercise closed: the job was written, the template
        // was written, and nothing ever called it.
        $source = file_get_contents(app_path('Console/Commands/SendCartReminders.php'));

        $this->assertStringContainsString('SendCartReminderEmail::dispatch', $source);

        $scheduled = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->contains(fn ($event) => str_contains($event->command ?? '', 'cart:remind'));

        $this->assertTrue($scheduled, 'cart:remind must be scheduled or the reminders never run');
    }

    // ---- transactional email cannot be switched off --------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function alwaysOn(): array
    {
        return [
            'welcome' => ['welcome_email'],
            'cart 1h' => ['abandoned_cart_1h'],
            'cart 24h' => ['abandoned_cart_24h'],
            'cart 3d' => ['abandoned_cart_3d'],
            'follow up' => ['order_follow_up'],
            'confirmed' => ['order_status_confirmed'],
            'processing' => ['order_status_processing'],
            'shipped' => ['order_status_shipped'],
            'delivered' => ['order_status_delivered'],
            'cancelled' => ['order_status_cancelled'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('alwaysOn')]
    public function test_transactional_email_cannot_be_toggled_off(string $key): void
    {
        // Someone pays and hears nothing is a support incident, not a setting.
        $this->automation($key, true);

        $this->putJson("/api/v1/admin/email-automation/{$key}/toggle", [], $this->adminHeaders())
            ->assertStatus(422)
            ->assertJsonPath('code', 'always_on');

        $this->assertTrue((bool) EmailAutomationSetting::where('key', $key)->first()->is_enabled);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('alwaysOn')]
    public function test_transactional_email_sends_even_if_the_row_says_otherwise(string $key): void
    {
        // Belt and braces: a stale row, a direct database edit, or a toggle
        // slipped past the API must not silence an order confirmation.
        $this->automation($key, false);

        $this->assertTrue(
            EmailAutomationSetting::isEnabled($key),
            "{$key} must send regardless of the stored flag"
        );
    }

    public function test_the_update_endpoint_cannot_switch_one_off_either(): void
    {
        $this->automation('order_status_shipped', true);

        $this->putJson('/api/v1/admin/email-automation/order_status_shipped', [
            'is_enabled' => false,
        ], $this->adminHeaders())->assertStatus(422);

        $this->assertTrue((bool) EmailAutomationSetting::where('key', 'order_status_shipped')->first()->is_enabled);
    }

    public function test_the_config_of_an_always_on_email_may_still_be_edited(): void
    {
        $this->automation('welcome_email', true, []);

        $this->putJson('/api/v1/admin/email-automation/welcome_email', [
            'config' => ['note' => 'still editable'],
        ], $this->adminHeaders())->assertOk();
    }

    public function test_campaign_email_keeps_its_switch(): void
    {
        // The distinction has to hold in both directions, or "always on" just
        // means "nothing can be turned off".
        foreach (['low_stock_alert', 'daily_sales_report'] as $key) {
            $this->automation($key, true);

            $this->putJson("/api/v1/admin/email-automation/{$key}/toggle", [], $this->adminHeaders())
                ->assertOk();

            $this->assertFalse(
                (bool) EmailAutomationSetting::where('key', $key)->first()->is_enabled,
                "{$key} is a campaign and must stay switchable"
            );
        }
    }

    public function test_the_screen_is_told_which_rows_are_not_switchable(): void
    {
        $this->automation('order_status_shipped', true);
        $this->automation('low_stock_alert', true);

        $rows = collect($this->getJson('/api/v1/admin/email-automation', $this->adminHeaders())
            ->assertOk()
            ->json('data'))
            ->keyBy('key');

        $this->assertTrue($rows['order_status_shipped']['always_on'], 'the card needs this to render a badge instead of a power button');
        $this->assertFalse($rows['low_stock_alert']['always_on'], 'the internal digests keep their switch');
    }
    // ---- the test button tests something -------------------------------------

    /**
     * Every automation knows which mailable it sends, and that mailable names a
     * mailbox that exists.
     *
     * The map is what lets a test send leave through the right account. An
     * automation missing from it would quietly fall back to shop, so a broken
     * noreply would test green.
     */
    public function test_every_automation_resolves_to_a_real_mailbox(): void
    {
        $mailboxes = ['noreply', 'shop', 'support'];
        $unmapped = [];
        $unknown = [];

        foreach (EmailAutomationSetting::defaults() as $default) {
            $key = $default['key'];

            if (! array_key_exists($key, EmailAutomationSetting::MAILABLES)) {
                $unmapped[] = $key;

                continue;
            }

            $mailbox = EmailAutomationSetting::mailboxFor($key);

            if (! in_array($mailbox, $mailboxes, true)) {
                $unknown[] = "{$key} -> {$mailbox}";
            }
        }

        $this->assertSame([], $unmapped, 'these automations name no mailable, so a test send cannot know which mailbox to use: '.implode(', ', $unmapped));
        $this->assertSame([], $unknown, 'these automations resolve to a mailbox that does not exist: '.implode(', ', $unknown));
    }

    /**
     * A test send leaves through the automation's own mailbox.
     *
     * It used to go out on the default mailer with a fixed message, which made
     * the button actively misleading: each mailbox is a separate SMTP account,
     * so the button could report success while the account that carries order
     * mail was rejecting every message.
     */
    public function test_a_test_send_leaves_through_the_automations_own_mailbox(): void
    {
        $this->automation('welcome_email');

        $this->postJson('/api/v1/admin/email-automation/test', [
            'email' => 'someone@example.com',
            'type' => 'welcome_email',
        ], $this->adminHeaders())->assertOk();

        // welcome_email is carried by WelcomeEmail, which declares noreply.
        $this->assertCount(
            1,
            Mail::mailer('noreply')->getSymfonyTransport()->messages(),
            'the test send did not leave through the mailbox the real welcome email uses'
        );

        $this->assertCount(
            0,
            Mail::mailer('shop')->getSymfonyTransport()->messages(),
            'the test send leaked into another mailbox'
        );
    }
}
