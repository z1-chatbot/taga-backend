<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ApiToken;
use Tests\TestCase;

/**
 * Regression tests for API bearer tokens.
 *
 * These exist because the original scheme was `base64(id|email|timestamp)` with
 * no signature: anyone could authenticate as any account, including an admin,
 * by constructing that string from a guessable id and a known email. The
 * timestamp was parsed but never enforced, so tokens also never expired.
 *
 * The first test here is the one that matters — if it ever fails, the API is
 * wide open again.
 */
class ApiTokenTest extends TestCase
{
    public function test_an_unsigned_legacy_token_is_rejected(): void
    {
        $user = $this->makeUser(['email' => 'victim@example.com']);

        $forged = base64_encode($user->id.'|'.$user->email.'|'.time());

        $this->getJson('/api/v1/user', ['Authorization' => 'Bearer '.$forged])
            ->assertStatus(401);
    }

    public function test_a_token_with_a_wrong_signature_is_rejected(): void
    {
        $user = $this->makeUser();

        $payload = ApiToken::TYPE_USER.'|'.$user->id.'|'.$user->email.'|'.time();
        $forged = base64_encode($payload.'|'.str_repeat('a', 64));

        $this->getJson('/api/v1/user', ['Authorization' => 'Bearer '.$forged])
            ->assertStatus(401);
    }

    public function test_a_genuinely_issued_token_authenticates(): void
    {
        $user = $this->makeUser();

        $this->getJson('/api/v1/user', $this->tokenFor($user))
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_a_token_for_another_audience_is_rejected(): void
    {
        $user = $this->makeUser();

        // A rider token must not open a customer endpoint even when the id and
        // email happen to line up.
        $agentToken = ApiToken::issue(ApiToken::TYPE_AGENT, $user->id, $user->email);

        $this->getJson('/api/v1/user', ['Authorization' => 'Bearer '.$agentToken])
            ->assertStatus(401);
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $user = $this->makeUser();

        $issuedAt = now()->subDays(ApiToken::lifetimeDays() + 1)->timestamp;
        $payload = ApiToken::TYPE_USER.'|'.$user->id.'|'.$user->email.'|'.$issuedAt;
        $signature = hash_hmac('sha256', $payload, config('app.key'));

        $this->getJson('/api/v1/user', ['Authorization' => 'Bearer '.base64_encode($payload.'|'.$signature)])
            ->assertStatus(401);
    }

    public function test_a_token_stops_working_if_the_account_email_changes(): void
    {
        $user = $this->makeUser(['email' => 'before@example.com']);
        $headers = $this->tokenFor($user);

        $user->update(['email' => 'after@example.com']);

        $this->getJson('/api/v1/user', $headers)->assertStatus(401);
    }

    public function test_no_token_is_rejected(): void
    {
        $this->getJson('/api/v1/user')->assertStatus(401);
    }

    public function test_parse_round_trips_its_claims(): void
    {
        $token = ApiToken::issue(ApiToken::TYPE_USER, 42, 'someone@example.com');
        $claims = ApiToken::parse($token);

        $this->assertSame(ApiToken::TYPE_USER, $claims['type']);
        $this->assertSame('42', (string) $claims['id']);
        $this->assertSame('someone@example.com', $claims['email']);
    }

    public function test_parse_rejects_malformed_input(): void
    {
        foreach ([null, '', 'not-base64!!', base64_encode('a|b'), base64_encode('a|b|c')] as $bad) {
            $this->assertNull(ApiToken::parse($bad), 'Expected null for: '.var_export($bad, true));
        }
    }
}
