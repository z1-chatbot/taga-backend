<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A delivery code per parcel, not per order.
 *
 * An order split across two pharmacies arrives as two parcels, carried by two
 * riders, on two different days. There was one code for the whole order, so the
 * customer read the same six digits to both riders and either could close out a
 * delivery with a code that was meant for the other. The code exists to prove
 * the right parcel reached the right person, and shared between parcels it
 * proves nothing.
 *
 * Backfilled from the order so that every parcel already in flight keeps
 * working with the code its customer has already been emailed. Where an order
 * has several shipments and one code, they all inherit it — that is the
 * situation as it stands today, and re-minting here would invalidate a code
 * someone is holding at their door. New codes are minted going forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_shipments', function (Blueprint $table) {
            $table->string('delivery_code', 6)->nullable()->after('tracking_number');
            $table->index('delivery_code');
        });

        DB::statement('
            UPDATE order_shipments
            JOIN orders ON orders.id = order_shipments.order_id
            SET order_shipments.delivery_code = orders.delivery_code
            WHERE orders.delivery_code IS NOT NULL
        ');
    }

    public function down(): void
    {
        Schema::table('order_shipments', function (Blueprint $table) {
            $table->dropIndex(['delivery_code']);
            $table->dropColumn('delivery_code');
        });
    }
};
