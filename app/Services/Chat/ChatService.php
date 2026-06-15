<?php

namespace App\Services\Chat;

use App\Events\ChatMessageSent;
use App\Http\Repositories\ChatRepository;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use Illuminate\Support\Facades\DB;

class ChatService
{
    public function __construct(
        protected ChatRepository $chatRepository,
        protected ContactInfoFilter $contactInfoFilter,
    ) {
    }

    /**
     * Persist a new message, update the thread bookkeeping, mark the sender's
     * own side as read, and broadcast it to the private channel.
     *
     * Email addresses, phone numbers and links in the body are masked to keep
     * the conversation on-platform. An optional attachment (Upload uuid) may be
     * supplied; in that case the body may be empty.
     */
    public function sendMessage(
        ChatThread $thread,
        string $senderType,
        ?string $senderUuid,
        ?string $senderName,
        ?string $body,
        ?string $attachmentUploadUuid = null,
        ?string $attachmentName = null,
        string $messageType = ChatMessage::TYPE_TEXT,
        ?array $metadata = null,
    ): ChatMessage {
        // Only conversational text is masked. Structured/system messages (e.g.
        // schedule cards) carry trusted, platform-generated content.
        $resolvedBody = $messageType === ChatMessage::TYPE_TEXT
            ? $this->contactInfoFilter->mask((string) $body)
            : (string) $body;

        $message = DB::transaction(function () use (
            $thread,
            $senderType,
            $senderUuid,
            $senderName,
            $resolvedBody,
            $attachmentUploadUuid,
            $attachmentName,
            $messageType,
            $metadata,
        ) {
            $message = $this->chatRepository->createMessage([
                'chat_thread_uuid' => $thread->uuid,
                'sender_type' => $senderType,
                'sender_uuid' => $senderUuid,
                'sender_name' => $senderName,
                'message_type' => $messageType,
                'body' => $resolvedBody,
                'attachment_upload_uuid' => $attachmentUploadUuid,
                'attachment_name' => $attachmentName,
                'metadata' => $metadata,
            ]);

            $this->chatRepository->touchLastMessage($thread, $message);
            // The sender has implicitly read up to their own message.
            $this->chatRepository->markRead($thread, $senderType);

            return $message;
        });

        broadcast(new ChatMessageSent($message))->toOthers();

        return $message;
    }
}
