<?php

namespace App\Models;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CmsPage extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    public $incrementing = false;

    public $primaryKey = 'uuid';

    protected $keyType = 'string';

    protected $fillable = [
        'title',
        'slug',
        'content',
        'status',
        'show_in_footer',
        'footer_column',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'show_in_footer' => 'boolean',
        'sort_order' => 'integer',
    ];

    const STATUS_DRAFT = 'draft';

    const STATUS_PUBLISHED = 'published';

    const FOOTER_COLUMNS = ['explore', 'support'];

    public function scopeFilters(Builder $query, ?array $filters): Builder
    {
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['q'])) {
            $keyword = $filters['q'];
            $query->where(function ($sub) use ($keyword) {
                $sub->where('title', 'LIKE', "%{$keyword}%")
                    ->orWhere('slug', 'LIKE', "%{$keyword}%");
            });
        }

        return $query;
    }
}
