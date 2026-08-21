<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Signed bearer tokens for the API.
 *
 * ---------------------------------------------------------------------------
 * Why this exists
 * ---------------------------------------------------------------------------
 * The previous token was `base64(id|email|timestamp)` with no signature. Every
 * component was public or guessable — ids are sequential and emails are not
 * secret — so anyone could mint a valid token for any account, including an
 * administrator, without ever knowing a password. The timestamp was parsed but
 * never checked, so tokens also never expired.
 *
 * A token now carries an HMAC-SHA256 signature over its payload, keyed on the
 * application key. Forging one requires APP_KEY, and the issued-at value is
 * enforced, so a leaked token stops working.
 *
 * ---------------------------------------------------------------------------
 * Format
 * ---------------------------------------------------------------------------
 *   payload   = type|id|email|issuedAt
 *   token     = base64( payload|signature )
 *   signature = hash_hmac('sha256', payload, APP_KEY)
 *
 * `type` scopes a token to one audience, so a delivery agent's token cannot be
 * replayed against a customer endpoint that happens to share an id.
 *
 * Old three-part tokens are rejected. That signs everyone out once, which is
 * the correct outcome when a credential scheme was forgeable.
 */
class ApiToken
{
    public const TYPE_USER = 'user';

    public const TYPE_AGENT = 'agent';

    public const TYPE_COMPANY = 'company';

    /** How long a token stays valid. */
    public static function lifetimeDays(): int
    {
        return (int) config('auth.api_token_days', 30);
    }

    public static function issue(string $type, int|string $id, string $email): string
    {
        $payload = implode('|', [$type, $id, $email, time()]);

        return base64_encode($payload.'|'.self::sign($payload));
    }

    /**
     * Returns ['type','id','email','issued_at'] for a valid token, or null.
     *
     * Null covers every failure — malformed, wrong signature, expired — so a
     * caller cannot accidentally treat "bad signature" as a soft error.
     */
    public static function parse(?string $token): ?array
    {
        if (! $token) {
            return null;
        }

        $decoded = base64_decode($token, true);

        if ($decoded === false) {
            return null;
        }

        $parts = explode('|', $decoded);

        // type|id|email|issuedAt|signature
        if (count($parts) !== 5) {
            return null;
        }

        $signature = array_pop($parts);
        $payload = implode('|', $parts);

        // hash_equals rather than === so a wrong signature takes the same time
        // to reject as a right one.
        if (! hash_equals(self::sign($payload), $signature)) {
            return null;
        }

        [$type, $id, $email, $issuedAt] = $parts;

        if (! ctype_digit((string) $issuedAt)) {
            return null;
        }

        $expiresAt = Carbon::createFromTimestamp((int) $issuedAt)
            ->addDays(self::lifetimeDays());

        if ($expiresAt->isPast()) {
            return null;
        }

        return [
            'type' => $type,
            'id' => $id,
            'email' => $email,
            'issued_at' => (int) $issuedAt,
        ];
    }

    /** Parse and additionally require the token to be of the given audience. */
    public static function parseOfType(?string $token, string $type): ?array
    {
        $claims = self::parse($token);

        return ($claims && $claims['type'] === $type) ? $claims : null;
    }

    private static function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, config('app.key'));
    }
}
