<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Allows 'manual' as a payment gateway.
 *
 * PaymentController::adminConfirmPayment writes `'gateway' => 'manual'` when an
 * admin confirms a payment taken outside the gateway — a bank transfer, or cash
 * collected on delivery. The column was
 *
 *     enum('paystack','stripe','flutterwave')
 *
 * so every one of those confirmations failed with "Data truncated for column
 * 'gateway'", and the admin console could not mark any such order paid.
 *
 * Recording it as 'paystack' instead would be simpler but would put a false
 * entry in the financial record, so the enum gains the value it is actually
 * being given.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE `payment_transactions`
             MODIFY COLUMN `gateway` ENUM('paystack','stripe','flutterwave','manual') NOT NULL"
        );
    }

    public function down(): void
    {
        // Rows recorded as 'manual' would be truncated by a straight revert, so
        // they are moved to the closest surviving value first.
        DB::table('payment_transactions')->where('gateway', 'manual')->update(['gateway' => 'paystack']);

        DB::statement(
            "ALTER TABLE `payment_transactions`
             MODIFY COLUMN `gateway` ENUM('paystack','stripe','flutterwave') NOT NULL"
        );
    }
};
