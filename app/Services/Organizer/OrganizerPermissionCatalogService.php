<?php

namespace App\Services\Organizer;

use App\Constants\GeneralConstants;
use App\Constants\PermissionRoleScope;
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
        return Permission::query()
            ->where('code', $code)
            ->shared()
            ->exists();
    }

    public function isAccessAllowed(string $code, string $accessString): bool
    {
        $allowedLetters = $this->allowedLettersForCode($code);

        if ($allowedLetters === []) {
            return false;
        }

        foreach (str_split($accessString) as $letter) {
            if (!in_array($letter, $allowedLetters, true)) {
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
        $catalogAccess = $this->getCatalogByCode()[$code] ?? '';

        if ($catalogAccess !== '') {
            return $this->orderedAccessLetters($catalogAccess);
        }

        $permission = Permission::query()
            ->where('code', $code)
            ->shared()
            ->first();

        if (!$permission) {
            return [];
        }

        $available = $permission->available_access;

        return is_array($available) ? $available : str_split((string) $available);
    }

    /**
     * @return Collection<int, Permission>
     */
    public function getAssignablePermissions(): Collection
    {
        $catalogByCode = $this->getCatalogByCode();

        return Permission::query()
            ->shared()
            ->orderBy('module')
            ->orderBy('name')
            ->get()
            ->map(function (Permission $permission) use ($catalogByCode): Permission {
                $copy = clone $permission;

                if (array_key_exists($permission->code, $catalogByCode)) {
                    $copy->available_access = $this->orderedAccessLetters($catalogByCode[$permission->code]);
                }

                return $copy;
            })
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
