<?php

namespace App\Helpers;

use App\Constants\GeneralConstants;
use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GeneralHelper
{
    /**
     * @param $file
     * @param string $scope
     * @return array
     */
    public static function getScopeAccess(string $scope): array
    {
        $access = str_split($scope);
        $array = [];
        foreach ($access as $value) {
            $array[] =  GeneralConstants::PERMISSION_LABEL[$value];
        }
        return $array;
    }

    public static function checkHasAccess(string $scope): bool
    {
        $jwtPayload = TokenParserHelper::getClaims(request()->bearerToken());
        $permissions = $jwtPayload->permissions ?? [];

        if (!$permissions || !in_array($scope, GeneralHelper::getScope($permissions))) {
            return false;
        }

        return true;
    }

    /**
     * @param UploadedFile $file
     * @param string $path
     * @return array
     */
    public static function uploadHelper(UploadedFile $file, string $path): array
    {
        $completePath = public_path() . "/$path";
        $extension = pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION);
        $fileName = time() . '-' . $file('image')->getClientOriginalName() . '.' . $extension;
        // $fileName = strtotime('now') . '.' . $extension;

        if (!File::exists($completePath)) {
            File::makeDirectory($completePath, 0755, true);
        }
        // $file->move($completePath, $fileName);
        $file->save($completePath . $fileName);

        // Generate Thumbnail Image Upload on Folder Code
        $destinationPathThumbnail = $completePath . '/thumbnails/';
        $file->resize(100, 100);

        if (!File::exists($destinationPathThumbnail)) {
            File::makeDirectory($destinationPathThumbnail, 0755, true);
        }

        $file->save($destinationPathThumbnail . $fileName);

        return [
            'url' => "$path$fileName",
            'type' => $extension
        ];
    }

    /**
     * @param string $path
     * @return string
     */
    public static function deleteFile(string $path): void
    {
        if (File::exists(str_replace("\\", '/', $path))) {
            unlink(str_replace("\\", '/', $path));
        }
    }

    /**
     * @param string $mobileNumber
     * @return string
     */
    public static function formatContactNumberToPh(?string $mobileNumber): string
    {
        $trimmedMobileNumber = trim($mobileNumber);

        if (strlen($trimmedMobileNumber) < 10) {
            return $trimmedMobileNumber;
        }
        return preg_replace('/^(09|9|639)(\d+)/', '+639$2', $trimmedMobileNumber);
    }

    /**
     * Unset the data fields in an array
     * @param array $payload
     * @param array $acceptedFields
     * @return array
     */
    public static function unsetUnknown(array $payload, array $acceptedFields): array
    {
        foreach ($payload as $key => $item) {
            if (!in_array($key, $acceptedFields)) {
                unset($payload[$key]);
            }
        }

        return $payload;
    }

    /**
     * Unset the data fields in an array
     * @param array $payload
     * @param array $acceptedFields
     * @return array
     */
    public static function unsetUnknownAndNullFields(array $payload, array $acceptedFields): array
    {
        foreach ($payload as $key => $item) {
            if (!in_array($key, $acceptedFields) || is_null($item) || $item === '') {
                unset($payload[$key]);
            }
        }

        return $payload;
    }

    /**
     * Generate SOA number
     * @param int $id
     * @param string $prefix
     * @return string
     */
    public static function generateSoaNumber(int $id, string $prefix = ''): string
    {
        $year = Carbon::now()->format('Y');
        $numbers = '0000000';
        $soa = "$prefix$year$numbers";
        $strLength = strlen($id);
        return substr_replace($soa, $id, -$strLength);
    }

    /**
     * Generate order number
     * @param string $prefix
     * @return string
     */
    public static function generateOrderNumber(string $prefix = 'TKT-'): string
    {
        $date = Carbon::now()->format('Ymd');
        $uniqueCode = Str::upper(Str::random(8));
        $orderNumber = "$prefix$date-$uniqueCode";
        return $orderNumber;
    }

    /**
     * Generate payment order id
     * @param string $prefix
     * @return string
     */
    public static function generatePaymentOrderId(string $prefix = 'PAY-'): string
    {
        $date = Carbon::now()->format('Ymd');
        $uniqueCode = Str::upper(Str::random(8));
        $paymentOrderId = "$prefix$date-$uniqueCode";
        return $paymentOrderId;
    }


    public static function formatWithoutRounding($number, $decimals = 2)
    {
        $factor = pow(10, $decimals);
        $truncated = floor($number * $factor) / $factor;
        return number_format($truncated, $decimals, '.', '');
    }

    /**
     * Get scope from permissions array
     * @param array $permissions
     * @return array
     */
    public static function getScope(array $permissions): array
    {
        return $permissions;
    }

    /**
     * Generate a short unique string from a UUID (uppercase hex).
     * Ensures uniqueness by using the UUID value instead of random characters.
     *
     * @param int $length Number of characters (default 10)
     * @return string Uppercase hex string, e.g. "A1B2C3D4E5"
     */
    public static function shortUuid(int $length = 10): string
    {
        $uuidWithoutDashes = str_replace('-', '', (string) Str::uuid());

        return strtoupper(Str::substr($uuidWithoutDashes, 0, $length));
    }

    /**
     * Generate a unique QR code for the given model (e.g. ticket or coupon).
     * Format: PREFIX + 10 uppercase hex characters derived from a UUID (ensures uniqueness).
     *
     * @param Model $model Model that has a qr_code column (e.g. EventTicketCoupon)
     * @param string $prefix Prefix for the code (e.g. 'TICKET_', 'COUPON_')
     * @return string Unique QR code string, all capital letters
     */
    public static function generateQrCode(Model $model, string $prefix = 'TICKET_'): string
    {
        do {
            $qrCode = $prefix . self::shortUuid(10);
        } while ($model->where('qr_code', $qrCode)->exists());

        return $qrCode;
    }

    public static function getUploadUrl(string $disk, string $path): string
    {
        return Storage::disk($disk)->url($path);
    }

    /**
     * ✅ Helper to map shorthand model names to full namespaces
     */
    public static function resolveModelClass(string $type): string
    {
        $map = [
            'User'         => \App\Models\User::class,
            'AdminUser'    => \App\Models\AdminUser::class,
            'Event'        => \App\Models\Event::class,
            'Organization' => \App\Models\Organization::class,
            'Venue'        => \App\Models\Venue::class,
            'VenueListing' => \App\Models\VenueListing::class,
        ];

        return $map[$type] ?? "\\App\\Models\\{$type}";
    }

    public static function generateUuidTicketNumber(?string $prefix = 'TKT'): string
    {
        $shortUuid = strtoupper(Str::substr(Str::uuid(), 0, 8));
        $date = now()->format('Ymd');

        return "{$prefix}-{$date}-{$shortUuid}";
    }

    public static function generateSlug(string $name): string
    {
        $slug = Str::slug($name);
        // $count = 1;
        // while (Event::where('slug', $slug)->exists()) {
        //     $slug = $slug . '-' . $count;
        //     $count++;
        // }
        return $slug;
    }

    /**
     * Convert a model class name to its morph map key if it exists
     * @param string|null $className
     * @return string|null
     */
    public static function getMorphMapKey(?string $className): ?string
    {
        if (empty($className)) {
            return null;
        }

        $morphMap = Relation::morphMap();
        $key = array_search($className, $morphMap, true);

        // If found in morph map, return the key, otherwise return the original class name
        return $key !== false ? $key : $className;
    }

    public static function getPaymentTypeRefNo(?string $paymentType, mixed $paymentData = null): string
    {
        if ($paymentType === 'paypal') {
            $data = is_string($paymentData) ? json_decode($paymentData, true) : (array) $paymentData;
            return $data['purchase_units'][0]['payments']['captures'][0]['id'] ?? 'N/A';
        }

        if ($paymentType === 'paymongo') {
            $data = is_string($paymentData) ? json_decode($paymentData, true) : $paymentData;

            if (!is_array($data)) {
                return 'N/A';
            }

            $payment = $data['data']['attributes']['payments'][0] ?? null;
            $source = $payment['attributes']['source'] ?? null;

            return $source['provider_id']
                ?? $payment['id']
                ?? $source['id']
                ?? 'N/A';
        }

        return match ($paymentType) {
            'cash' => 'CASH',
            'bank_transfer' => 'BANK_TRANSFER',
            'credit_card' => 'CREDIT_CARD',
            'debit_card' => 'DEBIT_CARD',
            default => 'N/A',
        };
    }
}
