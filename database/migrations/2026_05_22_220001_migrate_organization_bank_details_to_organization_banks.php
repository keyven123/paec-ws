<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use App\Models\OrganizationBank;

return new class extends Migration
{
    private function organizationHasBankDetails(object $organization): bool
    {
        foreach ([
            'bank_name',
            'bank_branch',
            'bank_address',
            'bank_account_name',
            'bank_account_number',
        ] as $column) {
            $value = $organization->{$column} ?? null;
            if ($value !== null && trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('organization_banks') || ! Schema::hasTable('organizations')) {
            return;
        }

        $organizations = DB::table('organizations')
            ->select([
                'uuid',
                'bank_name',
                'bank_branch',
                'bank_address',
                'bank_account_name',
                'bank_account_number',
            ])
            ->orderBy('created_at')
            ->get();

        $now = now();

        foreach ($organizations as $organization) {
            if (! $this->organizationHasBankDetails($organization)) {
                continue;
            }

            $alreadyMigrated = DB::table('organization_banks')
                ->where('organization_uuid', $organization->uuid)
                ->exists();

            if ($alreadyMigrated) {
                continue;
            }

            DB::table('organization_banks')->insert([
                'uuid' => (string) Str::uuid(),
                'organization_uuid' => $organization->uuid,
                'account_type' => OrganizationBank::ACCOUNT_TYPE_SAVINGS,
                'bank_name' => (string) ($organization->bank_name ?? ''),
                'bank_branch' => (string) ($organization->bank_branch ?? ''),
                'bank_address' => (string) ($organization->bank_address ?? ''),
                'bank_account_name' => (string) ($organization->bank_account_name ?? ''),
                'bank_account_number' => (string) ($organization->bank_account_number ?? ''),
                'is_default' => true,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('organization_banks') || ! Schema::hasTable('organizations')) {
            return;
        }

        $organizations = DB::table('organizations')
            ->select([
                'uuid',
                'bank_name',
                'bank_branch',
                'bank_address',
                'bank_account_name',
                'bank_account_number',
            ])
            ->orderBy('created_at')
            ->get();

        foreach ($organizations as $organization) {
            if (! $this->organizationHasBankDetails($organization)) {
                continue;
            }

            DB::table('organization_banks')
                ->where('organization_uuid', $organization->uuid)
                ->where('is_default', true)
                ->delete();
        }
    }
};
