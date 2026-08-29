<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Logos of the pharmacies Taga works with, for the storefront.
 *
 * Deliberately not the `stores` table, which is the closest existing thing and
 * the wrong one. A store is an account: it has an owner, a licence, a
 * catalogue, and a verification state that governs whether it can sell. This is
 * a marketing wall — a pharmacy can belong on it before it has an account at
 * all, and a shop can hold an account without its logo being something the
 * platform wants to display.
 *
 * Tying the two together would mean either publishing every registered store
 * whether or not it looks presentable, or adding a "show this one" flag to
 * stores that has nothing to do with selling. Separate rows, curated by hand.
 *
 * `store_id` is an optional link, so a partner that *is* a vendor can point at
 * its shop rather than an external site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_pharmacies', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // Disk-relative path on the `public` disk, as banners and store
            // logos are. Never an absolute URL: the host has moved once
            // already, and rows holding a baked-in origin do not survive that.
            $table->string('logo_path');

            // Where the logo links, if anywhere. A plain string rather than a
            // url-validated column so an internal path ("/stores/x") is as
            // acceptable as an external site.
            $table->string('link_url')->nullable();

            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // The storefront asks for active partners in display order and
            // nothing else, so that is the index.
            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_pharmacies');
    }
};
