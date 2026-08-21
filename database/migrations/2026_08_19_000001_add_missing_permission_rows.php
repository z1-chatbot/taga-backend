<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permissions that route guards and UI guards already ask for, but which have
 * no row — so they could never be granted to anyone.
 *
 * `orders.update_payment` gates payment verification and confirmation on two
 * admin routes and one button in the order detail screen. Because there was no
 * row, no checkbox existed for it, nobody but an admin could ever pass the
 * guard, and the role editor would have rejected the name with a 422 if anyone
 * had tried to add it by hand.
 *
 * Additive and idempotent: existing rows are left alone, and `down()` removes
 * only what this added.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        [
            'name' => 'orders.update_payment',
            'display_name' => 'Verify & Confirm Payment',
            'group' => 'orders',
            'description' => 'Verify a gateway payment or confirm a manual one',
        ],
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            $exists = DB::table('permissions')->where('name', $permission['name'])->exists();

            if ($exists) {
                continue;
            }

            DB::table('permissions')->insert($permission + [
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $names = array_column(self::PERMISSIONS, 'name');

        // Drop the pivot rows first: the role_permission rows would otherwise
        // be orphaned and silently grant nothing on the next re-run.
        $ids = DB::table('permissions')->whereIn('name', $names)->pluck('id');

        DB::table('role_permission')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('name', $names)->delete();
    }
};
