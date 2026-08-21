<?php

namespace Tests\Feature;

use App\Mail\ResetPasswordEmail;
use App\Mail\VerifyEmail;
use App\Support\AppUrl;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Links in email point at addresses the recipient can actually reach.
 *
 * The first delivery agent invitation went out with a sign-in button pointing
 * at http://localhost:5175, because one set of values was answering two
 * different questions: which origins may call this API, and what address to
 * print in an email. They only agree in production.
 *
 * They are separate now — config('app.public_urls.*') for anything emailed,
 * config('app.*_url') for CORS and the Paystack return — and these tests hold
 * them apart, in both directions.
 */
class EmailLinkTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Files allowed to read the development URLs, and why.
     *
     * Everything else must go through AppUrl. This list is the whole point of
     * the test: adding a file to it should require justifying the entry.
     */
    private const MAY_USE_DEV_URLS = [
        // Decides which browser origins may call the API. Must stay localhost
        // in development or every portal fails CORS.
        'CorsMiddleware.php',
        // Where Paystack returns the customer's browser after payment. A local
        // checkout must come back to the local storefront.
        'PaystackService.php',
        // An API payload rendered by whichever frontend asked for it, so it
        // follows that frontend rather than the public domain.
        'StoreApplicationController.php',
    ];

    /**
     * The source with its commentary removed.
     *
     * These scans look for a pattern that is also the natural way to *describe*
     * the bug, so a comment explaining why a template no longer uses
     * config('app.url') would fail the test that checks it does not. Strip
     * Blade comments and whole-line PHP comments; nothing else, so a "https://"
     * inside a string is left alone.
     */
    private function codeOnly(string $source): string
    {
        $source = (string) preg_replace('~\{\{--.*?--\}\}~s', '', $source);

        return (string) preg_replace('~^\s*(//|\*|/\*).*$~m', '', $source);
    }

    private function pointUrlsApart(): void
    {
        config([
            'app.frontend_url' => 'http://localhost:5173',
            'app.admin_url' => 'http://localhost:5174',
            'app.agent_portal_url' => 'http://localhost:5175',
            'app.logistics_portal_url' => 'http://localhost:5176',
            'app.public_urls.frontend' => 'https://taga.ng',
            'app.public_urls.admin' => 'https://admin.taga.ng',
            'app.public_urls.agent_portal' => 'https://agents.taga.ng',
            'app.public_urls.logistics_portal' => 'https://logistics.taga.ng',
        ]);
    }

    public function test_the_helper_reads_the_public_urls(): void
    {
        $this->pointUrlsApart();

        $this->assertSame('https://taga.ng', AppUrl::storefront());
        $this->assertSame('https://admin.taga.ng/login', AppUrl::admin('/login'));
        $this->assertSame('https://agents.taga.ng/login', AppUrl::agentPortal('login'));
        $this->assertSame('https://logistics.taga.ng/login', AppUrl::logisticsPortal('/login'));
    }

    /**
     * One slash, however the caller writes the path — '/login', 'login' or ''.
     * A doubled slash is survivable; a missing one runs the host into the path
     * and produces a link to a domain that does not exist.
     */
    public function test_the_helper_joins_paths_cleanly(): void
    {
        config(['app.public_urls.frontend' => 'https://taga.ng/']);

        $this->assertSame('https://taga.ng', AppUrl::storefront());
        $this->assertSame('https://taga.ng/sell', AppUrl::storefront('/sell'));
        $this->assertSame('https://taga.ng/sell', AppUrl::storefront('sell'));
    }

    public function test_a_verification_link_uses_the_public_storefront(): void
    {
        $this->pointUrlsApart();

        $mail = new VerifyEmail($this->makeUser(), 'tok3n');

        $this->assertStringStartsWith('https://taga.ng/verify-email', $mail->verificationUrl);
        $this->assertStringNotContainsString('localhost', $mail->verificationUrl);
    }

    public function test_a_password_reset_link_uses_the_public_storefront(): void
    {
        $this->pointUrlsApart();

        $mail = new ResetPasswordEmail($this->makeUser(), 'tok3n', 60);

        $this->assertStringStartsWith('https://taga.ng/reset-password', $mail->resetUrl);
        $this->assertStringNotContainsString('localhost', $mail->resetUrl);
    }

    /**
     * Nothing outside the three listed files reads the development URLs.
     *
     * This is the regression guard. The bug was not that one link was wrong —
     * it was that the only available value happened to be the wrong one, so
     * every new email link inherited the mistake.
     */
    public function test_only_cors_and_the_payment_callback_read_the_dev_urls(): void
    {
        $offenders = [];

        $files = array_merge(
            glob(app_path('Mail/*.php')),
            glob(app_path('Jobs/*.php')),
            glob(app_path('Notifications/*.php')),
            glob(app_path('Services/*.php')),
            glob(app_path('Http/Middleware/*.php')),
            glob(app_path('Http/Controllers/*.php')),
            glob(app_path('Http/Controllers/*/*.php')),
            glob(app_path('Http/Controllers/*/*/*.php')),
            // The templates count too. Sixteen of them built their own links
            // from config('app.frontend_url'), so every order email, cart
            // reminder and coupon carried a localhost address — the scan that
            // covered only PHP classes would never have seen it.
            glob(resource_path('views/emails/*.blade.php')),
        );

        foreach ($files as $file) {
            $name = basename($file);

            if (in_array($name, self::MAY_USE_DEV_URLS, true)) {
                continue;
            }

            // app.url is in the list because a template was building "view your
            // order" from it — that is the API's own host, which serves no
            // order page at all, so the link went nowhere for every recipient.
            if (preg_match('~config\(\s*[\'"]app\.(url|frontend_url|admin_url|agent_portal_url|logistics_portal_url)~', $this->codeOnly((string) file_get_contents($file)))) {
                $offenders[] = $name;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'these read the CORS/callback URLs directly and will email a localhost link — use App\Support\AppUrl instead: '.implode(', ', $offenders)
        );
    }

    /**
     * The other direction: the local dev origins must survive in the CORS
     * allowlist even while the public URLs name real domains. Pointing the
     * allowlist at production is what broke admin sign-in before.
     */
    public function test_the_cors_allowlist_still_holds_the_local_origins(): void
    {
        $this->pointUrlsApart();

        foreach (['http://localhost:5173', 'http://localhost:5174', 'http://localhost:5175'] as $origin) {
            $response = $this->withHeaders(['Origin' => $origin])
                ->getJson('/api/v1/settings/public');

            $this->assertSame(
                $origin,
                $response->headers->get('Access-Control-Allow-Origin'),
                "{$origin} lost its place in the CORS allowlist"
            );
        }
    }
}
