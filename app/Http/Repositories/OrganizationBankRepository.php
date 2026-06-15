<?php

namespace App\Http\Repositories;

use App\Exceptions\NoResourceFoundException;
use App\Models\Organization;
use App\Models\OrganizationBank;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class OrganizationBankRepository
{
    public const BANK_FIELDS = [
        'account_type',
        'bank_name',
        'bank_branch',
        'bank_address',
        'bank_account_name',
        'bank_account_number',
    ];

    public function __construct(
        protected OrganizationBank $organizationBank,
    ) {
    }

    public function listForOrganization(string $organizationUuid): Collection
    {
        return $this->organizationBank
            ->where('organization_uuid', $organizationUuid)
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @throws NoResourceFoundException
     */
    public function fetchForOrganizationOrThrow(string $organizationUuid, string $bankUuid): OrganizationBank
    {
        $bank = $this->organizationBank
            ->where('organization_uuid', $organizationUuid)
            ->where('uuid', $bankUuid)
            ->first();

        if ($bank === null) {
            throw new NoResourceFoundException('Organization bank account not found');
        }

        return $bank;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createForOrganization(Organization $organization, array $payload): OrganizationBank
    {
        return DB::transaction(function () use ($organization, $payload) {
            $isDefault = (bool) ($payload['is_default'] ?? false);
            if ($isDefault) {
                $this->clearDefaultFlag($organization->uuid);
            } elseif (! $this->organizationBank->where('organization_uuid', $organization->uuid)->exists()) {
                $isDefault = true;
            }

            return $this->organizationBank->create([
                'organization_uuid' => $organization->uuid,
                'account_type' => $payload['account_type'],
                'bank_name' => $payload['bank_name'],
                'bank_branch' => $payload['bank_branch'],
                'bank_address' => $payload['bank_address'],
                'bank_account_name' => $payload['bank_account_name'],
                'bank_account_number' => $payload['bank_account_number'],
                'is_default' => $isDefault,
                'status' => $payload['status'] ?? OrganizationBank::STATUS_ACTIVE,
            ]);
        });
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateBank(OrganizationBank $bank, array $payload): OrganizationBank
    {
        return DB::transaction(function () use ($bank, $payload) {
            if (! empty($payload['is_default'])) {
                $this->clearDefaultFlag($bank->organization_uuid, $bank->uuid);
            }

            $bank->update([
                'account_type' => $payload['account_type'],
                'bank_name' => $payload['bank_name'],
                'bank_branch' => $payload['bank_branch'],
                'bank_address' => $payload['bank_address'],
                'bank_account_name' => $payload['bank_account_name'],
                'bank_account_number' => $payload['bank_account_number'],
                'is_default' => $payload['is_default'] ?? $bank->is_default,
                'status' => $payload['status'] ?? $bank->status,
            ]);

            $this->ensureDefaultBankExists($bank->organization_uuid);

            return $bank->fresh();
        });
    }

    public function deleteBank(OrganizationBank $bank): void
    {
        DB::transaction(function () use ($bank) {
            $organizationUuid = $bank->organization_uuid;
            $wasDefault = $bank->is_default;
            $bank->delete();

            if ($wasDefault) {
                $replacement = $this->organizationBank
                    ->where('organization_uuid', $organizationUuid)
                    ->orderBy('created_at')
                    ->first();

                if ($replacement !== null) {
                    $replacement->update(['is_default' => true]);
                }
            }
        });
    }

    /**
     * @param list<array<string, mixed>> $banksPayload
     */
    public function syncForOrganization(Organization $organization, array $banksPayload): Collection
    {
        return DB::transaction(function () use ($organization, $banksPayload) {
            $keptUuids = [];
            $hasExplicitDefault = false;

            foreach ($banksPayload as $bankData) {
                if (! empty($bankData['is_default'])) {
                    $hasExplicitDefault = true;
                    break;
                }
            }

            foreach ($banksPayload as $index => $bankData) {
                $isDefault = ! empty($bankData['is_default'])
                    || (! $hasExplicitDefault && $index === 0);

                if ($isDefault) {
                    $this->clearDefaultFlag($organization->uuid);
                }

                $attributes = [
                    'account_type' => $bankData['account_type'],
                    'bank_name' => $bankData['bank_name'],
                    'bank_branch' => $bankData['bank_branch'],
                    'bank_address' => $bankData['bank_address'],
                    'bank_account_name' => $bankData['bank_account_name'],
                    'bank_account_number' => $bankData['bank_account_number'],
                    'is_default' => $isDefault,
                    'status' => $bankData['status'] ?? OrganizationBank::STATUS_ACTIVE,
                ];

                if (! empty($bankData['uuid'])) {
                    $bank = $this->fetchForOrganizationOrThrow($organization->uuid, $bankData['uuid']);
                    $bank->update($attributes);
                    $keptUuids[] = $bank->uuid;
                } else {
                    $bank = $this->organizationBank->create(array_merge($attributes, [
                        'organization_uuid' => $organization->uuid,
                    ]));
                    $keptUuids[] = $bank->uuid;
                }
            }

            $this->organizationBank
                ->where('organization_uuid', $organization->uuid)
                ->whereNotIn('uuid', $keptUuids)
                ->delete();

            $this->ensureDefaultBankExists($organization->uuid);

            return $this->listForOrganization($organization->uuid);
        });
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function upsertDefaultBank(Organization $organization, array $payload): ?OrganizationBank
    {
        $bankPayload = [];
        foreach (self::BANK_FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                $bankPayload[$field] = $payload[$field];
            }
        }

        if ($bankPayload === []) {
            return null;
        }

        $hasValues = false;
        foreach ($bankPayload as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                $hasValues = true;
                break;
            }
        }

        if (! $hasValues) {
            return null;
        }

        $defaultBank = $this->organizationBank
            ->where('organization_uuid', $organization->uuid)
            ->where('is_default', true)
            ->first();

        if ($defaultBank === null) {
            $defaultBank = $this->organizationBank
                ->where('organization_uuid', $organization->uuid)
                ->orderBy('created_at')
                ->first();
        }

        if ($defaultBank === null) {
            return $this->createForOrganization($organization, array_merge($bankPayload, [
                'is_default' => true,
                'status' => OrganizationBank::STATUS_ACTIVE,
            ]));
        }

        $defaultBank->update($bankPayload);

        return $defaultBank->fresh();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function extractBankPayload(array $payload): array
    {
        $bankPayload = [];
        foreach (self::BANK_FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                $bankPayload[$field] = $payload[$field];
                unset($payload[$field]);
            }
        }

        return [$payload, $bankPayload];
    }

    private function clearDefaultFlag(string $organizationUuid, ?string $exceptUuid = null): void
    {
        $query = $this->organizationBank->where('organization_uuid', $organizationUuid);
        if ($exceptUuid !== null) {
            $query->where('uuid', '!=', $exceptUuid);
        }
        $query->update(['is_default' => false]);
    }

    private function ensureDefaultBankExists(string $organizationUuid): void
    {
        $banks = $this->organizationBank
            ->where('organization_uuid', $organizationUuid)
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->get();

        if ($banks->isEmpty()) {
            return;
        }

        if ($banks->contains(fn (OrganizationBank $bank) => $bank->is_default)) {
            return;
        }

        $banks->first()?->update(['is_default' => true]);
    }
}
