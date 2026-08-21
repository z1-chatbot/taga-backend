<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * URL slugs for display names.
 *
 * `Str::slug()` *deletes* characters it does not recognise rather than treating
 * them as word separators, which is fine for a stray apostrophe and wrong for
 * anything that joins two words:
 *
 *     Str::slug('Cough/Cold/Flu')      // coughcoldflu
 *     Str::slug('Endocrine/Diabetes')  // endocrinediabetes
 *
 * Those read as one mangled word in a URL. Joining characters are turned into
 * spaces first, so `Str::slug()` then renders them as the hyphens they mean.
 *
 * Characters already surrounded by spaces ("Mother & Child Care") were never
 * affected — the spaces on either side were doing the separating.
 */
final class Slug
{
    /**
     * Characters that join two words and must become a separator, not vanish.
     *
     * Deliberately short: anything not listed here keeps `Str::slug()`'s own
     * behaviour, so this cannot quietly reshape slugs it was never about.
     */
    private const JOINERS = '#[/\\\\|&+]+#u';

    public static function make(string $name): string
    {
        return Str::slug(preg_replace(self::JOINERS, ' ', $name));
    }

    /**
     * The comparison form used to match a slug regardless of its separators.
     *
     * This is what lets a link built before the separator rule changed —
     * /products?category=coughcoldflu — still resolve to cough-cold-flu.
     */
    public static function compact(string $slug): string
    {
        return str_replace('-', '', $slug);
    }
}
