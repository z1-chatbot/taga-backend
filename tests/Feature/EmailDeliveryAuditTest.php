<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use ReflectionClass;
use Tests\TestCase;

/**
 * Standing checks on the ways an email can fail to arrive without anyone noticing.
 *
 * Every mail send in this application is wrapped in a try/catch that logs and
 * carries on — correctly, because they all sit after something irreversible has
 * happened and must not undo it. The cost of that choice is that a broken email
 * is invisible in production: nothing fails, nothing 500s, the message simply
 * never turns up. Three real faults reached production behind exactly that
 * silence, and this file is the tripwire for each class of them.
 */
class EmailDeliveryAuditTest extends TestCase
{
    /** @return array<int, string> Fully-qualified mailable class names. */
    private function mailables(): array
    {
        return collect(File::files(app_path('Mail')))
            ->map(fn ($file) => 'App\\Mail\\'.$file->getFilenameWithoutExtension())
            ->filter(fn ($class) => class_exists($class))
            ->filter(fn ($class) => (new ReflectionClass($class))->isSubclassOf(\Illuminate\Mail\Mailable::class))
            ->values()
            ->all();
    }

    public function test_there_are_mailables_to_check(): void
    {
        // Guards the guard: a helper that silently returns nothing would make
        // every assertion below pass without testing anything.
        $this->assertGreaterThan(15, count($this->mailables()));
    }

    public function test_every_mailable_declares_the_mailbox_it_sends_from(): void
    {
        $missing = [];

        foreach ($this->mailables() as $class) {
            $reflection = new ReflectionClass($class);

            if (! $reflection->hasProperty('mailbox')) {
                $missing[] = $class;
            }
        }

        // SendsFromMailbox throws a LogicException when a mailable declares no
        // $mailbox. Each Hostinger mailbox is a separate SMTP account that may
        // only send as its own address, so the wrong one is refused with
        // "553 Sender address rejected" — and that refusal lands inside a
        // caller's catch block, where it becomes a log line nobody reads.
        $this->assertSame([], $missing, 'these mailables declare no $mailbox: '.implode(', ', $missing));
    }

    public function test_every_view_a_mailable_names_actually_exists(): void
    {
        $missing = [];

        foreach ($this->mailables() as $class) {
            $source = file_get_contents((new ReflectionClass($class))->getFileName());

            // Both styles are in use here: the Content object and the older
            // ->view()/->text() builder.
            preg_match_all(
                "/(?:view|text|html)\s*:\s*'([^']+)'|->(?:view|text)\('([^']+)'/",
                $source,
                $matches
            );

            $views = array_filter(array_merge($matches[1], $matches[2]));

            foreach ($views as $view) {
                if (! View::exists($view)) {
                    $missing[] = "{$class} -> {$view}";
                }
            }
        }

        // A missing view throws at render time, inside the caller's catch.
        $this->assertSame([], $missing, "missing views:\n  ".implode("\n  ", $missing));
    }

    public function test_no_runtime_code_reads_the_environment_directly(): void
    {
        $offenders = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            foreach (file($file->getPathname()) as $number => $line) {
                // Comments describing the problem are not the problem.
                $trimmed = ltrim($line);
                if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#')) {
                    continue;
                }

                if (preg_match('/(?<![a-zA-Z_>])env\s*\(/', $line)) {
                    $offenders[] = $file->getRelativePathname().':'.($number + 1);
                }
            }
        }

        // env() outside a config file returns null once `config:cache` has run,
        // because Laravel then never loads the .env at all. That is how
        // `config('mail.admin_email', env('ADMIN_EMAIL', 'admin@example.com'))`
        // came to send every order notification to a documentation domain.
        //
        // CorsMiddleware is the one remaining case and is knowingly excluded:
        // its env() only adds *extra* allowed origins on top of the configured
        // ones, so under a cached config it degrades to the configured list
        // rather than to nothing.
        $allowed = ['Http/Middleware/CorsMiddleware.php:104'];

        $offenders = array_values(array_filter(
            $offenders,
            fn ($offender) => ! in_array(str_replace('\\', '/', $offender), $allowed, true)
        ));

        $this->assertSame([], $offenders, "env() called at runtime:\n  ".implode("\n  ", $offenders));
    }

    public function test_admin_notifications_resolve_through_one_place(): void
    {
        $offenders = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', $file->getRelativePathname());

            // The one place allowed to answer the question.
            if ($relative === 'Support/PlatformAdmins.php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            if (preg_match("/where\s*\(\s*'role'\s*,\s*'admin'\s*\)[^;]*pluck\s*\(\s*'email'/s", $source)) {
                $offenders[] = $relative;
            }
        }

        // Four places used to answer "who are the administrators" and had
        // drifted into three different answers. Resolving it anywhere but
        // PlatformAdmins is how a fifth answer starts.
        $this->assertSame(
            [],
            $offenders,
            "these resolve admin recipients themselves instead of using PlatformAdmins:\n  "
                .implode("\n  ", $offenders)
        );
    }

    public function test_nothing_sends_a_bare_view_instead_of_a_mailable(): void
    {
        $offenders = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            foreach (file($file->getPathname()) as $number => $line) {
                $trimmed = ltrim($line);
                if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#')) {
                    continue;
                }

                // Mail::send('emails.x', ...) and Mail::raw(...) — the facade
                // called with a view or a string rather than with a Mailable.
                if (preg_match('/Mail::(send|raw)\s*\(/', $line)) {
                    $offenders[] = $file->getRelativePathname().':'.($number + 1);
                }
            }
        }

        // This is how the two welcome emails went missing. With no Mailable
        // there is no SendsFromMailbox, so the message leaves through the
        // default mailer — which authenticates as MAIL_USERNAME while declaring
        // the global MAIL_FROM_ADDRESS as its From. Each Hostinger mailbox may
        // only send as its own address, so that pairing is refused with
        // "553 Sender address rejected" and the message never arrives.
        //
        // It also hid them: every other message was converted to a Mailable,
        // and a search for mailables could not find something that was not one.
        $this->assertSame(
            [],
            $offenders,
            "these send a bare view instead of a Mailable, so they bypass the mailbox routing:
  "
                .implode("
  ", $offenders)
        );
    }

    public function test_the_configured_admin_address_is_a_declared_config_key(): void
    {
        // Not merely readable — actually declared in config/mail.php, so it
        // survives config:cache. Reading an undeclared key always returns the
        // default, which is what made the old lookup unfixable from .env.
        $this->assertArrayHasKey('admin_email', config('mail'));
    }
}
