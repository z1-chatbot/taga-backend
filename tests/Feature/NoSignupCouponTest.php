<?php

namespace Tests\Feature;

use App\Mail\WelcomeEmail;
use App\Models\Coupon;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Tests\TestCase;

/**
 * Signing up earns no discount, and no email may say otherwise.
 *
 * WelcomeEmail ended its constructor with
 *
 *     $this->couponCode = $couponCode ?? 'WELCOME10';
 *
 * so every welcome email this platform has ever sent advertised a code that was
 * never created. The coupons table has no such row and `Coupon::usable()`
 * returns false for it, so a customer who tried it was refused at checkout.
 *
 * What makes it worth a standing test rather than a one-line fix is how it
 * survived being fixed. The dispatching job had already been changed to resolve
 * an unusable code to null before handing it over, and both the job and the
 * blade template carried comments saying the hardcoded code was gone. It was
 * not: the mailable put it straight back one statement later, and the guard
 * upstream was dead code. Two accurate-looking comments described an intent the
 * class silently defeated.
 */
class NoSignupCouponTest extends TestCase
{
    public function test_the_welcome_email_offers_no_code(): void
    {
        $user = $this->makeUser();

        $html = (new WelcomeEmail($user))->render();

        $this->assertStringNotContainsString('WELCOME10', $html);

        // Not just that one string — any offer of a code at all.
        $this->assertDoesNotMatchRegularExpression(
            '/welcome code|coupon|discount code|promo/i',
            strip_tags($html),
            'the welcome email must not offer a discount; sign-up earns none'
        );
    }

    public function test_the_plain_text_welcome_offers_no_code(): void
    {
        $text = view('emails.welcome-text', ['user' => $this->makeUser()])->render();

        // The text alternative is the half nobody looks at, and it carried its
        // own copy of the same block.
        $this->assertDoesNotMatchRegularExpression(
            '/welcome code|coupon|discount code|promo/i',
            $text
        );
    }

    public function test_the_welcome_email_cannot_be_given_a_code(): void
    {
        // Removed rather than defaulted to null: a nullable parameter is an
        // invitation to pass something, and this is the class that turned a
        // null into a phantom code once already.
        $parameters = (new ReflectionClass(WelcomeEmail::class))
            ->getConstructor()
            ->getParameters();

        $this->assertCount(1, $parameters);
        $this->assertSame('user', $parameters[0]->getName());
    }

    public function test_no_email_template_hardcodes_a_discount_code(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views/emails')) as $file) {
            $body = file_get_contents($file->getPathname());

            // An all-caps token that looks like a coupon, written as a literal
            // in a template rather than passed in and checked.
            if (preg_match_all('/\b(WELCOME|SAVE|PROMO|DISCOUNT)\d{1,3}\b/', $body, $matches)) {
                foreach (array_unique($matches[0]) as $code) {
                    // A comment recording the removal is not a reoccurrence.
                    if (preg_match('/\{\{--[^}]*'.preg_quote($code, '/').'|\/\/.*'.preg_quote($code, '/').'/s', $body)) {
                        continue;
                    }

                    $offenders[] = $file->getRelativePathname().': '.$code;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "these templates hardcode a discount code:\n  ".implode("\n  ", $offenders)
        );
    }

    public function test_no_mailable_falls_back_to_a_hardcoded_code(): void
    {
        $offenders = [];

        foreach (array_merge(
            File::allFiles(app_path('Mail')),
            File::allFiles(app_path('Jobs'))
        ) as $file) {
            foreach (file($file->getPathname()) as $number => $line) {
                // A docblock quoting the removed line — as WelcomeEmail's now
                // does, so the next reader knows what the fallback did — is
                // documentation, not a reoccurrence.
                $trimmed = ltrim($line);

                if (str_starts_with($trimmed, '*')
                    || str_starts_with($trimmed, '//')
                    || str_starts_with($trimmed, '#')) {
                    continue;
                }

                // The exact shape of the bug: a null-coalesce onto a string
                // literal that looks like a code.
                if (preg_match('/\?\?\s*\'[A-Z][A-Z0-9_]{3,}\'/', $line, $m)) {
                    $offenders[] = $file->getRelativePathname().':'.($number + 1).' '.$m[0];
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "these fall back to a hardcoded code:\n  ".implode("\n  ", $offenders)
        );
    }

    public function test_welcome10_does_not_exist_as_a_coupon(): void
    {
        // The other half of the contract. If somebody later decides a sign-up
        // discount is wanted, creating the coupon is the change — not
        // reinstating a string that promises one.
        $this->assertFalse(Coupon::usable('WELCOME10'));
    }
}
