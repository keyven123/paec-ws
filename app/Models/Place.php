<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Place extends Model
{
    use HasUuids;
    use HasFactory;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'address',
        'code',
        'is_visible',
        'status',
    ];

    public function scopeFilters(Builder $query, ?array $filters): Builder
    {
        if (isset($filters['is_visible'])) {
            $raw = $filters['is_visible'];
            $isVisible = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isVisible === null) {
                $isVisible = $raw === 'true' || $raw === '1' || $raw === 1;
            }
            $query = $query->where('is_visible', $isVisible);
        }
        return $query;
    }
}
