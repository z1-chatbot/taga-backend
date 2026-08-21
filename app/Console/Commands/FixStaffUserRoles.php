<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;

class FixStaffUserRoles extends Command
{
    protected $signature = 'fix:staff-roles';
    protected $description = 'Fix staff users who have role_id but role field is set to customer';

    public function handle()
    {
        $this->info('Fixing staff user roles...');

        // Find users with role_id but role = 'customer'
        $staffUsers = User::whereNotNull('role_id')
            ->where('role', 'customer')
            ->with('roleRelation')
            ->get();

        if ($staffUsers->isEmpty()) {
            $this->info('No staff users need fixing.');
            return 0;
        }

        $count = 0;
        foreach ($staffUsers as $user) {
            if ($user->roleRelation) {
                $user->role = $user->roleRelation->name;
                $user->save();
                $this->info("Fixed user: {$user->email} - Role set to: {$user->role}");
                $count++;
            }
        }

        $this->info("Fixed {$count} staff user(s).");
        return 0;
    }
}
