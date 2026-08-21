<?php

namespace App\Console\Commands;

use App\Mail\LicenceExpiryWarningEmail;
use App\Models\Store;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Warns pharmacies whose licence is about to lapse, and tells them when it has.
 *
 * Selling is gated on a current licence, so the morning after one expires the
 * shop's whole catalogue silently stops being purchasable. A pharmacy would
 * discover that from the absence of orders rather than from us.
 *
 * Runs daily. Each milestone is sent once — the store remembers which it has
 * had — so a shop thirty days out is not emailed thirty times.
 */
class SendLicenceExpiryWarnings extends Command
{
    protected $signature = 'licences:warn-expiring {--dry-run : List who would be emailed without sending}';

    protected $description = 'Email pharmacies whose licence is expiring or has expired';

    /**
     * Days remaining at which a reminder goes out. -1 stands for "already gone".
     */
    private const MILESTONES = [30, 14, 7, 1, 0, -1];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $stores = Store::query()
            ->where('verification_status', Store::VERIFICATION_APPROVED)
            ->whereNotNull('pharmacy_license_expiry')
            ->get();

        $sent = 0;

        foreach ($stores as $store) {
            $daysRemaining = (int) now()->startOfDay()
                ->diffInDays($store->pharmacy_license_expiry->startOfDay(), false);

            $milestone = $this->milestoneFor($daysRemaining);

            if ($milestone === null) {
                continue;
            }

            // Already told them about this milestone, or a later (more urgent)
            // one. Stages descend, so a smaller stored value means further along.
            if ($store->licence_reminder_stage !== null
                && $store->licence_reminder_stage <= $milestone) {
                continue;
            }

            $recipient = $store->email ?: $store->owner?->email;

            if (! $recipient) {
                Log::warning('Licence expiring but no address to warn', ['store_id' => $store->id]);
                continue;
            }

            $this->line(sprintf(
                '%s (%s) — %s',
                $store->name,
                $recipient,
                $milestone === -1 ? 'licence has expired' : "expires in {$daysRemaining} day(s)"
            ));

            if ($dryRun) {
                continue;
            }

            try {
                Mail::to($recipient)->send(new LicenceExpiryWarningEmail($store, $daysRemaining));
                $store->update(['licence_reminder_stage' => $milestone]);
                $sent++;
            } catch (\Throwable $e) {
                // Leave the stage unchanged so tomorrow's run tries again.
                Log::error('Failed to send licence expiry warning: '.$e->getMessage(), [
                    'store_id' => $store->id,
                ]);
            }
        }

        $this->info($dryRun
            ? 'Dry run complete — nothing was sent.'
            : "Sent {$sent} licence reminder(s).");

        return self::SUCCESS;
    }

    /**
     * The milestone this many days out maps to, or null when none applies.
     *
     * A store that has been quiet for a while can jump past a milestone — a job
     * that did not run for a week must not skip the warning entirely — so this
     * picks the nearest milestone at or above the days remaining.
     */
    private function milestoneFor(int $daysRemaining): ?int
    {
        if ($daysRemaining < 0) {
            return -1;
        }

        $applicable = array_filter(
            self::MILESTONES,
            fn ($m) => $m >= 0 && $daysRemaining <= $m
        );

        return $applicable ? min($applicable) : null;
    }
}
