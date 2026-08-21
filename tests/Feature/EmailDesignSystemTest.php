<?php

namespace Tests\Feature;

use App\Support\EmailStyle;
use Tests\TestCase;

/**
 * The emails and the storefront are the same design system.
 *
 * Email cannot share a stylesheet with the site — Outlook and several webmail
 * clients drop <style> — so App\Support\EmailStyle transcribes the tokens from
 * frontend/src/index.css by hand. A hand copy drifts silently, and the first
 * anyone hears of it is an email that looks nothing like the shop.
 *
 * These tests are what keeps the copy honest.
 */
class EmailDesignSystemTest extends TestCase
{
    /**
     * EmailStyle constant => the CSS custom property it was copied from.
     */
    private const TOKENS = [
        'PAPER' => 'paper',
        'PAPER_2' => 'paper-2',
        'PAPER_3' => 'paper-3',
        'CARD' => 'card',
        'INK' => 'ink',
        'INK_2' => 'ink-2',
        'INK_3' => 'ink-3',
        'LINE' => 'line',
        'LINE_2' => 'line-2',
        'BRAND' => 'brand',
        'SUCCESS' => 'success',
        'DANGER' => 'danger',
        'WARN' => 'warn',
    ];

    private function storefrontStylesheet(): string
    {
        $path = base_path('../frontend/src/index.css');

        if (! is_file($path)) {
            $this->markTestSkipped('the storefront stylesheet is not checked out beside the API');
        }

        return (string) file_get_contents($path);
    }

    /** Templates that have been moved onto the shared layout. */
    private function convertedTemplates(): array
    {
        return array_values(array_filter(
            glob(resource_path('views/emails/*.blade.php')),
            fn ($file) => str_contains((string) file_get_contents($file), "@extends('emails.layout')")
        ));
    }

    public function test_every_colour_matches_the_storefront_token_it_was_copied_from(): void
    {
        $css = $this->storefrontStylesheet();
        $drifted = [];

        foreach (self::TOKENS as $constant => $property) {
            // Anchored on the closing colon so --color-line does not match
            // --color-line-2, which differ by one character and two shades.
            if (! preg_match('~--color-'.preg_quote($property, '~').':\s*(#[0-9a-fA-F]{6})~', $css, $found)) {
                $drifted[] = "{$constant}: no --color-{$property} in index.css";

                continue;
            }

            $mine = strtolower(constant(EmailStyle::class."::{$constant}"));
            $theirs = strtolower($found[1]);

            if ($mine !== $theirs) {
                $drifted[] = "{$constant}: email has {$mine}, storefront has {$theirs}";
            }
        }

        $this->assertSame([], $drifted, "the email palette has drifted from the storefront:\n  ".implode("\n  ", $drifted));
    }

    /**
     * index.css points --font-mono at Archivo and says of regulated data:
     * "Same family as everything else; tabular figures are what make it line
     * up, not a monospace face." An email that reaches for Consolas is not
     * following the system, it is inventing one.
     */
    public function test_no_converted_template_uses_a_monospace_face(): void
    {
        $offenders = [];

        // Matches a monospace face being *used* — a named mono family, or the
        // generic keyword sitting in a font stack. Not the word in prose: the
        // first version of this test failed on EmailStyle's own comment saying
        // there is deliberately no monospace here.
        $used = '~(ui-monospace|SFMono|Consolas|Menlo|Courier|Liberation Mono)|font-family\s*:[^;\'"]*monospace~i';

        foreach (array_merge($this->convertedTemplates(), [app_path('Support/EmailStyle.php')]) as $file) {
            if (preg_match($used, (string) file_get_contents($file))) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders, 'these set a monospace face, which the design system does not use: '.implode(', ', $offenders));
    }

    /**
     * Outlook renders none of these. The old templates used gradients in 18
     * files, box-shadow in 15 and flexbox in 11, so their headers arrived as
     * grey slabs for a large share of readers.
     */
    public function test_no_converted_template_uses_css_outlook_ignores(): void
    {
        $banned = ['linear-gradient', 'radial-gradient', 'box-shadow', 'display:flex', 'display: flex'];
        $offenders = [];

        foreach ($this->convertedTemplates() as $file) {
            $source = (string) file_get_contents($file);

            foreach ($banned as $rule) {
                if (stripos($source, $rule) !== false) {
                    $offenders[] = basename($file).' → '.$rule;
                }
            }
        }

        $this->assertSame([], $offenders, 'Outlook ignores these, so they degrade silently: '.implode(', ', $offenders));
    }

    /**
     * The primary button is ink. index.css defines
     * `primary: 'bg-ink text-paper'` and keeps vermilion for the separate
     * `clay` variant — a vermilion call to action on a warm ground is not what
     * the product looks like.
     */
    public function test_the_call_to_action_is_ink_not_vermilion(): void
    {
        $button = (string) file_get_contents(resource_path('views/emails/partials/button.blade.php'));

        $this->assertStringContainsString('S::INK', $button, 'the button should take its background from the ink token');
        $this->assertStringNotContainsString('S::BRAND', $button, 'vermilion is the accent rule and the mark, not the default call to action');
    }

    /**
     * At least one template is on the shared layout, so the checks above are
     * actually inspecting something. Without this they all pass vacuously on
     * an empty list.
     */
    public function test_the_converted_set_is_not_empty(): void
    {
        $this->assertNotEmpty($this->convertedTemplates(), 'no template extends emails.layout, so the design checks above test nothing');
    }
}
