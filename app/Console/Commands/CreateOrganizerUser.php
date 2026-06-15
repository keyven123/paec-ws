<?php

namespace App\Console\Commands;

use App\Models\AdminUser;
use App\Models\PasswordSetup;
use App\Models\Role;
use Illuminate\Console\Command;

class CreateOrganizerUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:create-organizer-user';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Interactively create a new AdminUser record.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('👤 Let’s create a new Admin User!');
        $this->line(str_repeat('-', 45));

        // Step 1: Organization UUID
        $organizationUuid = $this->askRequired('Enter organization UUID');

        // Step 2: Role selection
        $roleName = $this->choice('Select a role', ['organizer', 'scanner'], 0);
        $role = Role::where('name', $roleName)->first();

        if (! $role) {
            $this->error("❌ Role '{$roleName}' not found in database.");
            return self::FAILURE;
        }

        // Step 3: Personal details
        $firstName = $this->askRequired('Enter first name');
        $lastName  = $this->askRequired('Enter last name');
        $email     = $this->askUniqueEmail();

        // Step 4: Summary table
        $this->table(['Field', 'Value'], [
            ['Organization UUID', $organizationUuid],
            ['Role', "{$role->name} ({$role->uuid})"],
            ['First Name', $firstName],
            ['Last Name', $lastName],
            ['Email', $email],
        ]);

        if (! $this->confirm('Do you want to save this user?', true)) {
            $this->warn('Operation cancelled.');
            return self::SUCCESS;
        }

        // Step 5: Save to database
        $adminUser = AdminUser::create([
            'organization_uuid' => $organizationUuid,
            'role_uuid'         => $role->uuid,
            'first_name'        => $firstName,
            'last_name'         => $lastName,
            'email'             => $email,
        ]);

        // Step 6: Create PasswordSetup to trigger email notification
        $passwordSetup = PasswordSetup::create([
            'email' => $email,
            'type'  => PasswordSetup::TYPE_SETUP,
        ]);

        $passwordSetup->sendOtp();

        $this->info("\n✅ AdminUser '{$adminUser->first_name} {$adminUser->last_name}' created successfully!");
        $this->info("🆔 UUID: {$adminUser->uuid}");
        $this->info("📧 Password setup email sent to: {$email}");

        return self::SUCCESS;
    }

     /**
     * Ask for a required field until not empty.
     */
    private function askRequired(string $question): string
    {
        $value = trim($this->ask($question));

        while (empty($value)) {
            $this->error('This field is required.');
            $value = trim($this->ask($question));
        }

        return $value;
    }

    /**
     * Ask for an email that is valid and unique in the AdminUser table.
     */
    private function askUniqueEmail(): string
    {
        $email = trim($this->ask('Enter email address'));

        while (true) {
            // Validate empty
            if (empty($email)) {
                $this->error('Email is required.');
            }
            // Validate format
            elseif (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->error('Invalid email format. Please enter a valid email.');
            }
            // Validate uniqueness
            elseif (AdminUser::where('email', $email)->exists()) {
                $this->error('This email is already taken. Please enter a different email.');
            } else {
                break; // ✅ Valid and unique
            }

            // Re-ask the question if invalid
            $email = trim($this->ask('Enter email address'));
        }

        return $email;
    }
}
