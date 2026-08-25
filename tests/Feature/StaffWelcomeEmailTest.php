<?php

namespace Tests\Feature;

use App\Mail\StaffWelcomeEmail;
use App\Mail\StoreOwnerWelcomeEmail;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Creating a colleague hands them credentials that actually arrive.
 *
 * Both welcome emails were sent as raw `Mail::send('emails.x', $data, $closure)`
 * — the facade with a view name and no Mailable. With no Mailable there is no
 * SendsFromMailbox, so the message left through the default mailer, which
 * authenticates as MAIL_USERNAME while declaring the global MAIL_FROM_ADDRESS
 * as its From. Each Hostinger mailbox may only send as its own address, and
 * that pairing is refused outright — the exact "553 Sender address rejected"
 * the three named mailboxes exist to avoid.
 *
 * The failure was silent from the screen: the send sits in a queued job that
 * logs and rethrows, so it landed in failed_jobs while the API had already
 * answered "Welcome email sent." A new colleague got an account they could not
 * get into, with no password and nothing on screen saying so.
 */
class StaffWelcomeEmailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // /admin/users is behind `permission:users.create`, and taga_test
        // carries schema but no seed data.
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    public function test_creating_a_staff_user_sends_them_their_password(): void
    {
        Mail::fake();

        $admin = $this->makeUser(['role' => 'admin']);
        $role = Role::where('name', 'manager')->firstOrFail();

        $email = 'newstaff'.uniqid().'@example.test';

        $this->postJson('/api/v1/admin/users/staff', [
            'name' => 'New Colleague',
            'email' => $email,
            'password' => 'Sup3rSecret!',
            'password_confirmation' => 'Sup3rSecret!',
            'role_id' => $role->id,
        ], $this->tokenFor($admin))->assertCreated();

        Mail::assertSent(StaffWelcomeEmail::class, function (StaffWelcomeEmail $mail) use ($email) {
            return $mail->hasTo($email)
                // The password has to be the plain one. It is the entire point
                // of the message, and a hash would be indistinguishable from a
                // working email until somebody tried to sign in.
                && $mail->password === 'Sup3rSecret!';
        });
    }

    public function test_the_new_account_is_pre_verified_so_they_can_sign_in(): void
    {
        Mail::fake();

        $admin = $this->makeUser(['role' => 'admin']);
        $role = Role::where('name', 'manager')->firstOrFail();

        $email = 'newstaff'.uniqid().'@example.test';

        $this->postJson('/api/v1/admin/users/staff', [
            'name' => 'New Colleague',
            'email' => $email,
            'password' => 'Sup3rSecret!',
            'password_confirmation' => 'Sup3rSecret!',
            'role_id' => $role->id,
        ], $this->tokenFor($admin))->assertCreated();

        $created = User::where('email', $email)->firstOrFail();

        $this->assertNotNull($created->email_verified_at);
        $this->assertTrue($created->is_active);

        // The check that matters: login refuses an unverified address with a
        // 403 telling them to look for a verification link that was never sent.
        $this->postJson('/api/v1/login', [
            'email' => $email,
            'password' => 'Sup3rSecret!',
        ])->assertOk();
    }

    public function test_the_message_carries_a_real_sign_in_link(): void
    {
        $user = $this->makeUser(['role' => 'manager']);

        $html = (new StaffWelcomeEmail($user, 'Sup3rSecret!'))->render();

        $this->assertStringContainsString('Sup3rSecret!', $html);
        $this->assertStringContainsString(\App\Support\AppUrl::admin('/login'), $html);
    }

    public function test_the_pharmacy_welcome_link_is_not_a_bare_slash_login(): void
    {
        $user = $this->makeUser(['role' => 'store_owner']);

        $mail = new StoreOwnerWelcomeEmail($user, 'Sup3rSecret!');

        // It used to be built from `config('app.vendor_url')`, a key
        // config/app.php has never declared. It resolved to null, so the button
        // pointed at the literal string "/login" — an invitation to sign in at
        // a link that goes nowhere.
        $this->assertNotSame('/login', $mail->loginUrl);
        $this->assertStringStartsWith('http', $mail->loginUrl);
        $this->assertStringContainsString($mail->loginUrl, $mail->render());
    }

    public function test_both_welcome_emails_leave_through_a_named_mailbox(): void
    {
        foreach ([StaffWelcomeEmail::class, StoreOwnerWelcomeEmail::class] as $class) {
            $property = (new \ReflectionClass($class))->getProperty('mailbox');
            $property->setAccessible(true);

            $mail = new $class($this->makeUser(), 'x');

            // Not the default mailer. That is what made these two undeliverable
            // while every other message in the application arrived.
            $this->assertSame('noreply', $property->getValue($mail), $class);
        }
    }

    public function test_both_render_a_plain_text_alternative(): void
    {
        $user = $this->makeUser(['role' => 'manager']);

        foreach ([
            'emails.staff-welcome-text',
            'emails.store-owner-welcome-text',
        ] as $view) {
            $text = view($view, [
                'user' => $user,
                'password' => 'Sup3rSecret!',
                'loginUrl' => \App\Support\AppUrl::admin('/login'),
            ])->render();

            $this->assertStringContainsString('Sup3rSecret!', $text);
        }
    }
}
