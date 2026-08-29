<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A delivery rate can be agreed with one rider, not only with a company.
 *
 * `shipping_rates` keyed on logistics_company_id alone: a rate was either that
 * company's or global, applying to every courier on the route. There was no way
 * to agree terms with an individual independent rider — the very couriers who
 * have no company to negotiate on their behalf — so the only lever on their pay
 * was a rate everyone else got too, or a percentage of the customer's shipping
 * fee.
 *
 * A row now belongs to a rider, or to a company, or to nobody (global), and the
 * lookup takes the most specific one that applies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_rates', function (Blueprint $table) {
            $table->foreignId('delivery_agent_id')
                ->nullable()
                ->after('logistics_company_id')
                ->constrained('delivery_agents')
                ->cascadeOnDelete();

            // The old index made a route unique per company. It now has to be
            // unique per company *and* rider, or a rider's rate would collide
            // with the global row for the same route.
            $table->dropUnique('shipping_rates_route_company_unique');
            $table->unique(
                ['from_state', 'to_state', 'logistics_company_id', 'delivery_agent_id'],
                'shipping_rates_route_owner_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('shipping_rates', function (Blueprint $table) {
            $table->dropUnique('shipping_rates_route_owner_unique');
        });

        // Rider rates cannot survive a column that is going away, and they must
        // not collapse into global rows that would then apply to everybody.
        \Illuminate\Support\Facades\DB::table('shipping_rates')->whereNotNull('delivery_agent_id')->delete();

        Schema::table('shipping_rates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_agent_id');
            $table->unique(['from_state', 'to_state', 'logistics_company_id'], 'shipping_rates_route_company_unique');
        });
    }
};
