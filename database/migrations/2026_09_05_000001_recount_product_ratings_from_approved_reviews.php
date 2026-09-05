<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rebuild every product's stored rating from its approved reviews.
 *
 * `products.average_rating` and `products.rating_count` were being written by
 * Product::updateRating() and then never read: an accessor of the same name
 * shadowed the column and recomputed the average across *every* review,
 * approved or not. So the stored values are whatever happened to be left there,
 * and in most cases have never been correct.
 *
 * Now that the accessor is gone and those columns are what the storefront
 * actually shows, they have to start from a true count. Cheap either way: this
 * is one grouped query plus one write per product with reviews, and a platform
 * of this size has few enough of both.
 */
return new class extends Migration
{
    public function up(): void
    {
        $totals = DB::table('reviews')
            ->select('product_id', DB::raw('COUNT(*) as approved_count'), DB::raw('AVG(rating) as mean'))
            ->where('is_approved', true)
            ->whereNotNull('product_id')
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        // Every product, not only those with reviews: one carrying a stale
        // count from before this fix has to be reset to zero, and it will not
        // appear in the query above.
        Product::query()->select('id')->chunkById(200, function ($products) use ($totals) {
            foreach ($products as $product) {
                $row = $totals->get($product->id);

                DB::table('products')->where('id', $product->id)->update([
                    'rating_count' => (int) ($row->approved_count ?? 0),
                    'average_rating' => $row ? round((float) $row->mean, 2) : 0,
                ]);
            }
        });
    }

    public function down(): void
    {
        // Nothing to undo. These columns are derived: the previous values were
        // not a state worth restoring, they were the residue of a bug.
    }
};
