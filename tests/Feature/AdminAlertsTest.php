<?php

namespace Tests\Feature;

use App\Mail\AdminAlertEmail;
use App\Mail\OrderNotificationEmail;
use App\Mail\OrderStatusEmail;
use App\Mail\PayoutRequestedEmail;
use App\Models\ConsultationRequest;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Store;
use App\Models\User;
use App\Support\PlatformAdmins;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * What the platform's administrators are told, and who "administrator" means.
 *
 * Four places had four answers to that second question. Two resolved real admin
 * accounts, one resolved `config('mail.admin_email', env('ADMIN_EMAIL', ...))`
 * against a key config/mail.php never declared — so it always fell through to
 * its default, and that default calls env() outside a config file, which
 * returns null once `config:cache` has run. Order notifications from a
 * cached-config deploy went to the literal admin@example.com. The fourth had no
 * notion of an administrator and sent nothing.
 */
class AdminAlertsTest extends TestCase
{
    private function admins(int $count = 2): array
    {
        return collect(range(1, $count))
            ->map(fn ($i) => $this->makeUser(['role' => 'admin']))
            ->all();
    }

    private function order(): Order
    {
        $user = $this->makeUser();

        $address = [
            'firstName' => 'Ada',
            'lastName' => 'Obi',
            'email' => 'ada@example.test',
            'phone' => '08000000000',
            'address' => '1 Test Road',
            'city' => 'Lagos',
            'state' => 'Lagos',
            'country' => 'Nigeria',
        ];

        return Order::create([
            'user_id' => $user->id,
            'order_number' => 'TEST-AA-'.uniqid(),
            'status' => Order::STATUS_PENDING,
            'payment_status' => Order::PAYMENT_PENDING,
            'subtotal' => 1000,
            'total_amount' => 1000,
            'shipping_address' => $address,
            'billing_address' => $address,
        ]);
    }

    /* ------------------------------------------------ who counts as admin */

    public function test_real_admin_accounts_are_preferred_over_the_configured_address(): void
    {
        config(['mail.admin_email' => 'fallback@example.test']);

        [$one, $two] = $this->admins();

        $emails = PlatformAdmins::emails();

        $this->assertContains($one->email, $emails);
        $this->assertContains($two->email, $emails);
        $this->assertNotContains('fallback@example.test', $emails);
    }

    public function test_the_configured_address_is_a_fallback_not_a_default(): void
    {
        // No admin users at all. DatabaseTransactions gives a clean slate per
        // test, but the seeded database may hold real ones, so this asserts the
        // branch rather than the environment.
        User::where('role', 'admin')->update(['role' => 'customer']);

        config(['mail.admin_email' => 'fallback@example.test']);

        $this->assertSame(['fallback@example.test'], PlatformAdmins::emails());
    }

    public function test_one_message_each_rather_than_one_message_to_everyone(): void
    {
        Mail::fake();

        [$one, $two] = $this->admins();

        $sent = PlatformAdmins::notify(
            fn () => new AdminAlertEmail(
                subject: 'Test', heading: 'Test', intro: 'Test.',
            ),
            'a test'
        );

        $this->assertSame(2, $sent);

        // A Mailable accumulates recipients on to(), so reusing one instance
        // across the loop would send the second message to both people. Each
        // message must carry exactly one address.
        Mail::assertSent(AdminAlertEmail::class, 2);
        Mail::assertSent(AdminAlertEmail::class, fn ($mail) => $mail->hasTo($one->email));
        Mail::assertSent(AdminAlertEmail::class, fn ($mail) => $mail->hasTo($two->email));
    }

    public function test_one_bad_address_does_not_stop_the_others(): void
    {
        [$one, $two] = $this->admins();

        $attempted = [];

        Mail::shouldReceive('to')->andReturnUsing(function ($recipient) use (&$attempted, $one) {
            $attempted[] = $recipient;

            if ($recipient === $one->email) {
                throw new \RuntimeException('mailbox full');
            }

            return new class {
                public function send($mailable) {}
            };
        });

        $sent = PlatformAdmins::notify(
            fn () => new AdminAlertEmail(subject: 'T', heading: 'T', intro: 'T.'),
            'a test'
        );

        $this->assertSame([$one->email, $two->email], $attempted);
        $this->assertSame(1, $sent, 'the second administrator is still notified');
    }

    /* ------------------------------------------- the collapse of two into one */

    public function test_paying_sends_the_customer_one_email_not_two(): void
    {
        Mail::fake();

        $this->admins(1);
        $order = $this->order();

        $transaction = PaymentTransaction::create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'reference' => 'TEST-COLLAPSE-'.uniqid(),
            'amount' => 1000,
            'status' => PaymentTransaction::STATUS_PENDING,
            'gateway' => PaymentTransaction::GATEWAY_PAYSTACK,
        ]);

        $transaction->markAsSuccessful(['id' => 1]);

        $customer = $order->user->email;

        // The survivor: it carries the delivery code, which is the one thing in
        // a post-payment email a customer has to keep.
        Mail::assertSent(OrderStatusEmail::class, fn ($mail) => $mail->hasTo($customer));

        // And the duplicate is gone. The customer used to receive this as well,
        // in the same second, saying the same thing in a different template.
        Mail::assertNotSent(
            OrderNotificationEmail::class,
            fn ($mail) => $mail->notificationType === 'order_placed'
        );
    }

    public function test_the_trade_side_is_still_told_when_an_order_is_paid(): void
    {
        Mail::fake();

        [$admin] = $this->admins(1);
        $order = $this->order();

        (new \App\Services\OrderNotificationService())->notifyOrderPlaced($order);

        // Collapsing the customer's two emails must not have taken the
        // administrator's notification with it — and it must reach a real
        // admin account rather than admin@example.com.
        Mail::assertSent(
            OrderNotificationEmail::class,
            fn ($mail) => $mail->notificationType === 'new_order' && $mail->hasTo($admin->email)
        );
    }

    /* ------------------------------------------------------- the new alerts */

    public function test_a_rider_payout_request_reaches_an_administrator(): void
    {
        Mail::fake();

        [$admin] = $this->admins(1);

        \App\Services\AdminAlerts::payoutRequested(
            (object) ['id' => 42, 'amount' => 7500, 'created_at' => now(), 'bank_details' => []],
            'delivery_agent',
            'Chidi the rider',
            'rider@example.test'
        );

        // Riders were the one requester type nobody was told about: stores and
        // logistics companies both emailed on request, a rider's withdrawal
        // debited their balance and then waited to be noticed.
        Mail::assertSent(
            PayoutRequestedEmail::class,
            fn ($mail) => $mail->requesterType === 'delivery_agent' && $mail->hasTo($admin->email)
        );
    }

    public function test_a_consultation_reaches_an_administrator(): void
    {
        Mail::fake();

        [$admin] = $this->admins(1);

        $this->postJson('/api/v1/consultations', [
            'practitioner_type' => 'pharmacist',
            'name' => 'Ada Obi',
            'email' => 'ada@example.test',
            'message' => 'Can I take this with my blood pressure tablets?',
            'session_id' => 'guest-consult-'.uniqid(),
        ])->assertCreated();

        Mail::assertSent(AdminAlertEmail::class, fn ($mail) => $mail->hasTo($admin->email));
    }

    public function test_the_consultation_alert_does_not_carry_the_message_body(): void
    {
        Mail::fake();

        $this->admins(1);

        $secret = 'I have been diagnosed with something private';

        $this->postJson('/api/v1/consultations', [
            'practitioner_type' => 'doctor',
            'name' => 'Ada Obi',
            'email' => 'ada@example.test',
            'message' => $secret,
            'session_id' => 'guest-consult-'.uniqid(),
        ])->assertCreated();

        // A health question from a named person. The reply is composed in the
        // dashboard where the thread lives; copying it into every
        // administrator's inbox spreads it further than it needs to go.
        Mail::assertSent(AdminAlertEmail::class, function (AdminAlertEmail $mail) use ($secret) {
            return ! str_contains($mail->render(), $secret);
        });
    }

    public function test_a_pharmacy_application_reaches_an_administrator(): void
    {
        Mail::fake();

        [$admin] = $this->admins(1);

        $store = Store::create([
            'owner_id' => $this->makeUser(['role' => 'customer'])->id,
            'name' => 'Applicant Pharmacy',
            'slug' => 'app-'.uniqid(),
            'email' => 'shop'.uniqid().'@pharmacy.test',
            'state' => 'Lagos',
            'city' => 'Ikeja',
            'status' => 'inactive',
            'verification_status' => Store::VERIFICATION_PENDING,
        ]);

        \App\Services\AdminAlerts::pharmacyApplied($store);

        Mail::assertSent(AdminAlertEmail::class, function (AdminAlertEmail $mail) use ($admin, $store) {
            return $mail->hasTo($admin->email)
                && str_contains($mail->subject, $store->name);
        });
    }

    public function test_alerts_never_throw_into_the_caller(): void
    {
        // Every one of these runs after something irreversible has happened —
        // an order is paid, a balance is debited, an application is saved. A
        // mail failure must not propagate back and undo it.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $this->admins(1);

        $consultation = ConsultationRequest::create([
            'reference' => ConsultationRequest::generateReference(),
            'practitioner_type' => 'pharmacist',
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'message' => 'hello',
            'status' => ConsultationRequest::STATUS_OPEN,
            'session_id' => 'guest-'.uniqid(),
        ]);

        $this->assertSame(0, \App\Services\AdminAlerts::consultationRaised($consultation));
    }
}
