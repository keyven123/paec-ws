<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    use HasUuids;
    use HasFactory;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';

    public const TYPE_TEXT = 'text';
    public const TYPE_SCHEDULE_CARD = 'schedule_card';
    public const TYPE_VISIT_ACCEPTED_CARD = 'visit_accepted_card';
    public const TYPE_VISIT_DECLINED_CARD = 'visit_declined_card';
    public const TYPE_VISIT_SUGGESTED_CARD = 'visit_suggested_card';
    public const TYPE_PROPOSAL_CARD = 'proposal_card';
    public const TYPE_PROPOSAL_DECLINED_CARD = 'proposal_declined_card';
    public const TYPE_APPROVAL_CARD = 'approval_card'; // legacy alias
    public const TYPE_DEPOSIT_REQUESTED_CARD = 'deposit_requested_card';
    public const TYPE_DEPOSIT_PAID_CARD = 'deposit_paid_card';
    public const TYPE_BALANCE_DUE_CARD = 'balance_due_card';
    public const TYPE_CONFIRMATION_CARD = 'confirmation_card'; // legacy alias
    public const TYPE_FULLY_PAID_CARD = 'fully_paid_card';

    protected $fillable = [
        'chat_thread_uuid',
        'sender_type',
        'sender_uuid',
        'sender_name',
        'message_type',
        'body',
        'attachment_upload_uuid',
        'attachment_name',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(ChatThread::class, 'chat_thread_uuid', 'uuid');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Upload::class, 'attachment_upload_uuid', 'uuid');
    }
}
