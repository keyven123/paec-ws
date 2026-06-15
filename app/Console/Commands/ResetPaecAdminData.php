<?php

namespace App\Console\Commands;

use App\Models\AdminUser;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\DatasetSeeder;
use Database\Seeders\EventSectionSeeder;
use Database\Seeders\PaecActivitySeeder;
use Database\Seeders\PaecOrganizationSeeder;
use Database\Seeders\SuperAdminUserSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ResetPaecAdminData extends Command
{
    protected $signature = 'paec:reset-admin-data {--force : Skip confirmation}';

    protected $description = 'Clear PAEC operational admin data and re-seed activities';

    public function handle(): int
    {
        if (!$this->option('force') && !$this->confirm('This will clear transactions, tickets, promo codes, and other operational data. Activities will be re-seeded. Continue?')) {
            return self::SUCCESS;
        }

        $this->info('Clearing operational data...');

        Schema::disableForeignKeyConstraints();

        $tables = [
            'ticket_seats',
            'tickets',
            'temp_transaction_orders',
            'temp_transactions',
            'transaction_orders',
            'transaction_commissions',
            'transaction_compliances',
            'transactions',
            'event_ticket_coupons',
            'ticket_coupons',
            'promo_codes',
            'chat_messages',
            'chat_threads',
            'venue_inquiries',
            'platform_notifications',
            'affiliate_conversions',
            'affiliate_link_clicks',
            'affiliate_payout_requests',
            'user_affiliates',
            'merchant_payout_requests',
            'event_reminder_logs',
            'activity_compliance_histories',
            'activity_compliances',
            'organization_platform_coms',
            'venue_listings',
            'event_tickets',
            'schedule_times',
            'schedules',
            'events',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->truncate();
                $this->line("  truncated {$table}");
            }
        }

        if (Schema::hasTable('datasets')) {
            DB::table('datasets')->truncate();
        }

        User::query()->forceDelete();
        AdminUser::query()->where('email', '!=', 'admin@paec.com')->forceDelete();

        Schema::enableForeignKeyConstraints();

        $this->info('Re-seeding core data...');

        $this->call(SuperAdminUserSeeder::class);
        $this->call(CustomerSeeder::class);
        $this->call(PaecOrganizationSeeder::class);
        $this->call(CategorySeeder::class);
        $this->call(PaecActivitySeeder::class);
        $this->call(DatasetSeeder::class);

        $activityCount = DB::table('events')->count();

        $this->newLine();
        $this->info("Done. Seeded {$activityCount} activities.");
        $this->info('Admin login: admin@paec.com / P@ec2026!!');

        return self::SUCCESS;
    }
}
