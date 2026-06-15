<?php

namespace App\Services\Organizer;

use App\Constants\GeneralConstants;
use App\Helpers\CsvHelper;
use App\Models\Permission;
use Illuminate\Support\Collection;

class OrganizerPermissionCatalogService
{
    use CsvHelper;

    /** @var array<int, array{code: string, available_access: string}>|null */
    private ?array $catalogRows = null;

    /** @var array<string, string>|null */
    private ?array $catalogByCode = null;

    /**
     * @return array<int, array{code: string, available_access: string}>
     */
    public function getCatalogRows(): array
    {
        if ($this->catalogRows !== null) {
            return $this->catalogRows;
        }

        $rows = $this->csvToArray(database_path('data/organizer_permissions.csv'));

        $this->catalogRows = array_values(array_filter(array_map(static function (array $row): ?array {
            $code = trim($row['code'] ?? '');

            if ($code === '') {
                return null;
            }

            return [
                'code' => $code,
                'available_access' => trim($row['available_access'] ?? ''),
            ];
        }, $rows)));

        $this->catalogByCode = collect($this->catalogRows)
            ->pluck('available_access', 'code')
            ->all();

        return $this->catalogRows;
    }

    /**
     * @return array<string, string>
     */
    public function getCatalogByCode(): array
    {
        if ($this->catalogByCode === null) {
            $this->getCatalogRows();
        }

        return $this->catalogByCode ?? [];
    }

    public function isCodeAllowed(string $code): bool
    {
        return array_key_exists($code, $this->getCatalogByCode());
    }

    public function isAccessAllowed(string $code, string $accessString): bool
    {
        $allowed = $this->getCatalogByCode()[$code] ?? '';

        if ($allowed === '') {
            return false;
        }

        foreach (str_split($accessString) as $letter) {
            if (!str_contains($allowed, $letter)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public function allowedLettersForCode(string $code): array
    {
        $access = $this->getCatalogByCode()[$code] ?? '';

        if ($access === '') {
            return [];
        }

        return $this->orderedAccessLetters($access);
    }

    /**
     * @return Collection<int, Permission>
     */
    public function getAssignablePermissions(): Collection
    {
        $catalogByCode = $this->getCatalogByCode();
        $codes = array_keys($catalogByCode);

        if ($codes === []) {
            return collect();
        }

        $permissions = Permission::query()
            ->whereIn('code', $codes)
            ->orderBy('module')
            ->orderBy('name')
            ->get()
            ->keyBy('code');

        return collect($catalogByCode)
            ->map(function (string $access, string $code) use ($permissions): ?Permission {
                $permission = $permissions->get($code);

                if (!$permission) {
                    return null;
                }

                $copy = clone $permission;
                $copy->available_access = $this->orderedAccessLetters($access);

                return $copy;
            })
            ->filter()
            ->values();
    }

    /**
     * @return list<string>
     */
    private function orderedAccessLetters(string $access): array
    {
        $letters = array_flip(str_split($access));

        return array_values(array_filter(
            array_keys(GeneralConstants::PERMISSION_LABEL),
            static fn (string $letter): bool => isset($letters[$letter]),
        ));
    }
}
