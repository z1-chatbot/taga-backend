<?php

namespace Tests\Feature;

use App\Mail\LicenceExpiryWarningEmail;
use App\Models\Store;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A pharmacy is warned before its licence takes its shop off sale.
 *
 * Selling is gated on a current licence, so the morning after one expires the
 * whole catalogue silently stops being purchasable. Without these warnings the
 * first a shop knows about it is the orders drying up.
 */
class LicenceExpiryWarningTest extends TestCase
{
    private function approvedStore(?int $expiresInDays, array $extra = []): Store
    {
        $owner = $this->makeUser(['role' => 'store_owner']);

        return Store::create(array_merge([
            'owner_id' => $owner->id,
            'name' => 'Test Pharmacy',
            'slug' => 'lic-'.uniqid(),
            'email' => 'shop'.uniqid().'@pharmacy.test',
            'state' => 'Lagos',
            'city' => 'Ikeja',
            'status' => 'active',
            'verification_status' => Store::VERIFICATION_APPROVED,
            'pharmacy_license_expiry' => $expiresInDays === null
                ? null
                : now()->addDays($expiresInDays),
        ], $extra));
    }

    private function runWarnings(): void
    {
        $this->artisan('licences:warn-expiring')->assertSuccessful();
    }

    public function test_a_licence_far_from_expiry_is_left_alone(): void
    {
        Mail::fake();

        $store = $this->approvedStore(90);
        $this->runWarnings();

        Mail::assertNothingSent();
        $this->assertNull($store->fresh()->licence_reminder_stage);
    }

    public function test_the_first_warning_goes_out_a_month_ahead(): void
    {
        Mail::fake();

        $store = $this->approvedStore(25);
        $this->runWarnings();

        Mail::assertSent(LicenceExpiryWarningEmail::class, fn ($mail) => $mail->expired === false);
        $this->assertSame(30, $store->fresh()->licence_reminder_stage);
    }

    public function test_the_same_warning_is_not_repeated_daily(): void
    {
        Mail::fake();

        $this->approvedStore(25);

        $this->runWarnings();
        $this->runWarnings();
        $this->runWarnings();

        Mail::assertSentCount(1);
    }

    public function test_each_milestone_warns_again_as_the_date_closes_in(): void
    {
        Mail::fake();

        $store = $this->approvedStore(25);

        $this->runWarnings();
        $this->assertSame(30, $store->fresh()->licence_reminder_stage);

        // A fortnight out is a new, more urgent milestone.
        $store->update(['pharmacy_license_expiry' => now()->addDays(10)]);
        $this->runWarnings();
        $this->assertSame(14, $store->fresh()->licence_reminder_stage);

        $store->update(['pharmacy_license_expiry' => now()->addDays(3)]);
        $this->runWarnings();
        $this->assertSame(7, $store->fresh()->licence_reminder_stage);

        Mail::assertSentCount(3);
    }

    public function test_a_lapsed_licence_gets_a_final_notice(): void
    {
        Mail::fake();

        $store = $this->approvedStore(-2);
        $this->runWarnings();

        Mail::assertSent(LicenceExpiryWarningEmail::class, fn ($mail) => $mail->expired === true);
        $this->assertSame(-1, $store->fresh()->licence_reminder_stage);

        // And only once.
        $this->runWarnings();
        Mail::assertSentCount(1);
    }

    public function test_a_missed_run_does_not_skip_the_warning(): void
    {
        Mail::fake();

        // The job did not run for a fortnight and the store is now inside a
        // tighter milestone than the one it should have had first.
        $store = $this->approvedStore(5);
        $this->runWarnings();

        Mail::assertSentCount(1);
        $this->assertSame(7, $store->fresh()->licence_reminder_stage);
    }

    public function test_renewing_starts_the_reminders_over(): void
    {
        Mail::fake();

        $store = $this->approvedStore(3);
        $this->runWarnings();

        $this->assertSame(7, $store->fresh()->licence_reminder_stage);

        // A renewal pushes the date out; the old stage must not suppress the
        // next cycle of warnings.
        $store->update([
            'pharmacy_license_expiry' => now()->addYear(),
            'licence_reminder_stage' => null,
        ]);

        $this->runWarnings();

        Mail::assertSentCount(1, 'nothing is due a year out');
        $this->assertNull($store->fresh()->licence_reminder_stage);
    }

    public function test_a_store_with_no_expiry_on_file_is_never_warned(): void
    {
        Mail::fake();

        $this->approvedStore(null);
        $this->runWarnings();

        Mail::assertNothingSent();
    }

    public function test_an_unapproved_store_is_not_warned(): void
    {
        Mail::fake();

        $this->approvedStore(5, ['verification_status' => Store::VERIFICATION_PENDING]);
        $this->runWarnings();

        Mail::assertNothingSent();
    }

    public function test_a_dry_run_sends_nothing(): void
    {
        Mail::fake();

        $store = $this->approvedStore(5);

        $this->artisan('licences:warn-expiring', ['--dry-run' => true])->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertNull($store->fresh()->licence_reminder_stage,
            'a dry run must not record that a warning was sent');
    }
}
