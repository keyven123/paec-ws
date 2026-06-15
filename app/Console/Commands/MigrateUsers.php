<?php

namespace App\Console\Commands;

use App\Constants\GeneralConstants;
use Illuminate\Console\Command;
use App\Helpers\CsvHelper;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MigrateUsers extends Command
{
    use CsvHelper;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:migrate-users';

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
        $users = $this->csvToArray(app_path('Console/data/users.csv'));
        $role = Role::where('code', GeneralConstants::ROLES['CUSTOMER']['name'])->first();
        foreach ($users as $user) {
            $userModel = User::where('email', $user['email'])->first();
            if (!$userModel) {
                $fullname = explode(' ', $user['name']);
                $count = count($fullname);
                $lastname = $fullname[$count - 1];
                if ($count > 1) {
                    $firstname = implode(' ', array_slice($fullname, 1, $count - 2));
                }
                $user = User::create([
                    'role_uuid' => $role->uuid,
                    'first_name' => $firstname,
                    'last_name' => $lastname,
                    'email' => $user['email'],
                    'password' => 'T!cketoc2025',
                    'qr_code' => $user['qr_code'],
                    'birth_date' => $user['birth_date'] ? Carbon::parse($user['birth_date'])->format('Y-m-d') : null,
                    'address_line_1' => $user['address_line_1'],
                    'address_line_2' => $user['address_line_2'],
                    'city' => $user['city'],
                    'region' => $user['region'],
                    'postal_code' => $user['postal_code'],
                    'country' => $user['country'],
                    'marketing_consent' => $user['marketing_consent'] == 'FALSE' ? false : true,
                    'marketing_consent_date' => $user['marketing_consent_date'] ? Carbon::parse($user['marketing_consent_date'])->toDateTimeString() : null,
                    'terms_accepted_at' => $user['terms_accepted_at'] ? Carbon::parse($user['terms_accepted_at'])->toDateTimeString() : null,
                    'is_migrated' => true,
                ]);
                $this->info('User created: ' . $user['email']);
            }
        }
        DB::commit();
    }
}
