<?php

namespace App\Http\Repositories;

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\VenueInquiry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\DB;

class ChatRepository
{
    public function __construct(
        protected ChatThread $chatThread,
        protected ChatMessage $chatMessage,
    ) {
    }

    public function findThreadByInquiry(string $inquiryUuid): ?ChatThread
    {
        return $this->chatThread
            ->where('venue_inquiry_uuid', $inquiryUuid)
            ->first();
    }

    public function findThread(string $threadUuid): ?ChatThread
    {
        return $this->chatThread->where('uuid', $threadUuid)->first();
    }

    /**
     * Resolve the chat thread for an inquiry, creating it on first access.
     */
    public function firstOrCreateThreadForInquiry(VenueInquiry $inquiry): ChatThread
    {
        $existing = $this->findThreadByInquiry($inquiry->uuid);

        if ($existing) {
            return $existing;
        }

        $inquiry->loadMissing('venueListing:uuid,organization_uuid');

        return $this->chatThread->create([
            'venue_inquiry_uuid' => $inquiry->uuid,
            'venue_listing_uuid' => $inquiry->venue_listing_uuid,
            'organization_uuid' => $inquiry->venueListing?->organization_uuid,
            'customer_uuid' => $inquiry->user_uuid,
        ]);
    }

    /**
     * @return Collection<int, ChatMessage>
     */
    public function getMessages(string $threadUuid): Collection
    {
        return $this->chatMessage
            ->with('attachment')
            ->where('chat_thread_uuid', $threadUuid)
            ->orderBy('created_at')
            ->orderBy('uuid')
            ->get();
    }

    public function createMessage(array $payload): ChatMessage
    {
        return $this->chatMessage->create($payload);
    }

    public function touchLastMessage(ChatThread $thread, ChatMessage $message): void
    {
        $preview = match ($message->message_type) {
            ChatMessage::TYPE_SCHEDULE_CARD => '📅 Site visit scheduled',
            ChatMessage::TYPE_VISIT_ACCEPTED_CARD => '✅ Site visit accepted',
            ChatMessage::TYPE_VISIT_DECLINED_CARD => '❌ Site visit declined',
            ChatMessage::TYPE_VISIT_SUGGESTED_CARD => '📅 Alternative visit date suggested',
            ChatMessage::TYPE_PROPOSAL_CARD => '📄 Proposal sent',
            ChatMessage::TYPE_APPROVAL_CARD,
            ChatMessage::TYPE_DEPOSIT_REQUESTED_CARD => '✅ Deposit requested',
            ChatMessage::TYPE_DEPOSIT_PAID_CARD => '💳 Deposit received',
            ChatMessage::TYPE_BALANCE_DUE_CARD => '📋 Final billing sent',
            ChatMessage::TYPE_CONFIRMATION_CARD,
            ChatMessage::TYPE_FULLY_PAID_CARD => '🎉 Booking fully paid',
            default => trim((string) $message->body) !== ''
                ? mb_substr($message->body, 0, 160)
                : ($message->attachment_upload_uuid ? '📎 Attachment' : ''),
        };

        $thread->forceFill([
            'last_message_preview' => $preview,
            'last_message_at' => $message->created_at,
        ])->save();
    }

    public function markRead(ChatThread $thread, string $side): void
    {
        $column = $side === ChatThread::SENDER_CUSTOMER
            ? 'customer_last_read_at'
            : 'merchant_last_read_at';

        $thread->forceFill([$column => now()])->save();
    }

    /**
     * Return unread message counts scoped to all threads owned by a customer.
     *
     * Uses a single join query to avoid N+1 across many threads.
     *
     * @return BaseCollection<int, object{venue_inquiry_uuid:string, thread_uuid:string, unread_count:int}>
     */
    public function getUnreadSummaryForCustomer(string $customerUuid): BaseCollection
    {
        return DB::table('chat_messages')
            ->join('chat_threads', 'chat_messages.chat_thread_uuid', '=', 'chat_threads.uuid')
            ->where('chat_threads.customer_uuid', $customerUuid)
            ->where('chat_messages.sender_type', ChatThread::SENDER_MERCHANT)
            ->where(function ($query) {
                $query->whereNull('chat_threads.customer_last_read_at')
                    ->orWhereColumn('chat_messages.created_at', '>', 'chat_threads.customer_last_read_at');
            })
            ->select('chat_threads.venue_inquiry_uuid', 'chat_threads.uuid as thread_uuid')
            ->selectRaw('COUNT(*) as unread_count')
            ->groupBy('chat_threads.uuid', 'chat_threads.venue_inquiry_uuid')
            ->having('unread_count', '>', 0)
            ->get();
    }

    /**
     * Return unread message counts scoped to all threads for a merchant org
     * (or every thread when $orgUuid is null — platform admin).
     *
     * @return BaseCollection<int, object{venue_inquiry_uuid:string, thread_uuid:string, unread_count:int}>
     */
    public function getUnreadSummaryForMerchant(?string $orgUuid): BaseCollection
    {
        $query = DB::table('chat_messages')
            ->join('chat_threads', 'chat_messages.chat_thread_uuid', '=', 'chat_threads.uuid')
            ->where('chat_messages.sender_type', ChatThread::SENDER_CUSTOMER)
            ->where(function ($q) {
                $q->whereNull('chat_threads.merchant_last_read_at')
                    ->orWhereColumn('chat_messages.created_at', '>', 'chat_threads.merchant_last_read_at');
            });

        if ($orgUuid !== null) {
            $query->where('chat_threads.organization_uuid', $orgUuid);
        }

        return $query
            ->select('chat_threads.venue_inquiry_uuid', 'chat_threads.uuid as thread_uuid')
            ->selectRaw('COUNT(*) as unread_count')
            ->groupBy('chat_threads.uuid', 'chat_threads.venue_inquiry_uuid')
            ->having('unread_count', '>', 0)
            ->get();
    }
}
