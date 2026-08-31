<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patient contact details on a prescription.
 *
 * The upload form already carried a way to reach the *prescriber*
 * (`doctor_email`, `doctor_phone`) but nothing to reach the *patient*. Those
 * are not the same person and often not the same household: prescriptions are
 * routinely uploaded by a relative, and a guest can upload before any account
 * exists, which leaves the reviewing pharmacist with no address at all.
 *
 * Nullable and optional, like the prescriber pair. A pharmacist who needs to
 * query a dose wants a number to call; a shopper photographing a prescription
 * on a phone should not be stopped from uploading because they did not fill
 * one in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            if (! Schema::hasColumn('prescriptions', 'patient_email')) {
                $table->string('patient_email')->nullable()->after('patient_name');
            }

            if (! Schema::hasColumn('prescriptions', 'patient_phone')) {
                $table->string('patient_phone', 32)->nullable()->after('patient_email');
            }
        });
    }

    public function down(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->dropColumn(['patient_email', 'patient_phone']);
        });
    }
};
