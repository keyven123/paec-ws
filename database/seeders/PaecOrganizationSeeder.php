<?php

namespace Database\Seeders;

use App\Constants\GeneralConstants;
use App\Models\Organization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PaecOrganizationSeeder extends Seeder
{
    public const PAEC_ORG_NAME = 'Philippine Amusement and Entertainment Corporation (PAEC)';

    public function run(): void
    {
        DB::beginTransaction();

        Organization::updateOrCreate(
            ['email' => 'inquire@paec.com'],
            [
                'business_type' => Organization::BUSINESS_TYPE_CORPORATION,
                'name' => self::PAEC_ORG_NAME,
                'representative_first_name' => 'PAEC',
                'representative_last_name' => 'Admin',
                'address' => 'Metro Manila, Philippines',
                'contact_number' => '+63 2 8888 0000',
                'status' => GeneralConstants::ORGANIZER_STATUSES['ONBOARDED'],
                'description' => 'Official PAEC amusement and entertainment activities.',
            ]
        );

        DB::commit();
    }
}
