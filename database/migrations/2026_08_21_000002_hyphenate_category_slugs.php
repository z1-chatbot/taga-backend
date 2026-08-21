<?php

use App\Support\Slug;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Repairs slugs where a joining character was deleted instead of separated.
 *
 * `Str::slug()` drops characters it does not recognise, so category names built
 * around a slash arrived as one word: coughcoldflu, endocrinediabetes,
 * respiratory-asthmacopd. App\Support\Slug now treats those as separators, and
 * this brings the rows already in the table into line.
 *
 * Two guards, because a slug is a public URL:
 *
 *   1. Only rows whose slug is *exactly* what the old algorithm produced for
 *      their own name are rewritten. A slug that was hand-edited, or qualified
 *      with its parent to break a clash (chronic-care-medications-mental-health),
 *      is left exactly as it is — recomputing those would either collide or
 *      silently discard a deliberate choice.
 *   2. The new slug is skipped if anything else already holds it.
 *
 * Old links keep working regardless: Category::findBySlugOrId() falls back to a
 * separator-insensitive match, so /products?category=coughcoldflu still
 * resolves, and the storefront rewrites the address bar to the current slug.
 *
 * Three tables reference a category *by slug* rather than by id, and are
 * carried along so nothing silently stops matching:
 * `pricing_configurations.category`, and the `applicable_ids` list on `coupons`
 * and `sale_events` when they are scoped to specific categories. Both halves
 * are idempotent, so re-running this is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->apply(reverse: false);
    }

    public function down(): void
    {
        $this->apply(reverse: true);
    }

    /**
     * @param  bool  $reverse  Go back to the pre-separator form.
     */
    private function apply(bool $reverse): void
    {
        $renames = [];

        foreach (DB::table('categories')->select('id', 'name', 'slug')->get() as $category) {
            $legacy = Str::slug($category->name);
            $improved = Slug::make($category->name);

            if ($legacy === $improved) {
                continue;
            }

            [$from, $to] = $reverse ? [$improved, $legacy] : [$legacy, $improved];

            // Only this migration's own rows: the slug is either still the form
            // we are moving away from, or already the one we are moving to.
            if (! in_array($category->slug, [$legacy, $improved], true)) {
                continue;
            }

            $renames[$from] = $to;

            if ($category->slug !== $from) {
                continue;
            }

            $taken = DB::table('categories')
                ->where('slug', $to)
                ->where('id', '!=', $category->id)
                ->exists();

            if ($taken) {
                unset($renames[$from]);

                continue;
            }

            DB::table('categories')->where('id', $category->id)->update(['slug' => $to]);
        }

        if ($renames !== []) {
            $this->rewriteReferences($renames);
        }
    }

    /**
     * Follow the rename into everything that points at a category by slug.
     *
     * @param  array<string, string>  $renames  old slug => new slug
     */
    private function rewriteReferences(array $renames): void
    {
        foreach ($renames as $from => $to) {
            DB::table('pricing_configurations')->where('category', $from)->update(['category' => $to]);
        }

        foreach (['coupons', 'sale_events'] as $table) {
            $rows = DB::table($table)
                ->where('applicable_to', 'specific_categories')
                ->select('id', 'applicable_ids')
                ->get();

            foreach ($rows as $row) {
                $ids = json_decode((string) $row->applicable_ids, true);

                if (! is_array($ids)) {
                    continue;
                }

                // Product ids share this column, so only exact slug matches move.
                $mapped = array_map(
                    fn ($id) => is_string($id) && isset($renames[$id]) ? $renames[$id] : $id,
                    $ids
                );

                if ($mapped !== $ids) {
                    DB::table($table)->where('id', $row->id)->update(['applicable_ids' => json_encode($mapped)]);
                }
            }
        }
    }
};
