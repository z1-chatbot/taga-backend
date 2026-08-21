<?php

namespace Tests\Feature;

use App\Jobs\SendCartReminderEmail;
use App\Models\Cart;
use App\Models\EmailAutomationSetting;
use App\Models\EmailLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Abandoned basket reminders.
 *
 * The job and the email template existed for months; nothing dispatched them,
 * so all three switches in the admin sat on and sent nothing. These cover the
 * dispatcher, and in particular the two ways a drip campaign embarrasses you:
 * sending the same reminder twice, and sending "you left something an hour ago"
 * five days late.
 */
class CartReminderTest extends TestCase
{
    private function enableAll(): void
    {
        foreach (['1h', '24h', '3d'] as $stage) {
            EmailAutomationSetting::updateOrCreate(
                ['key' => 'abandoned_cart_'.$stage],
                ['name' => 'Abandoned cart '.$stage, 'is_enabled' => true, 'config' => []]
            );
        }
    }

    private function abandonedBasket(User $user, int $hoursIdle, int $quantity = 2): Cart
    {
        $product = Product::factory()->create(['price' => 1500]);

        $cart = Cart::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => 1500,
        ]);

        // Written straight to the columns: Eloquent would otherwise stamp
        // updated_at to now on save and the basket would never look idle.
        Cart::where('id', $cart->id)->update([
            'created_at' => now()->subHours($hoursIdle),
            'updated_at' => now()->subHours($hoursIdle),
        ]);

        return $cart->fresh();
    }

    public function test_a_basket_idle_for_an_hour_earns_the_first_reminder(): void
    {
        Queue::fake();
        $this->enableAll();
        $user = $this->makeUser(['role' => 'customer']);
        $this->abandonedBasket($user, 2);

        $this->artisan('cart:remind')->assertExitCode(0);

        Queue::assertPushed(SendCartReminderEmail::class, function (SendCartReminderEmail $job) use ($user) {
            return $job->user->id === $user->id
                && $job->reminderType === '1h'
                && $job->cartTotal === 3000.0
                && count($job->cartItems) === 1;
        });
    }

    public function test_a_long_dead_basket_gets_the_last_reminder_not_the_first(): void
    {
        // Telling someone they left something "an hour ago" five days later is
        // the failure mode that makes drip campaigns look broken.
        Queue::fake();
        $this->enableAll();
        $this->abandonedBasket($this->makeUser(['role' => 'customer']), 24 * 5);

        $this->artisan('cart:remind')->assertExitCode(0);

        Queue::assertPushed(SendCartReminderEmail::class, fn ($job) => $job->reminderType === '3d');
        Queue::assertPushed(SendCartReminderEmail::class, 1);
    }

    public function test_a_fresh_basket_is_left_alone(): void
    {
        Queue::fake();
        $this->enableAll();
        $this->abandonedBasket($this->makeUser(['role' => 'customer']), 0);

        $this->artisan('cart:remind')->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_the_same_reminder_is_not_sent_twice(): void
    {
        Queue::fake();
        $this->enableAll();
        $user = $this->makeUser(['role' => 'customer']);
        $this->abandonedBasket($user, 2);

        $this->artisan('cart:remind')->assertExitCode(0);
        Queue::assertPushed(SendCartReminderEmail::class, 1);

        // The job records the send; the dispatcher reads that record back.
        EmailLog::logEmail($user->email, 'cart_reminder_1h', 'Cart Reminder - 1h', null, $user->id);

        $this->artisan('cart:remind')->assertExitCode(0);
        Queue::assertPushed(SendCartReminderEmail::class, 1);
    }

    public function test_abandoning_again_later_earns_a_fresh_reminder(): void
    {
        // Dedupe is scoped to the current basket, not to all time.
        Queue::fake();
        $this->enableAll();
        $user = $this->makeUser(['role' => 'customer']);

        $old = EmailLog::logEmail($user->email, 'cart_reminder_1h', 'Cart Reminder - 1h', null, $user->id);
        EmailLog::where('id', $old->id)->update(['created_at' => now()->subMonths(3)]);

        $this->abandonedBasket($user, 2);

        $this->artisan('cart:remind')->assertExitCode(0);

        Queue::assertPushed(SendCartReminderEmail::class, 1);
    }

    public function test_guest_baskets_are_skipped(): void
    {
        // Keyed by session id, with no address to write to.
        Queue::fake();
        $this->enableAll();

        $product = Product::factory()->create(['price' => 900]);
        Cart::create([
            'session_id' => 'guest-'.uniqid(),
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 900,
        ]);
        Cart::where('session_id', 'like', 'guest-%')->update([
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $this->artisan('cart:remind')->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_reminders_send_whatever_the_rows_say(): void
    {
        /*
         * Basket reminders are ordinary shop behaviour, not a campaign an
         * operator opts into, so they have no switch. isEnabled() short-circuits
         * for them — a stale row or a direct database edit cannot silence one.
         */
        Queue::fake();
        $this->enableAll();

        EmailAutomationSetting::where('key', 'like', 'abandoned_cart_%')
            ->update(['is_enabled' => false]);

        $this->abandonedBasket($this->makeUser(['role' => 'customer']), 2);

        $this->artisan('cart:remind')->assertExitCode(0);

        Queue::assertPushed(SendCartReminderEmail::class, 1);
    }

    public function test_dry_run_sends_nothing(): void
    {
        Queue::fake();
        $this->enableAll();
        $this->abandonedBasket($this->makeUser(['role' => 'customer']), 2);

        $this->artisan('cart:remind', ['--dry-run' => true])->assertExitCode(0);

        Queue::assertNothingPushed();
    }
}
