<?php

namespace App\Console\Commands;

use App\Models\EmailLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Answer "is email working here?" in one command.
 *
 * Written for shared hosting, where you cannot attach a debugger and the only
 * feedback is whatever a cron job writes to a log. It checks the two things
 * that have actually broken in this app — a From address the SMTP account is
 * not allowed to use, and a queue nothing drains — then optionally sends a
 * real message.
 */
class MailDoctor extends Command
{
    /**
     * The mailboxes and what each is for.
     *
     * Mirrors config/mail.php. Each is a distinct Hostinger account, because an
     * SMTP account may only send as its own address.
     */
    private const MAILBOXES = [
        'noreply' => 'sign-up, verification, password reset',
        'shop' => 'orders, cart, delivery, payouts, partner updates',
        'support' => 'anything a person replies to',
    ];

    protected $signature = 'mail:doctor
        {--send= : Send a test message to this address}
        {--mailbox=shop : Which mailbox the test message goes through}';

    protected $description = 'Check the mail and queue setup, and optionally send a test email';

    public function handle(): int
    {
        $this->components->info('Mail configuration');

        $default = config('mail.default');
        $this->line("  default mailer   {$default}");
        $this->newLine();

        $problems = 0;

        if ($default === 'log' || $default === 'array') {
            $this->warn("  ! MAIL_MAILER is \"{$default}\" — mail with no explicit mailbox stays on the server.");
            $problems++;
        }

        /*
         * Each mailbox is checked separately. A mismatch in any one of them
         * silently kills that whole category of email while the others keep
         * working — password resets could stop while orders still send.
         */
        foreach (self::MAILBOXES as $name => $purpose) {
            $host = config("mail.mailers.{$name}.host");
            $port = config("mail.mailers.{$name}.port");
            $username = config("mail.mailers.{$name}.username");
            $from = config("mail.mailers.{$name}.from.address");

            $this->line("  {$name} — {$purpose}");
            $this->line("    host       {$host}:{$port}");
            $this->line('    auth as    '.($username ?: '(none)'));
            $this->line('    sends from '.($from ?: '(not set)'));

            if (! $username) {
                $this->warn('    ! no credentials of its own — falling back to base MAIL_USERNAME');
            }

            // The failure that produced 53 rejected messages: Hostinger (and
            // most shared SMTP) answers "553 Sender address rejected" when the
            // From address is not the mailbox you authenticated as.
            if ($username && $from && strcasecmp($username, $from) !== 0) {
                $this->warn('    ! From address does not match this SMTP account.');
                $this->line("      Authenticated as : {$username}");
                $this->line("      Sending as       : {$from}");
                $this->line('      This is rejected with "553 Sender address rejected".');
                $problems++;
            }

            $this->newLine();
        }

        $this->newLine();
        $this->components->info('Queue');

        $connection = config('queue.default');
        $this->line("  connection  {$connection}");

        if ($connection === 'sync') {
            $this->line('  Jobs run inline. Nothing to drain, but requests wait for SMTP.');
        } else {
            $pending = $this->pendingJobs();
            $failed = $this->failedJobs();

            $this->line("  pending     {$pending}");
            $this->line("  failed      {$failed}");

            if ($pending > 0) {
                /*
                 * What is waiting, and how long it has waited.
                 *
                 * Both matter before you drain. A backlog that has sat for days
                 * is not just undelivered mail — it is mail that will go out
                 * the moment a worker starts, and an order-status email
                 * arriving a week after the order is worse than one that never
                 * arrives. `attempts` separates the two failure modes this
                 * command exists to tell apart: 0 across the board means
                 * nothing has ever picked these up, whereas a non-zero count
                 * means a worker is running and the sends themselves are
                 * failing.
                 */
                foreach ($this->pendingByType() as $name => $count) {
                    $this->line("    {$count}x {$name}");
                }

                if ($oldest = $this->oldestPendingJob()) {
                    $this->line('    oldest      '.$oldest->diffForHumans());
                }

                $untried = $this->untriedJobs();

                $this->warn("  ! {$pending} job(s) waiting. If this number never falls, nothing is");
                $this->line('    draining the queue. The scheduler does it once a minute, so check');
                $this->line('    the cron entry:');
                $this->line('      * * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1');

                if ($untried === $pending) {
                    $this->line('    All '.$pending.' have attempts=0 — never picked up, so nothing');
                    $this->line('    reached the mail server at all. That is a worker problem, not');
                    $this->line('    an SMTP one.');
                }

                $this->line('    Drain by hand: php artisan queue:work --stop-when-empty');
                $problems++;
            }
        }

        $this->newLine();
        $this->components->info('Delivery history');

        $total = EmailLog::count();

        if ($total === 0) {
            $this->line('  No emails have been attempted yet.');
        } else {
            foreach (EmailLog::selectRaw('status, count(*) c')->groupBy('status')->get() as $row) {
                $this->line('  '.str_pad($row->status, 12).$row->c);
            }

            $lastFailure = EmailLog::whereNotNull('error_message')->latest()->first();

            if ($lastFailure) {
                $this->newLine();
                $this->line('  Most recent failure:');
                $this->line('    '.str($lastFailure->error_message)->limit(200));
            }
        }

        if ($address = $this->option('send')) {
            $this->newLine();
            $this->components->info("Sending a test message to {$address}");

            $mailbox = $this->option('mailbox');

            if (! array_key_exists($mailbox, self::MAILBOXES)) {
                $this->components->error(
                    "Unknown mailbox \"{$mailbox}\". Choose one of: ".implode(', ', array_keys(self::MAILBOXES))
                );

                return self::FAILURE;
            }

            try {
                Mail::mailer($mailbox)->raw(
                    "This is a test from Taga's mail:doctor command, sent through the "
                    ."{$mailbox} mailbox.\n\nIf you are reading this, that account delivers.",
                    fn ($message) => $message->to($address)->subject("Taga mail test ({$mailbox})")
                );

                $this->components->info("Accepted by the SMTP server via {$mailbox}.");
            } catch (\Throwable $e) {
                $this->components->error('Rejected: '.$e->getMessage());
                $problems++;
            }
        }

        $this->newLine();

        if ($problems === 0) {
            $this->components->info('No problems found.');

            return self::SUCCESS;
        }

        $this->components->warn("{$problems} problem(s) found — see above.");

        return self::FAILURE;
    }

    private function pendingJobs(): int
    {
        try {
            return DB::table('jobs')->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Waiting jobs grouped by class, so you can see what a drain would send. */
    private function pendingByType(): array
    {
        try {
            $counts = [];

            foreach (DB::table('jobs')->get(['payload']) as $job) {
                $name = json_decode($job->payload, true)['displayName'] ?? 'unknown';
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }

            arsort($counts);

            return $counts;
        } catch (\Throwable) {
            return [];
        }
    }

    /** Jobs no worker has ever touched — the signature of a stalled queue. */
    private function untriedJobs(): int
    {
        try {
            return DB::table('jobs')->where('attempts', 0)->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function oldestPendingJob(): ?\Illuminate\Support\Carbon
    {
        try {
            $timestamp = DB::table('jobs')->min('created_at');

            // The column is a unix timestamp, not a datetime.
            return $timestamp ? \Illuminate\Support\Carbon::createFromTimestamp((int) $timestamp) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function failedJobs(): int
    {
        try {
            return DB::table('failed_jobs')->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
