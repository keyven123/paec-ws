<?php

namespace App\Http\Controllers;

use App\Http\Repositories\ChatRepository;
use App\Http\Repositories\UploadRepository;
use App\Http\Repositories\VenueInquiryRepository;
use App\Http\Requests\Chat\SendMerchantChatMessageRequest;
use App\Http\Resources\ChatMessageResource;
use App\Http\Resources\ChatThreadResource;
use App\Models\ChatThread;
use App\Models\VenueInquiry;
use App\Services\Chat\ChatService;
use App\Services\VenueInquiryWorkflowService;
use Illuminate\Http\JsonResponse;

class ChatController extends Controller
{
    public function __construct(
        protected VenueInquiryRepository $venueInquiryRepository,
        protected ChatRepository $chatRepository,
        protected ChatService $chatService,
        protected UploadRepository $uploadRepository,
        protected VenueInquiryWorkflowService $workflowService,
    ) {
    }

    /**
     * Return the chat thread and its messages for an inquiry owned by the
     * merchant's organization, creating the thread on first access.
     */
    public function show(string $inquiryUuid): JsonResponse
    {
        $inquiry = $this->authorizeInquiry($inquiryUuid);

        $thread = $this->chatRepository->firstOrCreateThreadForInquiry($inquiry);
        $this->chatRepository->markRead($thread, ChatThread::SENDER_MERCHANT);

        return response()->json([
            'thread' => (new ChatThreadResource($thread->fresh()))
                ->forSide(ChatThread::SENDER_MERCHANT),
            'messages' => ChatMessageResource::collection(
                $this->chatRepository->getMessages($thread->uuid)
            ),
        ]);
    }

    public function sendMessage(SendMerchantChatMessageRequest $request, string $inquiryUuid): JsonResponse
    {
        $inquiry = $this->authorizeInquiry($inquiryUuid);
        $admin = auth('admin')->user();

        $thread = $this->chatRepository->firstOrCreateThreadForInquiry($inquiry);

        $attachmentUuid = null;
        $attachmentName = null;

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $upload = $this->uploadRepository->create([
                'file' => $file,
                'collection' => 'attachment',
            ]);
            $attachmentUuid = $upload->uuid;
            $attachmentName = $file->getClientOriginalName();
        }

        $message = $this->chatService->sendMessage(
            $thread,
            ChatThread::SENDER_MERCHANT,
            $admin->uuid,
            $admin->full_name ?: $admin->email,
            $request->validated()['body'] ?? null,
            $attachmentUuid,
            $attachmentName,
        );

        $inquiry = $this->workflowService->onMerchantFirstMessage($inquiry);

        $validated = $request->validated();
        $sendAsProposal = (bool) ($validated['send_as_proposal'] ?? false);

        if ($attachmentUuid !== null && (
            $sendAsProposal
            || in_array($inquiry->status, [
                VenueInquiry::STATUSES['IN_DISCUSSION'],
                VenueInquiry::STATUSES['SITE_VISIT_SCHEDULED'],
            ], true)
        )) {
            $this->workflowService->sendProposal($inquiry->fresh(), [
                'proposal_amount' => $validated['proposal_amount'] ?? $inquiry->proposal_amount ?? 0,
                'proposal_valid_until' => $validated['proposal_valid_until']
                    ?? now()->addDays(14)->toDateString(),
                'proposal_upload_uuid' => $attachmentUuid,
                'attachment_name' => $attachmentName,
            ]);
        }

        return response()->json([
            'message' => new ChatMessageResource($message->load('attachment')),
        ], 201);
    }

    public function markRead(string $threadUuid): JsonResponse
    {
        $thread = $this->chatRepository->findThread($threadUuid);
        $admin = auth('admin')->user();

        if (!$thread || !$this->adminCanAccessOrganization($admin->organization_uuid, $thread->organization_uuid)) {
            return response()->json(['message' => 'Chat thread not found.'], 404);
        }

        $this->chatRepository->markRead($thread, ChatThread::SENDER_MERCHANT);

        return response()->json(['success' => true]);
    }

    /**
     * Return a lightweight summary of unread counts for all threads visible to
     * this merchant (or scoped to a specific venue listing via ?venue_listing_uuid=…).
     * Used by the venue dashboard to render notification badges per inquiry.
     */
    public function unreadSummary(): JsonResponse
    {
        $admin = auth('admin')->user();
        $orgUuid = $admin->organization_uuid;

        $summary = $this->chatRepository->getUnreadSummaryForMerchant($orgUuid);

        return response()->json(['data' => $summary]);
    }

    /**
     * Ensure the inquiry exists and belongs to the merchant's organization.
     * Platform admins (no organization) may access any inquiry.
     */
    protected function authorizeInquiry(string $inquiryUuid): VenueInquiry
    {
        $inquiry = $this->venueInquiryRepository->fetchOrThrow($inquiryUuid);
        $inquiry->loadMissing('venueListing:uuid,organization_uuid');

        $admin = auth('admin')->user();

        if (!$this->adminCanAccessOrganization($admin->organization_uuid, $inquiry->venueListing?->organization_uuid)) {
            abort(403, 'You do not have access to this conversation.');
        }

        return $inquiry;
    }

    protected function adminCanAccessOrganization(?string $adminOrganizationUuid, ?string $resourceOrganizationUuid): bool
    {
        // Platform admins have no organization and may access everything.
        if ($adminOrganizationUuid === null) {
            return true;
        }

        return $resourceOrganizationUuid !== null
            && $resourceOrganizationUuid === $adminOrganizationUuid;
    }
}
