<?php

namespace Tests\Feature;

use App\Mail\ResetPasswordEmail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The password reset flow was a stub: it returned the reset URL — token
 * included — in the HTTP response and never sent an email, so two
 * unauthenticated requests took over any account whose address you knew.
 */
class PasswordResetTest extends TestCase
{
    public function test_the_response_never_contains_the_reset_token(): void
    {
        Mail::fake();

        $user = $this->makeUser();

        $response = $this->postJson('/api/v1/forgot-password', ['email' => $user->email]);

        $response->assertOk();

        $body = $response->getContent();

        $this->assertStringNotContainsString('token', $body);
        $this->assertStringNotContainsString('reset_url', $body);
        $this->assertStringNotContainsString('reset-password?', $body);
    }

    public function test_a_reset_link_is_emailed_to_the_account_holder(): void
    {
        Mail::fake();

        $user = $this->makeUser();

        $this->postJson('/api/v1/forgot-password', ['email' => $user->email])->assertOk();

        Mail::assertSent(ResetPasswordEmail::class, fn ($mail) => $mail->hasTo($user->email));
    }

    public function test_an_unknown_address_gets_the_same_response_and_no_email(): void
    {
        Mail::fake();

        $known = $this->makeUser();

        $a = $this->postJson('/api/v1/forgot-password', ['email' => $known->email]);
        $b = $this->postJson('/api/v1/forgot-password', ['email' => 'nobody@nowhere.test']);

        // Identical responses, or the endpoint reports who has an account here —
        // on a pharmacy that is a disclosure about a person's health purchasing.
        $this->assertSame($a->status(), $b->status());
        $this->assertSame($a->getContent(), $b->getContent());

        Mail::assertSentCount(1);
    }

    public function test_the_stored_token_is_hashed(): void
    {
        Mail::fake();

        $user = $this->makeUser();

        $this->postJson('/api/v1/forgot-password', ['email' => $user->email])->assertOk();

        $row = DB::table('password_reset_tokens')->where('email', $user->email)->first();

        $this->assertNotNull($row);
        $this->assertNotSame(64, strlen($row->token), 'token appears to be stored in the clear');
        $this->assertStringStartsWith('$2y$', $row->token);
    }

    public function test_a_valid_token_resets_the_password_once(): void
    {
        $user = $this->makeUser();
        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => Hash::make($token), 'created_at' => now()]
        );

        $this->postJson('/api/v1/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'BrandNewPass1!',
            'password_confirmation' => 'BrandNewPass1!',
        ])->assertOk();

        $this->assertTrue(Hash::check('BrandNewPass1!', $user->fresh()->password));

        // Replaying the same link must fail.
        $this->postJson('/api/v1/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'AnotherPass1!',
            'password_confirmation' => 'AnotherPass1!',
        ])->assertStatus(400);
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $user = $this->makeUser();
        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => Hash::make($token), 'created_at' => now()->subHours(3)]
        );

        $this->postJson('/api/v1/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'BrandNewPass1!',
            'password_confirmation' => 'BrandNewPass1!',
        ])->assertStatus(400);

        $this->assertFalse(Hash::check('BrandNewPass1!', $user->fresh()->password));
    }

    public function test_a_forged_token_is_rejected(): void
    {
        $user = $this->makeUser();

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => Hash::make(Str::random(64)), 'created_at' => now()]
        );

        $this->postJson('/api/v1/reset-password', [
            'token' => Str::random(64),
            'email' => $user->email,
            'password' => 'BrandNewPass1!',
            'password_confirmation' => 'BrandNewPass1!',
        ])->assertStatus(400);
    }
}
