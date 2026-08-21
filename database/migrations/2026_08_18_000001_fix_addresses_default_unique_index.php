<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the unique index that capped every customer at two saved addresses.
 *
 * `unique_default_address` was UNIQUE (user_id, is_default). The apparent intent
 * was "one default address per user", but a two-column unique index constrains
 * *both* values: a user could hold one row with is_default = 1 and exactly one
 * with is_default = 0. Saving a third address failed with
 *
 *     Duplicate entry '13-0' for key 'unique_default_address'
 *
 * "Only one default" is a partial-uniqueness rule, which MySQL cannot express
 * as an index. It is enforced in AddressController instead, which clears the
 * previous default inside a transaction whenever a new one is set.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->indexExists('addresses', 'unique_default_address')) {
            return;
        }

        /*
         * Order matters. user_id carries a foreign key, and MySQL satisfies
         * that constraint using the leftmost column of this very index — so
         * dropping it first fails with "Cannot drop index
         * 'unique_default_address': needed in a foreign key constraint". The
         * replacement index has to exist before the old one goes.
         */
        if (! $this->indexExists('addresses', 'addresses_user_id_index')) {
            Schema::table('addresses', function ($table) {
                $table->index('user_id');
            });
        }

        Schema::table('addresses', function ($table) {
            $table->dropUnique('unique_default_address');
        });
    }

    public function down(): void
    {
        // Deliberately not restored: re-adding it would fail on any user who has
        // since saved a third address, and it was never a correct constraint.
    }

    private function indexExists(string $table, string $index): bool
    {
        return count(DB::select(
            'SHOW INDEX FROM `'.$table.'` WHERE Key_name = ?',
            [$index]
        )) > 0;
    }
};
