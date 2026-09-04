<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Verifies a Google Sign-In ID token.
 *
 * ---------------------------------------------------------------------------
 * Why this exists
 * ---------------------------------------------------------------------------
 * `POST /auth/google` used to take `google_id`, `email` and `name` as ordinary
 * request fields and trust them. Nothing tied those values to Google. A single
 * unauthenticated request --
 *
 *     curl -d 'google_id=1&email=admin@taga.ng&name=x' .../api/v1/auth/google
 *
 * -- returned a valid API token for whatever account that email named, or
 * created one if it did not exist. That is the same failure App\Support\ApiToken
 * was written to close, one layer up: a credential anyone can type.
 *
 * The browser is now the only thing that handles profile claims for display.
 * What it sends the API is the raw ID token Google issued, and every claim the
 * API acts on is read out of *this* verifier, never out of the request body.
 *
 * ---------------------------------------------------------------------------
 * What "verified" means here
 * ---------------------------------------------------------------------------
 * All of the following must hold, or verify() returns null:
 *
 *   1. Three-part JWT whose header says `alg: RS256`. The algorithm is pinned,
 *      so a token claiming `none` -- or `HS256` keyed on the public key, the
 *      classic confusion attack -- is rejected before any crypto runs.
 *   2. The header's `kid` matches a key currently published at Google's JWKS
 *      endpoint, and the RSA signature over `header.payload` checks out.
 *   3. `iss` is one of Google's two spellings of its issuer.
 *   4. `aud` is exactly our client id. Without this, an attacker could present a
 *      genuine, correctly-signed Google token minted for *their own* app and be
 *      let in as its subject.
 *   5. `exp` is in the future and `iat` is not in the future (60s of leeway for
 *      clock skew).
 *   6. `sub` and `email` are present.
 *
 * `email_verified` is deliberately *not* checked here -- it is a policy call
 * about what an account may do, not a question about the token's authenticity --
 * and is enforced by the caller.
 */
class GoogleIdToken
{
    /** Google's public signing keys, in JWK Set form. */
    private const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    /** Google issues tokens under both spellings; both are legitimate. */
    private const ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    public const CACHE_KEY = 'google.oauth.jwks';

    /** Google rotates signing keys every few days; an hour is well inside that. */
    private const CACHE_SECONDS = 3600;

    /** Tolerance for clock drift between this server and Google, in seconds. */
    private const LEEWAY = 60;

    /**
     * Returns the token's claims, or null if it is not a token this application
     * should accept.
     *
     * Null covers every failure -- malformed, bad signature, wrong audience,
     * expired -- so no caller can mistake "forged" for a recoverable error.
     */
    public static function verify(?string $jwt): ?array
    {
        $clientId = config('services.google.client_id');

        if (! $clientId) {
            // Loud, because it means Google sign-in is dead in this environment
            // and the symptom at the button is an unhelpful generic failure.
            Log::error('GOOGLE_CLIENT_ID is not configured; refusing all Google sign-ins.');

            return null;
        }

        if (! $jwt) {
            return null;
        }

        $segments = explode('.', $jwt);

        if (count($segments) !== 3) {
            return null;
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $segments;

        $header = self::decodeJson($encodedHeader);
        $claims = self::decodeJson($encodedPayload);
        $signature = self::base64UrlDecode($encodedSignature);

        if ($header === null || $claims === null || $signature === null) {
            return null;
        }

        // Pin the algorithm before choosing a key. Reading `alg` from the token
        // and then honouring it is how "alg: none" gets accepted.
        if (($header['alg'] ?? null) !== 'RS256' || ! is_string($header['kid'] ?? null)) {
            return null;
        }

        if (! self::signatureIsValid($header['kid'], $encodedHeader.'.'.$encodedPayload, $signature)) {
            return null;
        }

        return self::claimsAreValid($claims, $clientId) ? $claims : null;
    }

    /**
     * True if the signature was made by the Google key named by $kid.
     *
     * An unknown kid is retried once against a freshly fetched key set: Google
     * rotates keys, and the alternative is every login failing for up to an hour
     * after a rotation because we cached the previous set.
     */
    private static function signatureIsValid(string $kid, string $signedPart, string $signature): bool
    {
        $key = self::findKey($kid, false) ?? self::findKey($kid, true);

        if ($key === null) {
            return false;
        }

        $pem = self::jwkToPem($key['n'] ?? '', $key['e'] ?? '');

        if ($pem === null) {
            return false;
        }

        return openssl_verify($signedPart, $signature, $pem, OPENSSL_ALGO_SHA256) === 1;
    }

    private static function findKey(string $kid, bool $forceRefresh): ?array
    {
        if ($forceRefresh) {
            Cache::forget(self::CACHE_KEY);
        }

        foreach (self::keys() as $key) {
            if (($key['kid'] ?? null) === $kid && ($key['kty'] ?? null) === 'RSA') {
                return $key;
            }
        }

        return null;
    }

    /** @return array<int, array<string, string>> */
    private static function keys(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, function () {
                $response = Http::timeout(5)->get(self::JWKS_URL);

                if (! $response->successful()) {
                    // Thrown rather than returned: Cache::remember stores
                    // whatever the callback returns, so returning [] here would
                    // pin "no keys" in the cache for an hour and keep sign-in
                    // broken long after Google recovered.
                    throw new \RuntimeException('JWKS request returned '.$response->status());
                }

                return $response->json('keys') ?? [];
            });
        } catch (\Throwable $e) {
            Log::error('Could not fetch Google signing keys: '.$e->getMessage());

            return [];
        }
    }

    /**
     * The registered-claim checks. Split out from verify() so each one is
     * visible: every line here is a documented attack that the signature check
     * alone does not stop.
     */
    private static function claimsAreValid(array $claims, string $clientId): bool
    {
        $now = time();

        if (! in_array($claims['iss'] ?? null, self::ISSUERS, true)) {
            return false;
        }

        if (! is_string($claims['aud'] ?? null) || ! hash_equals($clientId, $claims['aud'])) {
            return false;
        }

        if (! isset($claims['exp']) || $now >= ((int) $claims['exp'] + self::LEEWAY)) {
            return false;
        }

        if (isset($claims['iat']) && ((int) $claims['iat'] - self::LEEWAY) > $now) {
            return false;
        }

        if (! is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            return false;
        }

        return is_string($claims['email'] ?? null) && $claims['email'] !== '';
    }

    private static function decodeJson(string $segment): ?array
    {
        $json = self::base64UrlDecode($segment);

        if ($json === null) {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * Wraps a JWK's RSA modulus and exponent in the DER structure openssl wants.
     *
     * openssl_verify() takes a PEM public key and PHP has no JWK reader, so the
     * key has to be re-encoded by hand: an X.509 SubjectPublicKeyInfo carrying
     * the rsaEncryption OID and the RSAPublicKey SEQUENCE { modulus, exponent }.
     */
    private static function jwkToPem(string $n, string $e): ?string
    {
        $modulus = self::base64UrlDecode($n);
        $exponent = self::base64UrlDecode($e);

        if (! $modulus || ! $exponent) {
            return null;
        }

        $rsaPublicKey = self::derSequence(
            self::derInteger($modulus).self::derInteger($exponent)
        );

        // SEQUENCE { OID 1.2.840.113549.1.1.1 (rsaEncryption), NULL }
        $algorithm = self::derSequence(
            "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00"
        );

        // BIT STRING, with the leading 0x00 "unused bits" octet.
        $bitString = "\x03".self::derLength(strlen($rsaPublicKey) + 1)."\x00".$rsaPublicKey;

        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode(self::derSequence($algorithm.$bitString)), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
    }

    private static function derSequence(string $contents): string
    {
        return "\x30".self::derLength(strlen($contents)).$contents;
    }

    private static function derInteger(string $value): string
    {
        $value = ltrim($value, "\x00");

        if ($value === '') {
            $value = "\x00";
        }

        // DER integers are signed, so a leading byte above 0x7f would read as a
        // negative number. Pad it.
        if (ord($value[0]) > 0x7f) {
            $value = "\x00".$value;
        }

        return "\x02".self::derLength(strlen($value)).$value;
    }

    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = '';

        while ($length > 0) {
            $bytes = chr($length & 0xff).$bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)).$bytes;
    }
}
