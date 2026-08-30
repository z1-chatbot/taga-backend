<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the weight band from courier rates.
 *
 * `per_kg_rate` and `weight_threshold` described a freight pricing model that
 * this platform never ran. Nothing read them: the customer pays a flat
 * `shipping_zones.shipping_fee` for the route, and the courier is paid
 * `base_rate` by DeliveryEarningsService. The only code that combined them sat
 * behind a service method with no callers.
 *
 * They were also unusable in principle. The threshold defaulted to 5kg, which a
 * whole order of blister packs, an inhaler and a bottle of syrup does not
 * reach, so the charge would have been zero on essentially every order. And no
 * weight was ever captured — `products.weight_kg` is nullable and the vendor
 * upload form does not ask for it.
 *
 * They were removed rather than left dormant because both were editable: a
 * logistics partner could enter a per-kg rate in their own console, see it
 * saved, and reasonably expect to be paid on it. A settlement dispute waiting
 * to happen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_rates', function (Blueprint $table) {
            foreach (['per_kg_rate', 'weight_threshold'] as $column) {
                if (Schema::hasColumn('shipping_rates', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('shipping_rates', function (Blueprint $table) {
            if (! Schema::hasColumn('shipping_rates', 'per_kg_rate')) {
                $table->decimal('per_kg_rate', 10, 2)->default(0)->after('base_rate');
            }

            if (! Schema::hasColumn('shipping_rates', 'weight_threshold')) {
                $table->decimal('weight_threshold', 8, 2)->default(5)->after('per_kg_rate');
            }
        });
    }
};
