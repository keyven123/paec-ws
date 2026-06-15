<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Repositories\ChatRepository;
use App\Http\Repositories\VenueInquiryRepository;
use App\Http\Requests\Chat\SendChatMessageRequest;
use App\Http\Resources\ChatMessageResource;
use App\Http\Resources\ChatThreadResource;
use App\Models\ChatThread;
use App\Models\VenueInquiry;
use App\Services\Chat\ChatService;
use Illuminate\Http\JsonResponse;

class ChatController extends Controller
{
    public function __construct(
        protected VenueInquiryRepository $venueInquiryRepository,
        protected ChatRepository $chatRepository,
        protected ChatService $chatService,
    ) {
    }

    /**
     * Return the chat thread and its messages for one of the customer's
     * inquiries, creating the thread on first access. Marks the thread read.
     */
    public function show(string $inquiryUuid): JsonResponse
    {
        $inquiry = $this->authorizeInquiry($inquiryUuid);

        $thread = $this->chatRepository->firstOrCreateThreadForInquiry($inquiry);
        $this->chatRepository->markRead($thread, ChatThread::SENDER_CUSTOMER);

        return response()->json([
            'thread' => (new ChatThreadResource($thread->fresh()))
                ->forSide(ChatThread::SENDER_CUSTOMER),
            'messages' => ChatMessageResource::collection(
                $this->chatRepository->getMessages($thread->uuid)
            ),
        ]);
    }

    public function sendMessage(SendChatMessageRequest $request, string $inquiryUuid): JsonResponse
    {
        $inquiry = $this->authorizeInquiry($inquiryUuid);
        $user = $request->user();

        $thread = $this->chatRepository->firstOrCreateThreadForInquiry($inquiry);

        $message = $this->chatService->sendMessage(
            $thread,
            ChatThread::SENDER_CUSTOMER,
            $user->uuid,
            $user->full_name ?: $user->email,
            $request->validated()['body'],
        );

        return response()->json([
            'message' => new ChatMessageResource($message),
        ], 201);
    }

    public function markRead(string $threadUuid): JsonResponse
    {
        $thread = $this->chatRepository->findThread($threadUuid);

        if (!$thread || $thread->customer_uuid !== request()->user()->uuid) {
            return response()->json(['message' => 'Chat thread not found.'], 404);
        }

        $this->chatRepository->markRead($thread, ChatThread::SENDER_CUSTOMER);

        return response()->json(['success' => true]);
    }

    /**
     * Return a lightweight summary of unread counts for all the customer's
     * chat threads. Used by the inquiries list page to render notification badges.
     */
    public function unreadSummary(): JsonResponse
    {
        $user = request()->user();
        $summary = $this->chatRepository->getUnreadSummaryForCustomer($user->uuid);

        return response()->json(['data' => $summary]);
    }

    /**
     * Ensure the inquiry exists and belongs to the authenticated customer.
     */
    protected function authorizeInquiry(string $inquiryUuid): VenueInquiry
    {
        $inquiry = $this->venueInquiryRepository->fetchOrThrow($inquiryUuid);
        $user = request()->user();

        if ($inquiry->user_uuid === null || $inquiry->user_uuid !== $user->uuid) {
            abort(403, 'You do not have access to this conversation.');
        }

        return $inquiry;
    }
}
