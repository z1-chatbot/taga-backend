<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How an account signs in, stated outright.
 *
 * This was previously inferred: an account with no password was taken to be a
 * Google account. That is true today and is a bad thing to depend on. It is an
 * absence standing in for a fact, so it holds only for as long as nothing else
 * can produce a passwordless account — an invited user who has not chosen one
 * yet, an imported account, a future provider — and the day one appears, every
 * branch that read it silently starts telling people to sign in with Google.
 *
 * A column that says what it means does not have that failure mode, and it is
 * what the front end reads to decide whether to offer a password form at all.
 *
 * `password` stays nullable, because a Google account genuinely has no
 * password and a random hash would be dead data that looks alive. But nothing
 * decides anything by looking at it any more.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'auth_provider')) {
                $table->string('auth_provider', 20)
                    ->default('password')
                    ->after('google_id');
            }
        });

        // Nothing to correct today — no account on this platform has a
        // google_id — but the backfill belongs with the column rather than in
        // whoever runs this on an environment that got ahead of us.
        DB::table('users')->whereNotNull('google_id')->update(['auth_provider' => 'google']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('auth_provider');
        });
    }
};
