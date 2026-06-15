<?php

namespace Database\Seeders;

use App\Constants\GeneralConstants;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        DB::beginTransaction();
        $role = Role::whereCode(GeneralConstants::ROLES['CUSTOMER']['name'])->first();

        if ($role) {
            $customers = [
                [
                    'email' => 'customer@paec.com',
                    'password' => 'P@ec2026!!',
                    'first_name' => 'John Vincent',
                    'last_name' => 'Cerdeño',
                    'phone_number' => '+63 917 555 1234',
                    'birth_date' => '1998-03-22',
                    'city' => 'Manila',
                ],
                [
                    'email' => 'paec@gmail.com',
                    'password' => '123123123',
                    'first_name' => 'PAEC',
                    'last_name' => 'User',
                    'phone_number' => null,
                    'birth_date' => null,
                    'city' => null,
                ],
            ];

            foreach ($customers as $customer) {
                User::updateOrCreate(
                    ['email' => $customer['email']],
                    [
                        'role_uuid' => $role->uuid,
                        'password' => $customer['password'],
                        'email_verified_at' => now(),
                        'first_name' => $customer['first_name'],
                        'last_name' => $customer['last_name'],
                        'phone_number' => $customer['phone_number'],
                        'birth_date' => $customer['birth_date'],
                        'city' => $customer['city'],
                    ]
                );
            }
        }

        DB::commit();
    }
}
