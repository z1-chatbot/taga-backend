<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which specialties a member of staff answers for.
 *
 * Consultations could always be assigned to a user, but any user — there was
 * nothing recording that someone *is* a dentist, so the queue could hand a
 * toothache to whoever happened to be logged in. This is the record.
 *
 * A pivot rather than a column on `users`: a pharmacist who also covers
 * nutrition is one person with one login, not two accounts. And a specialty
 * withdrawn from the storefront takes its assignments with it — the rows go,
 * the practitioner's account stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('practitioner_type_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('practitioner_type_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'practitioner_type_id'], 'practitioner_user_unique');
            // The queue's question is "who covers dentistry", so the index runs
            // that way round.
            $table->index('practitioner_type_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practitioner_type_user');
    }
};
