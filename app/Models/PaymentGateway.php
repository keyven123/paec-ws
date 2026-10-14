<?php

namespace App\Models;

use App\Services\Payments\PaynamicsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentGateway extends Model
{
    use HasUuids;
    use SoftDeletes;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'provider',
        'environment',
        'gateway_id',
        'public_key',
        'secret_key',
        'api_username',
        'api_password',
        'paybiz_wallet_id',
        'payment_channels',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $hidden = [
        'public_key',
        'secret_key',
        'api_username',
        'api_password',
    ];

    protected $casts = [
        'public_key' => 'encrypted',
        'secret_key' => 'encrypted',
        'api_username' => 'encrypted',
        'api_password' => 'encrypted',
        'payment_channels' => 'array',
    ];

    /**
     * Paynamics channels customers may pay with through this gateway.
     *
     * @return array<int, string>
     */
    public function enabledPaynamicsChannels(): array
    {
        return $this->payment_channels ?: array_keys(PaynamicsService::CHANNELS);
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        if (! empty($filters['q'])) {
            $search = '%' . $filters['q'] . '%';
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', $search)
                    ->orWhere('gateway_id', 'like', $search);
            });
        }

        foreach (['provider', 'status'] as $column) {
            if (! empty($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        if (! empty($filters['environment'])) {
            $filters['environment'] === 'unverified'
                ? $query->whereNull('environment')
                : $query->where('environment', $filters['environment']);
        }

        return $query;
    }

    /**
     * Show only the edges of a credential so admins can recognise it without exposing it.
     */
    public static function maskCredential(?string $value, int $visiblePrefix = 0): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $length = strlen($value);
        if ($length <= 8) {
            return str_repeat('•', $length);
        }

        $prefix = $visiblePrefix > 0 ? substr($value, 0, $visiblePrefix) : '';

        return $prefix . str_repeat('•', 8) . substr($value, -4);
    }
}
