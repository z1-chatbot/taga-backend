<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * That every email the platform can send actually has something to render.
 *
 * A Mailable names its templates as strings, so a renamed or forgotten blade
 * file is invisible until the moment somebody triggers it — and the moments
 * that trigger these are the ones that matter: a pharmacy being approved, a
 * courier being dispatched, a customer being told their order shipped. The
 * failure surfaces in production, to the one person who needed the email.
 *
 * Cheap to check, so it is checked.
 */
class EmailWiringTest extends TestCase
{
    /** @return array<string, array{0: string, 1: ?string, 2: ?string}> */
    private function mailables(): array
    {
        $found = [];

        foreach (glob(app_path('Mail/*.php')) as $file) {
            $source = file_get_contents($file);
            $name = basename($file, '.php');

            preg_match("/view:\s*'([^']+)'/", $source, $view);
            preg_match("/text:\s*'([^']+)'/", $source, $text);

            $found[$name] = [$name, $view[1] ?? null, $text[1] ?? null];
        }

        return $found;
    }

    private function bladePath(string $view): string
    {
        return resource_path('views/'.str_replace('.', '/', $view).'.blade.php');
    }

    public function test_every_mailable_points_at_a_template_that_exists(): void
    {
        $missing = [];

        foreach ($this->mailables() as [$name, $view, $text]) {
            foreach (array_filter(['html' => $view, 'text' => $text]) as $kind => $template) {
                if (! file_exists($this->bladePath($template))) {
                    $missing[] = "{$name} ({$kind}): {$template}";
                }
            }
        }

        $this->assertSame([], $missing, "Mailables naming a template that does not exist:\n".implode("\n", $missing));
    }

    public function test_the_emails_this_platform_cannot_do_without_are_present(): void
    {
        /*
         * Named one at a time rather than counted, because a count passes when
         * somebody deletes the licence decision email and adds a newsletter.
         *
         * Each of these is the only notice its recipient gets of something they
         * cannot otherwise find out: a pharmacy learning it may sell, a courier
         * learning where to collect, a practitioner learning somebody is
         * waiting for them.
         */
        $required = [
            'StoreVerificationSubmittedEmail',
            'StoreVerificationDecisionEmail',
            'LicenceExpiryWarningEmail',
            'DeliveryAssignmentEmail',
            'DeliveryTrackingUpdateEmail',
            'ConsultationReceivedEmail',
            'ConsultationReplyEmail',
            'ConsultationAwaitingEmail',
            'StaffWelcomeEmail',
            'StoreOwnerWelcomeEmail',
            'LogisticsCompanyWelcomeEmail',
            'AgentInvitationEmail',
            'OrderNotificationEmail',
            'OrderStatusEmail',
            'ResetPasswordEmail',
            'VerifyEmail',
        ];

        $present = array_keys($this->mailables());

        foreach ($required as $mailable) {
            $this->assertContains($mailable, $present, "{$mailable} has gone missing.");
        }
    }

    public function test_a_licence_decision_is_always_told_to_the_pharmacy(): void
    {
        $source = file_get_contents(
            app_path('Http/Controllers/Api/StoreVerificationController.php')
        );

        // Approval is what puts a pharmacy's listings on sale and rejection is
        // what keeps them off it. Deciding in silence leaves a shop wondering
        // why it has no orders, so both paths have to notify.
        $this->assertSame(
            2,
            substr_count($source, '$this->notifyDecision('),
            'Approval and rejection must each tell the pharmacy.'
        );

        $this->assertStringContainsString('StoreVerificationDecisionEmail', $source);
    }

    public function test_a_new_consultation_alerts_the_specialty(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Api/ConsultationController.php'));

        // A pooled request has nobody personally on the hook, so without this it
        // sits unread until somebody happens to open the queue.
        $this->assertStringContainsString('$this->alertPractitioners($consultation);', $source);
    }

    public function test_the_text_twin_of_every_html_email_is_present(): void
    {
        $htmlOnly = [];

        foreach ($this->mailables() as [$name, $view, $text]) {
            // The three payout mailables predate this convention and use the
            // older build() API; they are excluded rather than silently passing
            // because their absence is a known gap, not an accident.
            if ($view && ! $text) {
                $htmlOnly[] = $name;
            }
        }

        // A plain-text alternative is what keeps an email out of the spam
        // folder and readable on a watch or a feature phone.
        $this->assertSame([], $htmlOnly, 'HTML emails with no plain-text alternative: '.implode(', ', $htmlOnly));
    }
}
