<?php

namespace Database\Seeders;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\OrganizationBank;
use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DefaultOrganizationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::beginTransaction();
        $this->command->info('Start Default Organization Seeder.');

        $org = Organization::firstOrCreate(
            ['email' => 'keyvenrosal14@gmail.com'],
            [
                'business_type' => Organization::BUSINESS_TYPE_SOLE_PROPRIETORSHIP,
                'name' => 'Default Organization',
                'representative_first_name' => 'Default',
                'representative_last_name' => 'Representative',
                'address' => 'Default Address',
                'contact_number' => 'Default Contact Number',
                'status' => GeneralConstants::ORGANIZER_STATUSES['ONBOARDED'],
            ]
        );

        OrganizationBank::firstOrCreate(
            [
                'organization_uuid' => $org->uuid,
                'is_default' => true,
            ],
            [
                'account_type' => OrganizationBank::ACCOUNT_TYPE_SAVINGS,
                'bank_name' => 'BDO',
                'bank_branch' => 'BDO Pasig',
                'bank_address' => 'Default Address',
                'bank_account_name' => 'Keyven Rosal',
                'bank_account_number' => '1234567890',
                'status' => OrganizationBank::STATUS_ACTIVE,
            ]
        );

        $role = Role::where('code', GeneralConstants::ROLES['ORGANIZER']['name'])->first();

        AdminUser::create([
            'role_uuid' => $role->uuid,
            'organization_uuid' => $org->uuid,
            'first_name' => 'Default',
            'last_name' => 'Organizer',
            'email' => 'defaultorganizer@ticketoc.com',
            'password' => 'Password123!',
            'email_verified_at' => now(),
            'accepted_terms' => true,
            'accepted_terms_at' => now(),
            'is_first_time_login' => false,
        ]);

        $this->command->info('Success Seeding Default Organization.');
        DB::commit();
    }
}
