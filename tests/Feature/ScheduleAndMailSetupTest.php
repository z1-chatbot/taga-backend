<?php

namespace Tests\Feature;

use App\Mail\Concerns\SendsFromMailbox;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * The scheduled tasks are registered, and the queue that carries every email
 * gets drained.
 *
 * These existed for months in app/Console/Kernel.php, which Laravel 11 and 12
 * never load — `schedule:list` reported no tasks at all while the file sat
 * there full of them. Nothing failed loudly; the daily report, the low stock
 * alerts and the licence warnings simply never ran. A test is the only thing
 * that notices that kind of silence.
 */
class ScheduleAndMailSetupTest extends TestCase
{
    /** @return array<int, string> */
    private function scheduledSummaries(): array
    {
        // Both, not either: ->name() populates description and hides the command
        // string, so a task named 'low-stock-alerts' would not match a search
        // for the `stock:check-low` it actually runs.
        return collect(app(Schedule::class)->events())
            ->map(fn ($event) => trim(($event->description ?? '').' '.($event->command ?? '')))
            ->all();
    }

    public function test_the_schedule_is_registered_at_all(): void
    {
        $this->assertNotEmpty(
            app(Schedule::class)->events(),
            'no scheduled tasks are registered — check routes/console.php is still loaded '
                .'by bootstrap/app.php withRouting(commands: ...)'
        );
    }

    public function test_the_queue_is_drained_every_minute(): void
    {
        // Every email is a ShouldQueue job and QUEUE_CONNECTION is `database`,
        // so without this nothing the platform sends is ever delivered.
        $drain = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command ?? '', 'queue:work'));

        $this->assertNotNull($drain, 'nothing drains the queue; queued email would never be sent');
        $this->assertSame('* * * * *', $drain->expression, 'the queue should be drained every minute');
        $this->assertStringContainsString(
            '--stop-when-empty',
            $drain->command,
            'the worker must exit when the backlog clears rather than idling as a daemon'
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tasksThatMustRun')]
    public function test_task_is_scheduled(string $needle): void
    {
        $found = collect($this->scheduledSummaries())
            ->contains(fn (string $summary) => str_contains($summary, $needle));

        $this->assertTrue($found, "{$needle} is not scheduled, so it will never run");
    }

    public static function tasksThatMustRun(): array
    {
        return [
            'low stock alerts' => ['stock:check-low'],
            'daily sales report' => ['sales:daily-report'],
            'licence expiry warnings' => ['licences:warn-expiring'],
            'release held earnings' => ['release-pending-earnings'],
            'open and close promotions' => ['open-and-close-promotions'],
            'abandoned cart reminders' => ['cart:remind'],
        ];
    }

    // ---- the three mailboxes -------------------------------------------------

    /**
     * Each mailbox is a separate SMTP account, so each needs its own From to
     * match its own login. One mismatch kills that category of mail alone —
     * password resets could stop while orders keep sending.
     *
     * @return array<string, array{0: string}>
     */
    public static function mailboxes(): array
    {
        return [
            'noreply' => ['noreply'],
            'shop' => ['shop'],
            'support' => ['support'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mailboxes')]
    public function test_mailbox_sends_as_the_account_it_authenticates_as(string $mailbox): void
    {
        /*
         * Checked at the source rather than through config(), because both
         * values are resolved from env at boot — setting one afterwards cannot
         * re-derive the other, so a runtime assertion would prove nothing.
         *
         * The invariant that matters: a mailer's From must come from the same
         * env var as its username. Point it anywhere else and that mailbox
         * starts getting "553 Sender address rejected" on every message.
         */
        $source = file_get_contents(config_path('mail.php'));
        $var = 'MAIL_'.strtoupper($mailbox).'_USERNAME';

        $this->assertStringContainsString(
            "'username' => env('{$var}'",
            $source,
            "the {$mailbox} mailer should authenticate with {$var}"
        );

        $this->assertStringContainsString(
            "'address' => env('{$var}'",
            $source,
            "the {$mailbox} mailer must send as {$var}, the address it logs in as"
        );
    }

    /**
     * Each mailbox is a mailer in its own right, and is a real SMTP account
     * whenever the default driver is.
     *
     * The transport is not asserted to be 'smtp' outright: messages are now
     * routed to their mailbox rather than the default mailer, so a hardcoded
     * 'smtp' here would have every test that sends open a live connection to
     * Hostinger. config/mail.php makes the mailboxes follow the default driver
     * when it is array or log, which is what this checks instead.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('mailboxes')]
    public function test_mailbox_is_configured_as_a_real_mailer(string $mailbox): void
    {
        $this->assertIsArray(
            config("mail.mailers.{$mailbox}"),
            "the {$mailbox} mailbox is missing from config/mail.php"
        );

        $this->assertSame(
            config('mail.default') === 'smtp' ? 'smtp' : config('mail.default'),
            config("mail.mailers.{$mailbox}.transport"),
            "the {$mailbox} mailbox should follow the default driver rather than reaching for live SMTP"
        );
    }

    public function test_replies_to_unattended_mailboxes_reach_a_human(): void
    {
        // noreply and shop are not watched; a customer who hits reply should
        // still land somewhere someone reads.
        foreach (['noreply', 'shop'] as $mailbox) {
            $this->assertNotNull(
                config("mail.mailers.{$mailbox}.reply_to.address"),
                "{$mailbox} needs a reply-to so replies are not lost"
            );
        }
    }

    public function test_the_mail_doctor_reports_a_sender_mismatch(): void
    {
        // The failure that rejected the first 53 emails: authenticating as one
        // mailbox and trying to send as another.
        config([
            'mail.mailers.shop.username' => 'hello@z1stores.com',
            'mail.mailers.shop.from.address' => 'shop@taga.ng',
        ]);

        $this->artisan('mail:doctor')
            ->expectsOutputToContain('From address does not match this SMTP account')
            ->assertExitCode(1);
    }

    public function test_the_mail_doctor_is_satisfied_when_every_mailbox_agrees(): void
    {
        foreach (['noreply', 'shop', 'support'] as $mailbox) {
            config([
                "mail.mailers.{$mailbox}.username" => "{$mailbox}@taga.ng",
                "mail.mailers.{$mailbox}.from.address" => "{$mailbox}@taga.ng",
            ]);
        }

        config(['mail.default' => 'smtp', 'queue.default' => 'sync']);

        $this->artisan('mail:doctor')->assertExitCode(0);
    }

    /**
     * Every mailable declares which mailbox it goes out from, and applies the
     * trait that makes the declaration stick.
     *
     * A new one that forgets sends from whichever address MAIL_FROM_ADDRESS
     * happens to hold — the wrong identity at best, a 553 rejection at worst.
     * Nothing else in the system would notice.
     */
    public function test_every_mailable_is_routed_to_a_mailbox(): void
    {
        $unrouted = [];
        $unknown = [];
        $unenforced = [];

        foreach (glob(app_path('Mail/*.php')) as $file) {
            $source = file_get_contents($file);
            $name = basename($file, '.php');

            // Single-quoted: in a double-quoted PHP string "\$mailbox" would
            // interpolate an undefined variable rather than match a literal.
            if (! preg_match('~protected string \$mailbox = \'([a-z_]+)\'~', $source, $matches)) {
                $unrouted[] = $name;

                continue;
            }

            if (! array_key_exists($matches[1], self::mailboxes())) {
                $unknown[] = "{$name} -> {$matches[1]}";
            }

            // Declaring it is not enough. Without the trait the framework
            // overwrites the choice on the way out — see SendsFromMailbox.
            if (! str_contains($source, 'SendsFromMailbox')) {
                $unenforced[] = $name;
            }
        }

        $this->assertSame([], $unrouted, 'these mailables do not declare a $mailbox: '.implode(', ', $unrouted));
        $this->assertSame([], $unknown, 'these mailables name a mailbox that does not exist: '.implode(', ', $unknown));
        $this->assertSame([], $unenforced, 'these mailables declare a $mailbox but never apply SendsFromMailbox, so it is ignored: '.implode(', ', $unenforced));
    }

    /**
     * The declared mailbox survives dispatch.
     *
     * This is the test that was missing, and its absence cost real messages.
     * Asserting the declaration existed proved nothing: Mailer::sendMailable()
     * overwrites $mailer with the name of whichever mailer picked the message
     * up, before the message is built. Mail::to() always runs through the
     * default mailer, so every email the platform sent went out as the default
     * identity — authenticating as MAIL_USERNAME while claiming to be
     * MAIL_FROM_ADDRESS, which is exactly the pair the server rejects:
     *
     *     553 5.7.1 <support@taga.ng>: Sender address rejected:
     *                not owned by user hello@z1stores.com
     *
     * So this dispatches the way the application really does, then checks where
     * the message actually landed and what it claimed to be.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('mailboxes')]
    public function test_the_declared_mailbox_survives_dispatch(string $mailbox): void
    {
        config(["mail.mailers.{$mailbox}.from.address" => "{$mailbox}@taga.ng"]);
        Mail::forgetMailers();

        Mail::to('someone@example.com')->send(new MailboxProbe($mailbox));

        $sent = Mail::mailer($mailbox)->getSymfonyTransport()->messages();

        $this->assertCount(1, $sent, "a message declaring the {$mailbox} mailbox did not leave through it");
        $this->assertSame(
            "{$mailbox}@taga.ng",
            $sent[0]->getOriginalMessage()->getFrom()[0]->getAddress(),
            "the message left the {$mailbox} mailbox claiming to be someone else"
        );
    }

    /**
     * A mailable that declares no mailbox fails loudly rather than borrowing
     * the default identity, which the mail server would reject anyway — later,
     * and with a message nobody connects back to the missing declaration.
     */
    public function test_a_mailable_without_a_mailbox_refuses_to_send(): void
    {
        $this->expectException(\LogicException::class);

        Mail::to('someone@example.com')->send(new UnroutedProbe());
    }
}

/**
 * Stands in for a real mailable so routing can be exercised without dragging a
 * model graph into the test. Same trait, same declaration, same dispatch path.
 */
class MailboxProbe extends Mailable
{
    use SendsFromMailbox;

    protected string $mailbox;

    public function __construct(string $mailbox)
    {
        $this->mailbox = $mailbox;
    }

    public function build()
    {
        return $this->subject('probe')->html('probe');
    }
}

/** A mailable someone forgot to route. */
class UnroutedProbe extends Mailable
{
    use SendsFromMailbox;

    public function build()
    {
        return $this->subject('probe')->html('probe');
    }
}
