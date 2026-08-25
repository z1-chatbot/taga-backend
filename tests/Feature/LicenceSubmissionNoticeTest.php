<?php

namespace Tests\Feature;

use App\Mail\StoreVerificationSubmittedEmail;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Submitting a pharmacy licence tells somebody.
 *
 * It used to tell nobody, in either direction. The pharmacy uploaded a document
 * and got a JSON response, with no way to distinguish a submission queued for
 * review from one that never arrived; and nothing told the platform there was
 * anything waiting, so the queue was emptied only by someone remembering to
 * open it.
 *
 * The silence matters more than it sounds, because submitting also withdraws
 * `can_sell_prescription` and `can_sell_controlled` until the new licence is
 * approved. A pharmacy renewing on time watched its regulated listings go dark
 * and was told neither that this had happened nor that it was temporary.
 */
class LicenceSubmissionNoticeTest extends TestCase
{
    private function pharmacy(): array
    {
        $owner = $this->makeUser(['role' => 'store_owner']);

        $store = Store::create([
            'owner_id' => $owner->id,
            'name' => 'Submission Test Pharmacy',
            'slug' => 'sub-'.uniqid(),
            'email' => 'shop'.uniqid().'@pharmacy.test',
            'state' => 'Lagos',
            'city' => 'Ikeja',
            'status' => 'active',
            'verification_status' => Store::VERIFICATION_UNSUBMITTED,
        ]);

        return [$owner, $store];
    }

    private function submit(User $owner)
    {
        Storage::fake('local');

        return $this->postJson('/api/v1/stores/verification', [
            'license_document' => UploadedFile::fake()->create('licence.pdf', 200, 'application/pdf'),
        ], $this->tokenFor($owner));
    }

    public function test_the_pharmacy_is_told_its_licence_arrived(): void
    {
        Mail::fake();

        [$owner, $store] = $this->pharmacy();

        $this->submit($owner)->assertOk();

        Mail::assertSent(StoreVerificationSubmittedEmail::class, function ($mail) use ($store) {
            return $mail->forReviewer === false
                && $mail->hasTo($store->email);
        });
    }

    public function test_the_reviewers_are_told_there_is_something_waiting(): void
    {
        Mail::fake();

        $admin = $this->makeUser(['role' => 'admin']);
        [$owner] = $this->pharmacy();

        $this->submit($owner)->assertOk();

        Mail::assertSent(StoreVerificationSubmittedEmail::class, function ($mail) use ($admin) {
            return $mail->forReviewer === true && $mail->hasTo($admin->email);
        });
    }

    public function test_a_failing_mail_server_does_not_lose_the_submission(): void
    {
        // The licence is already saved by the time the notifications run.
        // Losing an upload because an SMTP connection timed out would be a far
        // worse outcome than a missing email, so both sends are caught.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        [$owner, $store] = $this->pharmacy();

        $this->submit($owner)->assertOk();

        $store->refresh();

        $this->assertSame(Store::VERIFICATION_PENDING, $store->verification_status);
        $this->assertNotNull($store->pharmacy_license_document);
    }

    public function test_both_copies_render(): void
    {
        [, $store] = $this->pharmacy();

        foreach ([false, true] as $forReviewer) {
            $html = (new StoreVerificationSubmittedEmail($store, $forReviewer))->render();

            $this->assertNotEmpty($html);
            $this->assertStringContainsString(
                $forReviewer ? 'waiting for review' : 'have your pharmacy licence',
                $html
            );
        }

        // The pharmacy's copy has to say the quiet part: regulated listings are
        // paused while the licence is under review, including for a renewal.
        $pharmacyCopy = (new StoreVerificationSubmittedEmail($store, false))->render();
        $this->assertStringContainsString('Paused until approval', $pharmacyCopy);
    }
}
