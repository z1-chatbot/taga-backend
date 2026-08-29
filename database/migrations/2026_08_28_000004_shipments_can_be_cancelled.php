<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A parcel can be cancelled.
 *
 * Cancelling an order set its own status and stopped there. The parcels stayed
 * exactly where they were — usually 'pending' — so a cancelled order still
 * showed as a live job in the rider's portal, still appeared in the admin
 * console as awaiting dispatch, and could still have a courier assigned to it.
 * Somebody would have driven to a pharmacy to collect an order that no longer
 * existed.
 *
 * 'failed' and 'returned' were the only endings available and neither is
 * honest: nothing failed, and nothing came back. The parcel was called off.
 */
return new class extends Migration
{
    private const WITH_CANCELLED = "'pending','shop_preparing','ready_for_pickup','assigned_to_agent','picked_up','in_transit','arrived_at_hub','out_for_delivery','delivered','failed','returned','cancelled'";

    private const WITHOUT_CANCELLED = "'pending','shop_preparing','ready_for_pickup','assigned_to_agent','picked_up','in_transit','arrived_at_hub','out_for_delivery','delivered','failed','returned'";

    public function up(): void
    {
        DB::statement(
            'ALTER TABLE order_shipments MODIFY COLUMN status ENUM('
            .self::WITH_CANCELLED
            .") NOT NULL DEFAULT 'pending'"
        );
    }

    public function down(): void
    {
        // Nothing can sit on a value the column is about to stop accepting.
        DB::table('order_shipments')->where('status', 'cancelled')->update(['status' => 'failed']);

        DB::statement(
            'ALTER TABLE order_shipments MODIFY COLUMN status ENUM('
            .self::WITHOUT_CANCELLED
            .") NOT NULL DEFAULT 'pending'"
        );
    }
};
