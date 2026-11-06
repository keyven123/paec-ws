<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MetaPixelEventLog extends Model
{
    use HasUuids;

    protected $primaryKey = 'uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'event_name',
        'source',
        'status',
        'pixel_id',
        'event_id',
        'content_name',
        'page_url',
        'value',
        'currency',
        'transaction_uuid',
        'event_uuid',
        'order_id',
        'http_status',
        'error_message',
        'payload',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'payload' => 'array',
        'http_status' => 'integer',
    ];
}
