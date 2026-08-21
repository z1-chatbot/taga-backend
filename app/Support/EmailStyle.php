<?php

namespace App\Support;

/**
 * The storefront's design system, transcribed for email.
 *
 * Email cannot use a stylesheet — Outlook and several webmail clients drop
 * <style> entirely — so every rule has to be inlined on the element. Left to
 * each template that meant 21 slightly different definitions of "body text",
 * which is how the old set ended up with eight brand colours between them.
 *
 * Every value here is copied from frontend/src/index.css rather than chosen.
 * When that file changes, this one has to change with it — a test compares the
 * two so the drift is caught rather than discovered in an inbox.
 *
 * Things the system is explicit about, which are easy to get wrong:
 *
 *  - Body copy is ink-2 (#4A4843), not the near-black used for headings.
 *  - Data — order numbers, delivery codes, quantities — is set in the same
 *    family as everything else. Tabular figures are what line it up, not a
 *    monospace face.
 *  - The primary button is ink, not brand. Vermilion is the accent rule and
 *    the wordmark, not the default call to action.
 *  - Labels are sentence case with normal tracking. index.css calls the old
 *    tracked-out uppercase "a large part of what made the pages feel
 *    machine-assembled".
 *
 * Templates echo these unescaped: style="{!! EmailStyle::BODY !!}". They are
 * class constants, never user input, so there is nothing to escape — and
 * escaping would turn every apostrophe in the font stack into &#039;.
 */
final class EmailStyle
{
    // ---- colour: straight from :root in index.css ---------------------------

    public const PAPER = '#FFFDF9';        // page background, a warm white
    public const PAPER_2 = '#F6F2EA';      // wells and recessed areas only
    public const PAPER_3 = '#ECE7DC';
    public const CARD = '#FFFFFF';

    public const INK = '#111110';          // headings, primary buttons
    public const INK_2 = '#4A4843';        // body copy
    public const INK_3 = '#6B6862';        // labels, metadata

    public const LINE = '#E6E1D7';         // hairline rules, the main device
    public const LINE_2 = '#CDC7B9';       // emphasised rules

    public const BRAND = '#E33F1F';

    public const SUCCESS = '#2C6A45';
    public const DANGER = '#A32A12';
    public const WARN = '#7A5A12';

    // Archivo will not load in most mail clients, so the fallback is the
    // design in practice rather than a safety net. index.css sets --font-mono
    // to Archivo too: there is deliberately no monospace anywhere.
    public const FONT = "'Archivo','Helvetica Neue',Helvetica,Arial,sans-serif";

    // ---- type: the .t-* scale ----------------------------------------------

    /** .t-title — the one heading an email gets. */
    public const TITLE = 'margin:0; font-family:'.self::FONT.'; font-size:26px; line-height:30px; letter-spacing:-0.025em; font-weight:600; color:'.self::INK.';';

    /** .t-subtitle — a section heading inside the body. */
    public const SUBTITLE = 'margin:0; font-family:'.self::FONT.'; font-size:17px; line-height:22px; letter-spacing:-0.015em; font-weight:600; color:'.self::INK.';';

    /** .t-lead — the opening line. */
    public const LEAD = 'margin:0 0 16px 0; font-family:'.self::FONT.'; font-size:17px; line-height:27px; color:'.self::INK_2.';';

    /** .t-body — ordinary paragraph. */
    public const BODY = 'margin:0 0 14px 0; font-family:'.self::FONT.'; font-size:15px; line-height:25px; color:'.self::INK_2.';';

    /** .t-small — caveats and small print. */
    public const SMALL = 'margin:0 0 12px 0; font-family:'.self::FONT.'; font-size:13px; line-height:20px; color:'.self::INK_3.';';

    /** .t-label — sentence case, normal tracking, never uppercase. */
    public const LABEL = 'font-family:'.self::FONT.'; font-size:13px; line-height:19px; letter-spacing:0; font-weight:500; color:'.self::INK_3.';';

    /** .t-data — order numbers, codes, quantities. Tabular, not monospace. */
    public const DATA = 'font-family:'.self::FONT.'; font-size:13px; line-height:20px; font-weight:400; color:'.self::INK_2.'; font-variant-numeric:tabular-nums;';

    /** .t-price — money. */
    public const PRICE = 'font-family:'.self::FONT.'; font-size:15px; line-height:22px; font-weight:600; letter-spacing:-0.02em; color:'.self::INK.'; font-variant-numeric:tabular-nums;';

    /** The delivery code and similar: t-data at a size you can read aloud. */
    public const CODE = 'font-family:'.self::FONT.'; font-size:28px; line-height:34px; font-weight:700; letter-spacing:0.08em; color:'.self::INK.'; font-variant-numeric:tabular-nums;';
}
