<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An account signed up with Google has no password, and says so.
 *
 * It used to store a random 64-character one. That was a workaround for a real
 * problem -- Hash::check() on a null second argument is an error, so the login
 * path needed *something* to compare against -- but it threw away the fact we
 * now need: after hashing, a random password is indistinguishable from a chosen
 * one. So nothing could tell whether an account had a password its owner knows,
 * and the Profile page showed a "Change your password" form to people whose
 * current password was 64 random characters nobody had ever seen. It could only
 * ever answer "Current password is incorrect".
 *
 * Null is the honest representation, and it is what the callers now branch on.
 * Every Hash::check() against this column is guarded; see AuthController::login
 * and changePassword.
 *
 * Safe to apply: no account on this platform has a google_id yet, so there is
 * no historical random password to reinterpret. Accounts created from here on
 * carry null, and a password account is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Reversing needs every row to hold a value, or the column cannot take
        // the NOT NULL back. A random one restores the previous behaviour
        // exactly: unusable for signing in, and indistinguishable from a real
        // one, which is the state this migration exists to leave behind.
        \App\Models\User::whereNull('password')->get()->each(function ($user) {
            $user->forceFill([
                'password' => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(64)),
            ])->save();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
