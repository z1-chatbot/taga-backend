<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a banner have no button.
 *
 * `button_text` was NOT NULL with a default of "Shop Now" while the controller
 * validated it as `nullable`. The two disagreed, and the column won: sending an
 * explicit null was a 500 rather than a validation error, and there was no way
 * to express "this banner links somewhere but shows no button".
 *
 * That matters more now that a banner can hide its copy entirely. Artwork
 * carrying its own call to action should not also be told it must have a
 * "Shop Now" pill on record.
 *
 * The default stays, so a create that simply omits the field still gets a
 * sensible label rather than a bare link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->string('button_text')->nullable()->default('Shop Now')->change();
        });
    }

    public function down(): void
    {
        // Rows cleared while the column was nullable would break the NOT NULL
        // put back here, so give them the old default first.
        DB::table('banners')->whereNull('button_text')->update(['button_text' => 'Shop Now']);

        Schema::table('banners', function (Blueprint $table) {
            $table->string('button_text')->default('Shop Now')->nullable(false)->change();
        });
    }
};
