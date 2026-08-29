<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The kinds of practitioner a shopper can ask to speak to.
 *
 * These were a constant on ConsultationRequest, whose own comment allowed for
 * this: "Moving it into system settings later needs no schema change — the
 * column is a plain string, validated against whatever this list holds."
 * Consultations keep storing the slug, so nothing about existing rows changes.
 *
 * Seeded with exactly the ten that were hardcoded, so the list a shopper sees
 * the moment this runs is the list they saw the moment before.
 */
return new class extends Migration
{
    private const SEEDED = [
        ['doctor', 'Doctor (General Practitioner)'],
        ['pharmacist', 'Pharmacist'],
        ['dentist', 'Dentist'],
        ['optometrist', 'Optometrist'],
        ['physiotherapist', 'Physiotherapist'],
        ['nurse', 'Nurse'],
        ['nutritionist', 'Nutritionist / Dietitian'],
        ['mental_health', 'Mental health professional'],
        ['paediatrician', 'Paediatrician'],
        ['other', 'Not sure / other'],
    ];

    public function up(): void
    {
        Schema::create('practitioner_types', function (Blueprint $table) {
            $table->id();
            // The slug is what consultations store, so it is the stable
            // identity here and cannot collide.
            $table->string('slug')->unique();
            $table->string('label');
            $table->string('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        $now = now();

        DB::table('practitioner_types')->insert(
            collect(self::SEEDED)->map(fn ($entry, $index) => [
                'slug' => $entry[0],
                'label' => $entry[1],
                // "Not sure / other" belongs at the bottom of the list wherever
                // else the order lands, so it keeps its place explicitly.
                'sort_order' => $entry[0] === 'other' ? 999 : $index,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('practitioner_types');
    }
};
