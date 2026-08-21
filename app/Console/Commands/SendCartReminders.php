<?php

namespace App\Console\Commands;

use App\Jobs\SendCartReminderEmail;
use App\Models\Cart;
use App\Models\EmailAutomationSetting;
use App\Models\EmailLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Remind shoppers about a basket they walked away from.
 *
 * The job and the email template were written long ago; nothing ever dispatched
 * them, so the three abandoned-cart switches in the admin sat on with no effect.
 * This is the missing half.
 */
class SendCartReminders extends Command
{
    protected $signature = 'cart:remind {--dry-run : List who would be emailed without sending}';

    protected $description = 'Email shoppers about baskets they left behind';

    /**
     * Stage => how long the basket must have been untouched.
     *
     * Ordered longest first: a basket that has sat for a week is past all three,
     * and the useful message is the last one, not the first. Sending "you left
     * something an hour ago" five days late reads as broken.
     */
    private const STAGES = [
        '3d' => 72,
        '24h' => 24,
        '1h' => 1,
    ];

    public function handle(): int
    {
        $enabled = array_filter(
            array_keys(self::STAGES),
            fn (string $stage) => EmailAutomationSetting::isEnabled('abandoned_cart_'.$stage)
        );

        if (empty($enabled)) {
            $this->info('All abandoned-cart reminders are switched off.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        /*
         * Only signed-in shoppers can be reminded — a guest basket is keyed by
         * session id and carries no address to write to.
         */
        $baskets = Cart::query()
            ->whereNotNull('user_id')
            ->with(['product', 'user'])
            ->get()
            ->groupBy('user_id');

        $sent = 0;

        foreach ($baskets as $userId => $items) {
            $user = $items->first()->user;

            if (! $user || ! $user->email) {
                continue;
            }

            // Untouched since the last time anything in the basket changed;
            // adding an item is activity and restarts the clock.
            $lastActivity = $items->max(fn (Cart $item) => $item->updated_at ?? $item->created_at);
            $episodeStart = $items->min(fn (Cart $item) => $item->created_at ?? $item->updated_at);

            if (! $lastActivity) {
                continue;
            }

            $stage = $this->dueStage(Carbon::parse($lastActivity), $enabled);

            if ($stage === null) {
                continue;
            }

            if ($this->alreadyReminded($userId, $stage, $episodeStart)) {
                continue;
            }

            $lines = $items
                ->filter(fn (Cart $item) => $item->product !== null)
                ->map(fn (Cart $item) => [
                    'name' => $item->product->name,
                    'quantity' => $item->quantity,
                    'price' => (float) $item->price,
                    'image' => is_array($item->product->images) ? ($item->product->images[0] ?? null) : null,
                ])
                ->values()
                ->all();

            if (empty($lines)) {
                // Every product in the basket has since been removed; there is
                // nothing left to come back to.
                continue;
            }

            $total = array_sum(array_map(fn (array $line) => $line['price'] * $line['quantity'], $lines));

            $this->line(sprintf(
                '  %-32s %-4s %d item(s)  %s',
                $user->email,
                $stage,
                count($lines),
                number_format($total, 2)
            ));

            if (! $dryRun) {
                SendCartReminderEmail::dispatch($user, $lines, (float) $total, $stage);
            }

            $sent++;
        }

        $this->info($dryRun
            ? "{$sent} reminder(s) would be sent."
            : "Cart reminders queued: {$sent}");

        return self::SUCCESS;
    }

    /**
     * The longest stage the basket has passed and that is switched on.
     *
     * @param  array<int, string>  $enabled
     */
    private function dueStage(Carbon $lastActivity, array $enabled): ?string
    {
        $hoursIdle = $lastActivity->diffInHours(now());

        foreach (self::STAGES as $stage => $hours) {
            if ($hoursIdle >= $hours && in_array($stage, $enabled, true)) {
                return $stage;
            }
        }

        return null;
    }

    /**
     * Whether this stage was already sent for this basket.
     *
     * Scoped to the current episode rather than to all time: someone who
     * abandoned a basket in March, bought, and abandoned another in August
     * should hear from us again. email_logs already carries the user and a
     * `cart_reminder_{stage}` type, so no extra bookkeeping table is needed.
     */
    private function alreadyReminded(int $userId, string $stage, $episodeStart): bool
    {
        return EmailLog::query()
            ->where('user_id', $userId)
            ->where('type', 'cart_reminder_'.$stage)
            ->when($episodeStart, fn ($query) => $query->where('created_at', '>=', $episodeStart))
            ->exists();
    }
}
