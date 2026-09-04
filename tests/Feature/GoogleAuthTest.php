<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ApiToken;
use App\Support\GoogleIdToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * POST /api/v1/auth/google.
 *
 * The endpoint used to accept `google_id` and `email` as ordinary request
 * fields and trust them, so the first test here is the one that matters: a
 * request that simply names an email must not produce a session. The rest walk
 * the ways a token can be genuine-looking but wrong.
 *
 * Google's signing keys are stood in for by tests/Fixtures/google-signing-key.pem
 * -- a throwaway RSA key generated for this suite alone, used nowhere else and
 * matching no real Google key -- seeded into the JWKS cache so no test reaches
 * the network. Regenerate with:
 *
 *   openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 \
 *     -out tests/Fixtures/google-signing-key.pem
 */
class GoogleAuthTest extends TestCase
{
    private const CLIENT_ID = '629124609146-test.apps.googleusercontent.com';

    private const KID = 'test-key';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_id' => self::CLIENT_ID]);

        $details = openssl_pkey_get_details($this->privateKey());

        Cache::forever(GoogleIdToken::CACHE_KEY, [[
            'kid' => self::KID,
            'kty' => 'RSA',
            'alg' => 'RS256',
            'use' => 'sig',
            'n' => $this->base64Url($details['rsa']['n']),
            'e' => $this->base64Url($details['rsa']['e']),
        ]]);
    }

    // ---------------------------------------------------------------------
    // The hole this endpoint used to have
    // ---------------------------------------------------------------------

    public function test_claims_without_a_token_are_rejected(): void
    {
        $victim = $this->makeUser(['email' => 'victim@example.com', 'is_active' => true]);

        // Exactly the request the old endpoint accepted.
        $response = $this->postJson('/api/v1/auth/google', [
            'google_id' => '1234567890',
            'email' => $victim->email,
            'name' => 'Not The Victim',
            'email_verified' => true,
        ]);

        $response->assertStatus(422);
        $this->assertNull($response->json('data.token'));
    }

    public function test_a_tampered_token_is_rejected(): void
    {
        // A genuine, correctly signed token whose payload is then swapped for
        // one naming a different account -- what an attacker without the signing
        // key can actually produce.
        [$header, , $signature] = explode('.', $this->idToken());

        $forgedPayload = $this->base64Url(json_encode(
            array_merge($this->baseClaims(), ['email' => 'admin@taga.ng'])
        ));

        $response = $this->postJson('/api/v1/auth/google', [
            'credential' => "{$header}.{$forgedPayload}.{$signature}",
        ]);

        $response->assertStatus(401);
        $this->assertDatabaseMissing('users', ['email' => 'admin@taga.ng']);
    }

    public function test_a_token_minted_for_another_application_is_rejected(): void
    {
        // Correctly signed, genuinely issued -- to somebody else's OAuth client.
        $response = $this->postJson('/api/v1/auth/google', [
            'credential' => $this->idToken(['aud' => 'someone-elses-app.apps.googleusercontent.com']),
        ]);

        $response->assertStatus(401);
    }

    public function test_an_unsigned_token_is_rejected(): void
    {
        $header = $this->base64Url(json_encode(['alg' => 'none', 'kid' => self::KID]));
        $payload = $this->base64Url(json_encode($this->baseClaims()));

        $response = $this->postJson('/api/v1/auth/google', [
            'credential' => "{$header}.{$payload}.",
        ]);

        $response->assertStatus(401);
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/auth/google', [
            'credential' => $this->idToken([
                'iat' => time() - 7200,
                'exp' => time() - 3600,
            ]),
        ]);

        $response->assertStatus(401);
    }

    public function test_a_token_from_the_wrong_issuer_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/auth/google', [
            'credential' => $this->idToken(['iss' => 'https://accounts.evil.example']),
        ]);

        $response->assertStatus(401);
    }

    public function test_sign_in_is_refused_when_no_client_id_is_configured(): void
    {
        config(['services.google.client_id' => null]);

        $response = $this->postJson('/api/v1/auth/google', [
            'credential' => $this->idToken(),
        ]);

        $response->assertStatus(401);
    }

    // ---------------------------------------------------------------------
    // Signing up
    // ---------------------------------------------------------------------

    public function test_a_valid_token_creates_a_verified_active_customer(): void
    {
        $email = 'new-'.Str::random(8).'@example.com';

        $response = $this->postJson('/api/v1/auth/google', [
            'credential' => $this->idToken([
                'sub' => '110000000000000000001',
                'email' => $email,
                'name' => 'Ada Okafor',
                'picture' => 'https://lh3.googleusercontent.com/a/ada',
            ]),
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', $email)
            ->assertJsonPath('data.user.role', 'customer')
            ->assertJsonPath('data.user.email_verified', true);

        $user = User::where('email', $email)->firstOrFail();

        $this->assertSame('110000000000000000001', $user->google_id);
        $this->assertSame('Ada Okafor', $user->name);
        $this->assertSame('https://lh3.googleusercontent.com/a/ada', $user->avatar);
        $this->assertTrue($user->is_active);
        // Nothing is left to verify -- Google already confirmed the mailbox, so
        // unlike a password signup this account can be used immediately.
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_the_issued_token_is_a_usable_customer_token(): void
    {
        $email = 'usable-'.Str::random(8).'@example.com';

        $token = $this->postJson('/api/v1/auth/google', [
            'credential' => $this->idToken(['email' => $email]),
        ])->json('data.token');

        $claims = ApiToken::parseOfType($token, ApiToken::TYPE_USER);

        $this->assertNotNull($claims);
        $this->assertSame($email, $claims['email']);
    }

    public function test_a_google_account_with_an_unverified_email_is_refused(): void
    {
        $response = $this->postJson('/api/v1/auth/google', [
            'credential' => $this->idToken([
                'email' => 'unverified@example.com',
                'email_verified' => false,
            ]),
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('users', ['email' => 'unverified@example.com']);
    }

    // ---------------------------------------------------------------------
    // Signing in again, and linking
    // ---------------------------------------------------------------------

    public function test_signing_in_twice_does_not_create_a_second_account(): void
    {
        $email = 'repeat-'.Str::random(8).'@example.com';
        $credential = $this->idToken(['sub' => '110000000000000000002', 'email' => $email]);

        $this->postJson('/api/v1/auth/google', ['credential' => $credential])->assertOk();
        $this->postJson('/api/v1/auth/google', ['credential' => $credential])->assertOk();

        $this->assertSame(1, User::where('email', $email)->count());
    }

    public function test_a_returning_user_is_matched_on_google_id_not_email(): void
    {
        $user = $this->makeUser([
            'email' => 'old-address@example.com',
            'google_id' => '110000000000000000003',
            'is_active' => true,
        ]);

        // Same Google account, new address on it.
        $this->postJson('/api/v1/auth/google', [
            'credential' => $this->idToken([
                'sub' => '110000000000000000003',
                'email' => 'new-address@example.com',
            ]),
        ])->assertOk();

        $this->assertSame(1, User::where('google_id', '110000000000000000003')->count());
        $this->assertDatabaseMissing('users', ['email' => 'new-address@example.com']);
        $this->assertSame($user->id, User::where('google_id', '110000000000000000003')->first()->id);
    }

    public function test_google_links_to_an_existing_password_account_with_the_same_email(): void
    {
        $user = $this->makeUser([
            'email' => 'existing-'.Str::random(6).'@example.com',
            'password' => Hash::make('a-real-password'),
            'google_id' => null,
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/auth/google', [
            'credential' => $this->idToken([
                'sub' => '110000000000000000004',
                'email' => $user->email,
            ]),
        ])->assertOk();

        $user->refresh();

        $this->assertSame('110000000000000000004', $user->google_id);
        $this->assertSame(1, User::where('email', $user->email)->count());
        // Linking must not disturb the existing password.
        $this->assertTrue(Hash::check('a-real-password', $user->password));
    }

    public function test_linking_verifies_an_account_that_never_clicked_its_verification_link(): void
    {
        // The state register() leaves behind: real account, unproven mailbox,
        // inactive. Google proving the mailbox is the same evidence the link
        // would have provided, so it finishes the job rather than dead-ending.
        $user = $this->makeUser([
            'email' => 'pending-'.Str::random(6).'@example.com',
            'email_verified_at' => null,
            'is_active' => false,
        ]);

        $this->postJson('/api/v1/auth/google', [
            'credential' => $this->idToken(['email' => $user->email]),
        ])->assertOk();

        $user->refresh();

        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->is_active);
    }

    public function test_a_deactivated_account_cannot_sign_in_with_google(): void
    {
        // Verified email but switched off: deactivated on purpose, not pending.
        // Google was a way around the gate that password login enforces.
        $user = $this->makeUser([
            'email' => 'banned-'.Str::random(6).'@example.com',
            'email_verified_at' => now(),
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/v1/auth/google', [
            'credential' => $this->idToken(['email' => $user->email]),
        ]);

        $response->assertStatus(403);
        $this->assertNull($response->json('data.token'));
        $this->assertFalse($user->fresh()->is_active);
    }

    // ---------------------------------------------------------------------
    // Customers only
    // ---------------------------------------------------------------------

    /**
     * Google is a shopper convenience. A pharmacy account dispenses medicine and
     * draws money down, so who holds it is not a decision to hand to whoever
     * controls a Google mailbox — those sign in to the dashboard with a password.
     */
    public function test_a_pharmacy_account_cannot_sign_in_with_google(): void
    {
        $user = $this->makeUser([
            'email' => 'owner-'.Str::random(6).'@example.com',
            'role' => 'store_owner',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/google', [
            'credential' => $this->idToken(['email' => $user->email]),
        ]);

        $response->assertStatus(403);
        $this->assertNull($response->json('data.token'));
        $this->assertSame('store_owner', $user->fresh()->role);
    }

    /** Same rule, and the same reasoning, for the accounts that approve them. */
    public function test_staff_and_admin_accounts_cannot_sign_in_with_google(): void
    {
        foreach (['admin', 'staff'] as $role) {
            $user = $this->makeUser([
                'email' => $role.'-'.Str::random(6).'@example.com',
                'role' => $role,
                'is_active' => true,
            ]);

            $this->postJson('/api/v1/auth/google', [
                'credential' => $this->idToken(['email' => $user->email]),
            ])->assertStatus(403);
        }
    }

    /**
     * The refusal must not write anything.
     *
     * resolveUser() stamps google_id onto an account it matches by email, and
     * can verify the mailbox and reactivate the account on the way past. Run the
     * role check after that and a dashboard account is quietly altered by a
     * request that was never allowed to succeed.
     */
    public function test_a_refused_sign_in_does_not_touch_the_account(): void
    {
        $user = $this->makeUser([
            'email' => 'owner-'.Str::random(6).'@example.com',
            'role' => 'store_owner',
            'is_active' => true,
        ]);

        $before = $user->fresh();

        $this->postJson('/api/v1/auth/google', [
            'credential' => $this->idToken(['email' => $user->email]),
        ])->assertStatus(403);

        $after = $user->fresh();

        $this->assertNull($after->google_id, 'a refused sign-in must not link a Google account');
        $this->assertSame($before->email_verified_at?->toString(), $after->email_verified_at?->toString());
        $this->assertSame($before->is_active, $after->is_active);
    }

    /**
     * A Google account cannot become a pharmacy in the first place - see
     * StoreOwnerOnboardingTest - so this is defence in depth rather than a live
     * path. It still matters: matching is on `sub` as well as email, and a check
     * that only looked at the address would miss an account whose Google address
     * later changed.
     */
    public function test_a_pharmacy_role_on_a_google_linked_account_is_refused(): void
    {
        $email = 'grower-'.Str::random(6).'@example.com';

        $this->postJson('/api/v1/auth/google', [
            'credential' => $this->idToken(['email' => $email]),
        ])->assertOk();

        $user = User::where('email', $email)->firstOrFail();
        $this->assertNotNull($user->google_id, 'the fixture should have linked a Google id');

        // Forced directly, because the application path refuses this account.
        $user->update(['role' => 'store_owner']);

        $this->postJson('/api/v1/auth/google', [
            'credential' => $this->idToken(['email' => $email]),
        ])->assertStatus(403);
    }

    /**
     * A pharmacy always has a password: a Google account is refused at the
     * pharmacy application, so no owner ever arrived here without one. The
     * message can simply point at the dashboard.
     */
    public function test_the_refusal_tells_a_pharmacy_where_to_sign_in(): void
    {
        $user = $this->makeUser([
            'email' => 'owner-'.Str::random(6).'@example.com',
            'role' => 'store_owner',
            'is_active' => true,
        ]);

        $message = $this->postJson('/api/v1/auth/google', [
            'credential' => $this->idToken(['email' => $user->email]),
        ])->assertStatus(403)->json('message');

        $this->assertStringContainsString('dashboard', $message);
        $this->assertStringContainsString('password', $message);
    }

    /** The rule is a restriction, not a redefinition: customers still get in. */
    public function test_a_customer_is_unaffected(): void
    {
        $user = $this->makeUser([
            'email' => 'shopper-'.Str::random(6).'@example.com',
            'role' => 'customer',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/auth/google', [
            'credential' => $this->idToken(['email' => $user->email]),
        ])->assertOk();

        $this->assertSame('customer', $user->fresh()->role);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function privateKey(): \OpenSSLAsymmetricKey
    {
        return openssl_pkey_get_private(
            file_get_contents(__DIR__.'/../Fixtures/google-signing-key.pem')
        );
    }

    /** @return array<string, mixed> */
    private function baseClaims(): array
    {
        return [
            'iss' => 'https://accounts.google.com',
            'aud' => self::CLIENT_ID,
            'sub' => '110000000000000000000',
            'email' => 'user@example.com',
            'email_verified' => true,
            'name' => 'Test User',
            'picture' => 'https://lh3.googleusercontent.com/a/test',
            'iat' => time(),
            'exp' => time() + 3600,
        ];
    }

    /** A correctly signed ID token, with any claim overridden. */
    private function idToken(array $claims = []): string
    {
        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'kid' => self::KID, 'typ' => 'JWT']));
        $payload = $this->base64Url(json_encode(array_merge($this->baseClaims(), $claims)));

        openssl_sign("{$header}.{$payload}", $signature, $this->privateKey(), OPENSSL_ALGO_SHA256);

        return "{$header}.{$payload}.".$this->base64Url($signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    // ---------------------------------------------------------------------
    // A Google account has no password
    // ---------------------------------------------------------------------

    /**
     * The provider is recorded outright, not inferred from an empty password.
     * An absence standing in for a fact holds only until something else can
     * produce a passwordless account.
     */
    public function test_a_google_signup_records_its_provider(): void
    {
        $email = 'nopass-'.Str::random(6).'@example.com';

        $this->postJson('/api/v1/auth/google', [
            'credential' => $this->idToken(['email' => $email]),
        ])->assertOk();

        $user = User::where('email', $email)->firstOrFail();

        $this->assertSame(User::AUTH_GOOGLE, $user->auth_provider);
        $this->assertTrue($user->signsInWithGoogle());
        $this->assertNull($user->getAttributes()['password']);
    }

    /** What the Profile page reads to hide its password form. */
    public function test_the_provider_is_reported_and_the_hash_never_is(): void
    {
        $email = 'nopass-'.Str::random(6).'@example.com';

        $body = $this->postJson('/api/v1/auth/google', [
            'credential' => $this->idToken(['email' => $email]),
        ])->assertOk()->json('data.user');

        $token = $this->postJson('/api/v1/auth/google', [
            'credential' => $this->idToken(['email' => $email]),
        ])->json('data.token');

        $profile = $this->getJson('/api/v1/user', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->json('data');

        $this->assertSame(User::AUTH_GOOGLE, $profile['auth_provider']);
        $this->assertArrayNotHasKey('password', $profile);
        $this->assertNotEmpty($body);
    }

    /**
     * Hash::check() against a null password is an error, so this path has to be
     * taken before the comparison. The message matters too: telling somebody
     * their password is wrong sends them to reset one they never had.
     */
    public function test_password_login_on_a_google_account_says_to_use_google(): void
    {
        $email = 'nopass-'.Str::random(6).'@example.com';

        $this->postJson('/api/v1/auth/google', [
            'credential' => $this->idToken(['email' => $email]),
        ])->assertOk();

        $response = $this->postJson('/api/v1/login', [
            'email' => $email,
            'password' => 'anything-at-all',
        ]);

        $response->assertStatus(403);
        $this->assertStringContainsString('Google', $response->json('message'));
        $this->assertNull($response->json('data.token'));
    }

    /** The form is hidden, so reaching the endpoint means it came from elsewhere. */
    public function test_changing_a_password_that_does_not_exist_is_refused(): void
    {
        $email = 'nopass-'.Str::random(6).'@example.com';

        $token = $this->postJson('/api/v1/auth/google', [
            'credential' => $this->idToken(['email' => $email]),
        ])->assertOk()->json('data.token');

        $this->postJson('/api/v1/change-password', [
            'current_password' => 'whatever',
            'new_password' => 'a-brand-new-one',
            'confirm_password' => 'a-brand-new-one',
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(400);
    }

    /** A password account is untouched by any of this. */
    public function test_a_password_account_defaults_to_the_password_provider(): void
    {
        $user = $this->makeUser([
            'email' => 'haspass-'.Str::random(6).'@example.com',
            'role' => 'customer',
            'is_active' => true,
        ]);

        $this->assertSame(User::AUTH_PASSWORD, $user->fresh()->auth_provider);
        $this->assertFalse($user->fresh()->signsInWithGoogle());
    }

    /**
     * Linking Google to an account that already has a password must not turn it
     * into a Google account. It signs in either way, keeps its password, and
     * keeps its password form.
     */
    public function test_linking_google_does_not_convert_a_password_account(): void
    {
        $user = $this->makeUser([
            'email' => 'haspass-'.Str::random(6).'@example.com',
            'role' => 'customer',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/auth/google', [
            'credential' => $this->idToken(['email' => $user->email]),
        ])->assertOk();

        $fresh = $user->fresh();

        $this->assertSame(User::AUTH_PASSWORD, $fresh->auth_provider);
        $this->assertFalse($fresh->signsInWithGoogle());
        $this->assertNotNull($fresh->getAttributes()['password']);
    }
}
