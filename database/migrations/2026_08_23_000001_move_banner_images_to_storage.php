<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Bring banners in line with every other user-visible upload.
 *
 * Two problems are fixed here, both of which only ever mattered once banners
 * actually rendered on the storefront — which, until now, they never did.
 *
 *  1. Images were written to `public/banners/` with an absolute URL baked into
 *     `image_url`. That directory is outside `storage/` and outside git, so a
 *     clean redeploy of the public tree would delete every banner image and
 *     leave live rows pointing at 404s. The baked-in hostname also froze at
 *     upload time, so moving domains orphaned everything. Files move to the
 *     `public` disk and the column becomes a disk-relative path.
 *
 *  2. `bg_color` held raw Tailwind gradient class pairs. Tailwind only emits
 *     classes it can see as literal strings in source, so a value arriving from
 *     the database would have produced no background at all on the storefront.
 *     Values become theme slugs resolved server-side.
 *
 * Both passes are idempotent and skip anything they cannot account for, so a
 * re-run is harmless and a missing file is left alone rather than nulled out.
 */
return new class extends Migration
{
    /**
     * Old class-pair value => new theme slug.
     *
     * The old dropdown had been half-migrated to the Taga palette already: its
     * "Blue", "Purple", "Pink" and "Indigo" entries had all been rewritten to
     * end in `to-ink-2` while keeping their original labels, so three of them
     * rendered as the same near-black. The mapping below goes by what each
     * option was *called*, since that is what whoever picked it intended.
     */
    private const THEME_MAP = [
        'from-ink to-ink-2' => 'ink',
        'from-blue-600 to-blue-800' => 'ink',
        'from-purple-600 to-ink-2' => 'plum',
        'from-purple-600 to-purple-800' => 'plum',
        'from-green-600 to-green-800' => 'moss',
        'from-red-600 to-red-800' => 'rust',
        'from-orange-600 to-orange-800' => 'ochre',
        'from-pink-600 to-ink-2' => 'plum',
        'from-pink-600 to-pink-800' => 'plum',
        'from-indigo-600 to-ink-2' => 'slate',
        'from-indigo-600 to-indigo-800' => 'slate',
        'from-gray-600 to-gray-800' => 'slate',
    ];

    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->string('bg_color')->default('ink')->change();
        });

        $this->moveImages();
        $this->normaliseThemes();
    }

    /**
     * Copy each legacy file onto the public disk and repoint the row.
     *
     * Copy rather than move: if anything below fails the original is still
     * sitting where the old code left it, and the row is only rewritten after
     * the new file is confirmed on disk.
     */
    private function moveImages(): void
    {
        $rows = DB::table('banners')->select('id', 'image_url')->get();

        foreach ($rows as $row) {
            $value = $row->image_url;

            // Already a disk-relative path — this migration has run before.
            if (! $value || ! filter_var($value, FILTER_VALIDATE_URL)) {
                continue;
            }

            $filename = basename(parse_url($value, PHP_URL_PATH));
            if ($filename === '') {
                continue;
            }

            $source = public_path('banners/' . $filename);
            $target = 'banners/' . $filename;

            // A row pointing at a file that is no longer there, or at an image
            // hosted somewhere else entirely. Leave the URL as it is — the
            // model returns absolute values untouched, so whatever still works
            // keeps working.
            if (! is_file($source)) {
                continue;
            }

            if (! Storage::disk('public')->exists($target)) {
                Storage::disk('public')->put($target, file_get_contents($source));
            }

            if (Storage::disk('public')->exists($target)) {
                DB::table('banners')->where('id', $row->id)->update(['image_url' => $target]);
                @unlink($source);
            }
        }

        // Only tidy the directory away if the move emptied it completely.
        $legacyDir = public_path('banners');
        if (is_dir($legacyDir) && ! (new FilesystemIterator($legacyDir))->valid()) {
            @rmdir($legacyDir);
        }
    }

    private function normaliseThemes(): void
    {
        foreach (self::THEME_MAP as $legacy => $slug) {
            DB::table('banners')->where('bg_color', $legacy)->update(['bg_color' => $slug]);
        }

        // Anything unrecognised — a hand-edited row, an option removed before
        // this ran — falls back to the default rather than rendering wrong.
        DB::table('banners')
            ->whereNotIn('bg_color', array_keys(\App\Models\Banner::THEMES))
            ->update(['bg_color' => 'ink']);
    }

    /**
     * Deliberately one-way for the data.
     *
     * Rolling back would mean re-baking an absolute URL from whatever the
     * hostname happens to be at rollback time, which is the exact fragility
     * this migration exists to remove. The column default is restored so the
     * schema matches, but rows are left in the good state.
     */
    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->string('bg_color')->default('from-blue-600 to-blue-800')->change();
        });
    }
};
