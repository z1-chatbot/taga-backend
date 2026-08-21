<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One order, one delivery earning.
 *
 * Four separate code paths credited couriers for a delivery and none of them
 * looked to see whether another had already done it, so an order confirmed in
 * both the rider's app and the admin console was paid for twice. The service
 * now takes a row lock and checks first; this index is the backstop, so a
 * genuine race cannot write the second row even if the check is ever skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Collapse anything already duplicated, keeping the earliest row — that
        // is the one the courier was told about when the delivery completed.
        $duplicates = DB::table('agent_earnings')
            ->select('order_id', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as total'))
            ->groupBy('order_id')
            ->having('total', '>', 1)
            ->get();

        foreach ($duplicates as $row) {
            $removed = DB::table('agent_earnings')
                ->where('order_id', $row->order_id)
                ->where('id', '!=', $row->keep_id)
                ->get();

            foreach ($removed as $earning) {
                // Take the overpayment back off whichever balance received it.
                if ($earning->logistics_company_id) {
                    $this->reverse('logistics_companies', $earning->logistics_company_id, $earning);
                } elseif ($earning->delivery_agent_id) {
                    $this->reverse('delivery_agents', $earning->delivery_agent_id, $earning);
                }
            }

            DB::table('agent_earnings')
                ->where('order_id', $row->order_id)
                ->where('id', '!=', $row->keep_id)
                ->delete();
        }

        Schema::table('agent_earnings', function ($table) {
            $table->unique('order_id', 'agent_earnings_order_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('agent_earnings', function ($table) {
            $table->dropUnique('agent_earnings_order_id_unique');
        });
    }

    private function reverse(string $table, $id, $earning): void
    {
        $amount = (float) $earning->agent_commission;
        $column = $earning->status === 'pending' ? 'pending_balance' : 'available_balance';

        DB::table($table)->where('id', $id)->update([
            $column => DB::raw("GREATEST(0, {$column} - {$amount})"),
            'total_earned' => DB::raw("GREATEST(0, total_earned - {$amount})"),
        ]);
    }
};
