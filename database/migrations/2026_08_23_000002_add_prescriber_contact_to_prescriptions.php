<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prescriber contact details on a prescription.
 *
 * The upload form already collected who wrote the prescription (`doctor_name`)
 * and where (`hospital_name`), but nothing that lets a pharmacist actually
 * reach them. Verifying a prescription against its prescriber is a normal part
 * of dispensing — particularly for a controlled or unusual item — and without a
 * number or an address the reviewing pharmacist can only approve or reject on
 * the face of the document.
 *
 * Both are nullable and stay optional: a shopper photographing a paper
 * prescription often cannot read the clinic's phone number off it, and making
 * these required would block uploads rather than improve them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->string('doctor_email')->nullable()->after('doctor_license');
            $table->string('doctor_phone', 32)->nullable()->after('doctor_email');
        });
    }

    public function down(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->dropColumn(['doctor_email', 'doctor_phone']);
        });
    }
};
