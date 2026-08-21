<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remembers which licence-expiry reminder a store has already had.
 *
 * The reminder job runs daily, so without this a pharmacy thirty days from
 * expiry would be emailed thirty times. This holds the milestone last sent (30,
 * 14, 7, 1, 0 days remaining, or -1 once it has lapsed) so each one goes out
 * exactly once, and is cleared when a fresh licence is submitted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->integer('licence_reminder_stage')->nullable()->after('verification_notes');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('licence_reminder_stage');
        });
    }
};
