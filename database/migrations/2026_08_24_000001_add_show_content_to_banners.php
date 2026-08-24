<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a banner say whether its text should be drawn over the ground.
 *
 * Some banner artwork is self-contained: the headline, the offer and the call
 * to action are already inside the image, put there by whoever designed it. For
 * those, the title and subtitle the admin form insists on are not content, they
 * are duplication printed next to the artwork that already says it.
 *
 * Defaults to true so every existing banner keeps rendering exactly as it does
 * today, and the admin has to opt a banner out deliberately.
 *
 * The title stays required regardless. It is how a banner is identified in the
 * admin list, and when the copy is hidden it becomes the image's alt text —
 * which is the one place the wording still has to exist for anyone using a
 * screen reader.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->boolean('show_content')->default(true)->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->dropColumn('show_content');
        });
    }
};
