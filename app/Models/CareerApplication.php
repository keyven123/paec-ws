<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareerApplication extends Model
{
    use HasFactory;
    use HasUuids;

    public $incrementing = false;

    public $primaryKey = 'uuid';

    protected $keyType = 'string';

    protected $fillable = [
        'career_position_uuid',
        'user_uuid',
        'full_name',
        'email',
        'phone',
        'salary_expectation',
        'resume_url',
        'resume_file_name',
        'cover_letter',
        'status',
        'interview_at',
        'interview_location',
    ];

    protected $casts = [
        'interview_at' => 'datetime',
    ];

    const STATUSES = [
        'applied',
        'accepted',
        'for_interview',
        'completed',
        'denied',
    ];

    public function position(): BelongsTo
    {
        return $this->belongsTo(CareerPosition::class, 'career_position_uuid', 'uuid');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }
}
