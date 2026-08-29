<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One earning per parcel carried, not per order.
 *
 * `agent_earnings.order_id` was unique — correct while an order was always one
 * journey, and wrong the moment an order was split between two pharmacies. Two
 * riders each drove a leg, the first to confirm took the credit, and the second
 * hit the unique index and was paid nothing. Nothing failed loudly; the second
 * rider simply never saw the money.
 *
 * The uniqueness that matters is per shipment. Legacy rows carry a null
 * shipment_id and MySQL treats nulls as distinct, so for those the guarantee
 * falls back to the row lock and existence check in DeliveryEarningsService —
 * which is the primary guard in any case, this index being the backstop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_earnings', function (Blueprint $table) {
            $table->foreignId('shipment_id')
                ->nullable()
                ->after('order_id')
                ->constrained('order_shipments')
                ->nullOnDelete();
        });

        // Existing earnings belong to whichever shipment carried them. Where the
        // order has exactly one, that is unambiguous; where it has several we
        // cannot know which leg was paid for, so those stay null and keep the
        // old order-level behaviour.
        DB::statement('
            UPDATE agent_earnings
            JOIN (
                SELECT order_id, MIN(id) AS shipment_id, COUNT(*) AS total
                FROM order_shipments
                GROUP BY order_id
                HAVING total = 1
            ) AS single ON single.order_id = agent_earnings.order_id
            SET agent_earnings.shipment_id = single.shipment_id
        ');

        Schema::table('agent_earnings', function (Blueprint $table) {
            $table->dropUnique('agent_earnings_order_id_unique');
            $table->unique(['order_id', 'shipment_id'], 'agent_earnings_order_shipment_unique');
        });
    }

    public function down(): void
    {
        Schema::table('agent_earnings', function (Blueprint $table) {
            $table->dropUnique('agent_earnings_order_shipment_unique');
        });

        // Collapse back to one per order before the old index can be restored.
        DB::statement('
            DELETE e FROM agent_earnings e
            JOIN (SELECT order_id, MIN(id) AS keep_id FROM agent_earnings GROUP BY order_id) k
              ON k.order_id = e.order_id AND e.id != k.keep_id
        ');

        Schema::table('agent_earnings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shipment_id');
            $table->unique('order_id', 'agent_earnings_order_id_unique');
        });
    }
};
