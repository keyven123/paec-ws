<?php

namespace App\Console\Commands;

use App\Constants\GeneralConstants;
use Illuminate\Console\Command;
use App\Helpers\CsvHelper;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

class MigrateAdminAndOrganizer extends Command
{
    use CsvHelper;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:migrate-admin-and-organizer';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        DB::beginTransaction();
        $adminUsers = $this->csvToArray(app_path('Console/data/admin_users.csv'));
        foreach ($adminUsers as $adminUser) {
            $user = AdminUser::where('email', $adminUser['email'])->first();
            if (!$user) {
                if ($adminUser['role'] === 'superadmin') {
                    $role = Role::where('code', GeneralConstants::ROLES['SUPER_ADMIN']['name'])->first();
                    $adminUser['role_uuid'] = $role->uuid;
                } else if ($adminUser['role'] === 'admin') {
                    $role = Role::where('code', GeneralConstants::ROLES['ADMIN']['name'])->first();
                    $adminUser['role_uuid'] = $role->uuid;
                } else if ($adminUser['role'] === 'organizer') {
                    $role = Role::where('code', GeneralConstants::ROLES['ORGANIZER']['name'])->first();
                    $adminUser['role_uuid'] = $role->uuid;
                    $organization = Organization::where('email', $adminUser['email'])->first();
                    if (!$organization) {
                        $organization = Organization::create([
                            'name' => $adminUser['organization_name'],
                            'representative_first_name' => $adminUser['name'],
                            'email' => $adminUser['email'],
                            'status' => GeneralConstants::ORGANIZER_STATUSES['ONBOARDED'],
                        ]);
                    }
                    $adminUser['organization_uuid'] = $organization->uuid;
                } else {
                    $this->error('Invalid role: ' . $adminUser['role']);
                    continue;
                }
                AdminUser::create([
                    'role_uuid' => $adminUser['role_uuid'],
                    'organization_uuid' => $organization?->uuid ?? null,
                    'first_name' => $adminUser['name'],
                    'email' => $adminUser['email'],
                    'password' => 'T!cketoc2025',
                    'is_migrated' => true,
                ]);
                $this->info('Admin user created: ' . $adminUser['email']);
            }
        }
        DB::commit();
    }
}
