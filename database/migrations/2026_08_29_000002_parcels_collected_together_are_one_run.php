<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parcels one rider collects in a single round.
 *
 * Two pharmacies in the same city are one pickup run, not two deliveries: a
 * rider collects from both and drives one route to the customer. Charging twice
 * for that — which is what pricing per pharmacy did — bills the shopper for a
 * journey nobody makes.
 *
 * The group is stamped on the shipment rather than derived from the shop each
 * time it is needed. A pharmacy that moves city later must not silently
 * re-group parcels already in flight, or an order's fee stops matching what
 * the customer was charged.
 *
 * Null means ungrouped, which is every parcel created before this and every
 * parcel whose shop has no city on file. Those keep being handled one at a
 * time, exactly as they are now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_shipments', function (Blueprint $table) {
            $table->string('pickup_group', 191)->nullable()->after('store_id');
            $table->index(['order_id', 'pickup_group'], 'order_shipments_run_index');
        });

        // Backfill from where each parcel is actually collected. Orders already
        // delivered are stamped too — the earnings ledger reads this, and a
        // historical row with no group would read as a run of one, which it was.
        DB::statement("
            UPDATE order_shipments
            JOIN stores ON stores.id = order_shipments.store_id
            SET order_shipments.pickup_group = LOWER(CONCAT(
                TRIM(COALESCE(stores.state, '')), '|', TRIM(COALESCE(stores.city, ''))
            ))
            WHERE stores.state IS NOT NULL
              AND stores.state <> ''
              AND stores.city IS NOT NULL
              AND stores.city <> ''
        ");
    }

    public function down(): void
    {
        Schema::table('order_shipments', function (Blueprint $table) {
            $table->dropIndex('order_shipments_run_index');
            $table->dropColumn('pickup_group');
        });
    }
};
