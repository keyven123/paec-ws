<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            SuperadminRolePermissionSeeder::class,
            AdminRolePermissionSeeder::class,
            OrganizerRolePermissionSeeder::class,
            CustomerRolePermissionSeeder::class,
            SuperAdminUserSeeder::class,
            PaecAdminUserSeeder::class,
            OrganizerScannerPermissionSeeder::class,
            CustomerSeeder::class,
            CategorySeeder::class,
            DatasetSeeder::class,
            // VenueSeeder::class,
            // VenueSeatSeeder::class,
            // VenueSeatNPMusicalSvipSeeder::class,
            // VenueSeatNPMusicalVipSeeder::class,
            // VenueSeatNPMusicalGoldSeeder::class,
            // VenueSeatNPMusicalSilverSeeder::class,
            // VenueSeatNPMusicalBronzeSeeder::class,
            // VenueSeatNPMusicalBoothSeeder::class,
            // VenueSeatNPTheatrePlatinumSeeder::class,
            // VenueSeatNPTheatreVipSeeder::class,
            // VenueSeatNPTheatreSvipSeeder::class,
            // VenueSeatNPTheatreGoldSeeder::class,
            // VenueSeatNPTheatreSilverSeeder::class,
            // VenueSeatNPTheatreBronzeSeeder::class,
            // VenueSeatNPTheatreBoothSeeder::class,
            EventSectionSeeder::class,
            // CustomerSeeder::class,
        ]);
    }
}
