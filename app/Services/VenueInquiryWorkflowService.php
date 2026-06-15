<?php

namespace App\Services;

use App\Http\Repositories\ChatRepository;
use App\Http\Resources\VenueInquiryResource;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Transaction;
use App\Models\VenueInquiry;
use App\Models\VenueListing;
use App\Notifications\VenueBookingConfirmedNotification;
use App\Notifications\VenueBookingUnavailableNotification;
use App\Notifications\VenueBalancePaidNotification;
use App\Notifications\VenueDepositPaidNotification;
use App\Notifications\VenueEventCompletedCustomerNotification;
use App\Notifications\VenueEventCompletedMerchantNotification;
use App\Notifications\VenueProposalAcceptedNotification;
use App\Notifications\VenueProposalDeclinedNotification;
use App\Notifications\VenueProposalSentNotification;
use App\Notifications\VenueVisitScheduledNotification;
use App\Notifications\VenueVisitCustomerResponseNotification;
use App\Services\Chat\ChatService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class VenueInquiryWorkflowService
{
    /** @var array<string, list<string>> */
    private const ALLOWED_TRANSITIONS = [
        'new' => ['in_discussion', 'site_visit_scheduled', 'cancelled'],
        'in_discussion' => ['site_visit_scheduled', 'proposal_sent', 'cancelled'],
        'site_visit_scheduled' => ['proposal_sent', 'in_discussion', 'cancelled'],
        'proposal_sent' => ['accepted', 'in_discussion', 'cancelled'],
        'accepted' => ['deposit_requested', 'cancelled'],
        'deposit_requested' => ['deposit_paid', 'cancelled'],
        'deposit_paid' => ['balance_due', 'cancelled'],
        'balance_due' => ['fully_paid', 'cancelled'],
        'fully_paid' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    public function __construct(
        protected NotificationService $notificationService,
        protected ChatService $chatService,
        protected ChatRepository $chatRepository,
    ) {
    }

    public function canTransition(VenueInquiry $inquiry, string $toStatus): bool
    {
        $from = $inquiry->status;
        $allowed = self::ALLOWED_TRANSITIONS[$from] ?? [];

        return in_array($toStatus, $allowed, true);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function transition(VenueInquiry $inquiry, string $toStatus, array $context = []): VenueInquiry
    {
        if (! $this->canTransition($inquiry, $toStatus)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot move inquiry from \"{$inquiry->status}\" to \"{$toStatus}\"."],
            ]);
        }

        $updates = ['status' => $toStatus];
        $now = now();

        match ($toStatus) {
            VenueInquiry::STATUSES['PROPOSAL_SENT'] => $updates['proposal_sent_at'] = $now,
            VenueInquiry::STATUSES['ACCEPTED'] => $updates['accepted_at'] = $now,
            VenueInquiry::STATUSES['DEPOSIT_PAID'] => $updates['deposit_paid_at'] = $now,
            VenueInquiry::STATUSES['FULLY_PAID'] => $updates['fully_paid_at'] = $now,
            VenueInquiry::STATUSES['COMPLETED'] => $updates['completed_at'] = $now,
            default => null,
        };

        if (! empty($context['extra_updates']) && is_array($context['extra_updates'])) {
            $updates = array_merge($updates, $context['extra_updates']);
        }

        $inquiry->update($updates);
        $inquiry = $inquiry->fresh();
        $inquiry->loadMissing('venueListing', 'user');

        $this->runSideEffects($inquiry, $toStatus, $context);

        return $inquiry;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function runSideEffects(VenueInquiry $inquiry, string $toStatus, array $context): void
    {
        match ($toStatus) {
            VenueInquiry::STATUSES['SITE_VISIT_SCHEDULED'] => $this->afterVisitScheduled(
                $inquiry,
                (bool) ($context['is_reschedule'] ?? false),
            ),
            VenueInquiry::STATUSES['PROPOSAL_SENT'] => $this->afterProposalSent($inquiry, $context),
            VenueInquiry::STATUSES['ACCEPTED'] => $this->afterProposalAccepted($inquiry),
            VenueInquiry::STATUSES['DEPOSIT_REQUESTED'] => $this->afterDepositRequested($inquiry),
            VenueInquiry::STATUSES['DEPOSIT_PAID'] => $this->afterDepositPaid(
                $inquiry,
                $context['transaction'] ?? null,
            ),
            VenueInquiry::STATUSES['BALANCE_DUE'] => $this->afterBalanceDue($inquiry),
            VenueInquiry::STATUSES['FULLY_PAID'] => $this->afterFullyPaid(
                $inquiry,
                $context['transaction'] ?? null,
            ),
            VenueInquiry::STATUSES['COMPLETED'] => $this->afterCompleted($inquiry),
            default => null,
        };
    }

    public function onMerchantFirstMessage(VenueInquiry $inquiry): VenueInquiry
    {
        if ($inquiry->status !== VenueInquiry::STATUSES['NEW']) {
            return $inquiry;
        }

        return $this->transition($inquiry, VenueInquiry::STATUSES['IN_DISCUSSION']);
    }

    /**
     * @param  array<string, mixed>  $proposalData
     */
    public function sendProposal(VenueInquiry $inquiry, array $proposalData): VenueInquiry
    {
        $allowedFrom = [
            VenueInquiry::STATUSES['IN_DISCUSSION'],
            VenueInquiry::STATUSES['SITE_VISIT_SCHEDULED'],
        ];

        if (! in_array($inquiry->status, $allowedFrom, true)) {
            throw ValidationException::withMessages([
                'inquiry' => ['A proposal can only be sent while the inquiry is in discussion or after a site visit.'],
            ]);
        }

        $extra = [
            'proposal_amount' => $proposalData['proposal_amount'] ?? null,
            'proposal_valid_until' => $proposalData['proposal_valid_until'] ?? null,
            'proposal_upload_uuid' => $proposalData['proposal_upload_uuid'] ?? null,
        ];

        if ($inquiry->status === VenueInquiry::STATUSES['PROPOSAL_SENT']) {
            $inquiry->update(array_merge($extra, ['proposal_sent_at' => now()]));
            $inquiry = $inquiry->fresh();
            $this->afterProposalSent($inquiry, $proposalData);

            return $inquiry;
        }

        return $this->transition($inquiry, VenueInquiry::STATUSES['PROPOSAL_SENT'], [
            'extra_updates' => $extra,
            ...$proposalData,
        ]);
    }

    public function scheduleVisit(VenueInquiry $inquiry, array $visitData, bool $isReschedule): VenueInquiry
    {
        if ($inquiry->site_visit !== VenueInquiry::SITE_VISIT_YES) {
            throw ValidationException::withMessages([
                'visit_scheduled_date' => ['Visit schedule can only be set for site visit inquiries.'],
            ]);
        }

        $inquiry->update($visitData);

        if ($inquiry->status === VenueInquiry::STATUSES['SITE_VISIT_SCHEDULED']) {
            $inquiry = $inquiry->fresh();
            $this->afterVisitScheduled($inquiry, $isReschedule);

            return $inquiry;
        }

        $from = $inquiry->status;
        if (! in_array($from, [VenueInquiry::STATUSES['NEW'], VenueInquiry::STATUSES['IN_DISCUSSION']], true)) {
            if (! $this->canTransition($inquiry->fresh(), VenueInquiry::STATUSES['SITE_VISIT_SCHEDULED'])) {
                throw ValidationException::withMessages([
                    'visit_scheduled_date' => ['Cannot schedule a visit at this stage.'],
                ]);
            }
        }

        if ($from === VenueInquiry::STATUSES['NEW']) {
            $inquiry->update(['status' => VenueInquiry::STATUSES['IN_DISCUSSION']]);
        }

        return $this->transition($inquiry->fresh(), VenueInquiry::STATUSES['SITE_VISIT_SCHEDULED'], [
            'is_reschedule' => $isReschedule,
        ]);
    }

    public function acceptProposal(VenueInquiry $inquiry): VenueInquiry
    {
        return $this->transition($inquiry, VenueInquiry::STATUSES['ACCEPTED']);
    }

    public function declineProposal(VenueInquiry $inquiry): VenueInquiry
    {
        if ($inquiry->status !== VenueInquiry::STATUSES['PROPOSAL_SENT']) {
            throw ValidationException::withMessages([
                'inquiry' => ['Only a sent proposal can be declined.'],
            ]);
        }

        $inquiry->update(['status' => VenueInquiry::STATUSES['IN_DISCUSSION']]);
        $this->afterProposalDeclined($inquiry->fresh());

        return $inquiry->fresh();
    }

    public function acceptVisitSchedule(VenueInquiry $inquiry): VenueInquiry
    {
        if ($inquiry->status !== VenueInquiry::STATUSES['SITE_VISIT_SCHEDULED']) {
            throw ValidationException::withMessages([
                'inquiry' => ['Only a scheduled site visit can be accepted.'],
            ]);
        }

        $this->afterVisitAccepted($inquiry->fresh());

        return $inquiry->fresh();
    }

    public function declineVisitSchedule(VenueInquiry $inquiry): VenueInquiry
    {
        if ($inquiry->status !== VenueInquiry::STATUSES['SITE_VISIT_SCHEDULED']) {
            throw ValidationException::withMessages([
                'inquiry' => ['Only a scheduled site visit can be declined.'],
            ]);
        }

        $scheduledLabel = $this->visitScheduleLabel($inquiry);
        $this->resetVisitSchedule($inquiry);
        $inquiry = $this->transition($inquiry->fresh(), VenueInquiry::STATUSES['IN_DISCUSSION']);
        $this->afterVisitDeclined($inquiry, $scheduledLabel);

        return $inquiry;
    }

    public function suggestVisitDate(
        VenueInquiry $inquiry,
        string $suggestedDate,
        string $suggestedTime,
    ): VenueInquiry {
        if ($inquiry->status !== VenueInquiry::STATUSES['SITE_VISIT_SCHEDULED']) {
            throw ValidationException::withMessages([
                'inquiry' => ['Only a scheduled site visit can receive a suggested alternative date.'],
            ]);
        }

        $scheduledLabel = $this->visitScheduleLabel($inquiry);
        $this->resetVisitSchedule($inquiry);
        $inquiry = $this->transition($inquiry->fresh(), VenueInquiry::STATUSES['IN_DISCUSSION']);
        $this->afterVisitDateSuggested($inquiry, $suggestedDate, $suggestedTime, $scheduledLabel);

        return $inquiry;
    }

    public function requestDeposit(VenueInquiry $inquiry, float $amount, string $dueDate): VenueInquiry
    {
        if ($inquiry->status === VenueInquiry::STATUSES['DEPOSIT_REQUESTED']) {
            $inquiry->update([
                'deposit_amount' => $amount,
                'deposit_due_date' => $dueDate,
                'approved_amount' => $amount,
                'approved_due_date' => $dueDate,
            ]);
            $inquiry = $inquiry->fresh();
            $this->afterDepositRequested($inquiry);

            return $inquiry;
        }

        return $this->transition($inquiry, VenueInquiry::STATUSES['DEPOSIT_REQUESTED'], [
            'extra_updates' => [
                'deposit_amount' => $amount,
                'deposit_due_date' => $dueDate,
                'approved_amount' => $amount,
                'approved_due_date' => $dueDate,
            ],
        ]);
    }

    public function sendFinalBilling(
        VenueInquiry $inquiry,
        float $balanceAmount,
        string $dueDate,
        float $additionalCharges = 0,
    ): VenueInquiry {
        $updates = [
            'balance_amount' => $balanceAmount,
            'balance_due_date' => $dueDate,
            'additional_charges' => $additionalCharges,
        ];

        if ($inquiry->status === VenueInquiry::STATUSES['BALANCE_DUE']) {
            $inquiry->update($updates);
            $inquiry = $inquiry->fresh();
            $this->afterBalanceDue($inquiry);

            return $inquiry;
        }

        return $this->transition($inquiry, VenueInquiry::STATUSES['BALANCE_DUE'], [
            'extra_updates' => $updates,
        ]);
    }

    public function markCompleted(VenueInquiry $inquiry): VenueInquiry
    {
        return $this->transition($inquiry, VenueInquiry::STATUSES['COMPLETED']);
    }

    public function cancel(VenueInquiry $inquiry): VenueInquiry
    {
        if ($inquiry->status === VenueInquiry::STATUSES['CANCELLED']) {
            return $inquiry;
        }

        if (! $this->canTransition($inquiry, VenueInquiry::STATUSES['CANCELLED'])) {
            throw ValidationException::withMessages([
                'status' => ['This inquiry cannot be cancelled at its current stage.'],
            ]);
        }

        return $this->transition($inquiry, VenueInquiry::STATUSES['CANCELLED']);
    }

    public function handleDepositPaid(VenueInquiry $inquiry, Transaction $transaction): VenueInquiry
    {
        $inquiry = $this->transition($inquiry, VenueInquiry::STATUSES['DEPOSIT_PAID'], [
            'transaction' => $transaction,
        ]);

        $this->cancelConflictingInquiries($inquiry);

        return $inquiry;
    }

    public function handleFullyPaid(VenueInquiry $inquiry, Transaction $transaction): VenueInquiry
    {
        return $this->transition($inquiry, VenueInquiry::STATUSES['FULLY_PAID'], [
            'transaction' => $transaction,
        ]);
    }

    private function afterVisitScheduled(VenueInquiry $inquiry, bool $isReschedule): void
    {
        $venue = $inquiry->venueListing;
        $venueName = $venue?->name ?? 'the venue';
        $visitTime = $inquiry->visit_scheduled_time
            ? substr((string) $inquiry->visit_scheduled_time, 0, 5)
            : null;
        $visitLabel = VenueInquiryResource::visitScheduledLabel(
            $inquiry->visit_scheduled_date?->format('Y-m-d'),
            $visitTime,
        ) ?? $inquiry->visit_scheduled_date?->format('F d, Y');

        if (! empty($inquiry->email)) {
            if ($inquiry->user !== null) {
                $inquiry->user->notify(new VenueVisitScheduledNotification($inquiry->uuid));
            } else {
                Notification::route('mail', $inquiry->email)
                    ->notify(new VenueVisitScheduledNotification($inquiry->uuid));
            }
        }

        if ($inquiry->user !== null) {
            $title = $isReschedule
                ? VenueInquiry::customerStatusLabel(VenueInquiry::STATUSES['SITE_VISIT_SCHEDULED']) . ' (Updated)'
                : VenueInquiry::customerStatusLabel(VenueInquiry::STATUSES['SITE_VISIT_SCHEDULED']);
            $verb = $isReschedule ? 'has been moved to' : 'is set for';
            $this->notifyCustomer(
                $inquiry,
                'visit_scheduled',
                $title,
                "Your site visit for \"{$venueName}\" {$verb} {$visitLabel}.",
            );
        }

        $this->sendScheduleCard($inquiry, $venue, $visitLabel, $visitTime, $isReschedule);
    }

    /** @param array<string, mixed> $context */
    private function afterProposalSent(VenueInquiry $inquiry, array $context): void
    {
        $venue = $inquiry->venueListing;
        $venueName = $venue?->name ?? 'the venue';
        $amount = $inquiry->proposal_amount;
        $amountLabel = $amount !== null ? '₱' . number_format((float) $amount, 2) : null;
        $validUntil = $inquiry->proposal_valid_until?->format('F d, Y');

        $this->notifyCustomer(
            $inquiry,
            'proposal_sent',
            VenueInquiry::customerStatusLabel(VenueInquiry::STATUSES['PROPOSAL_SENT']),
            "A proposal for \"{$venueName}\" is ready for your review.",
        );

        $body = "We've sent you a proposal for {$venueName}. "
            . "Please review the details below and let us know if you'd like to accept it.";

        $uploadUuid = $context['proposal_upload_uuid'] ?? $inquiry->proposal_upload_uuid;
        $attachmentName = $context['attachment_name'] ?? null;

        if (! empty($inquiry->email)) {
            if ($inquiry->user !== null) {
                $inquiry->user->notify(new VenueProposalSentNotification($inquiry->uuid));
            } else {
                Notification::route('mail', $inquiry->email)
                    ->notify(new VenueProposalSentNotification($inquiry->uuid));
            }
        }

        $this->sendChatCard(
            $inquiry,
            ChatMessage::TYPE_PROPOSAL_CARD,
            $body,
            [
                'venue_name' => $venueName,
                'venue_address' => $this->venueAddress($venue),
                'event_date' => $inquiry->event_date?->format('Y-m-d'),
                'event_label' => $inquiry->event_date?->format('F d, Y'),
                'proposal_amount' => $amount !== null ? (float) $amount : null,
                'proposal_amount_label' => $amountLabel,
                'proposal_valid_until' => $inquiry->proposal_valid_until?->format('Y-m-d'),
                'proposal_valid_until_label' => $validUntil,
                'proposal_upload_uuid' => $uploadUuid,
                'attachment_name' => $attachmentName,
                'inquiry_uuid' => $inquiry->uuid,
            ],
            is_string($uploadUuid) ? $uploadUuid : null,
            $attachmentName,
        );
    }

    private function afterProposalAccepted(VenueInquiry $inquiry): void
    {
        $inquiry->loadMissing(['venueListing.organization', 'user']);
        $venue = $inquiry->venueListing;
        $venueName = $venue?->name ?? 'the venue';
        $customerName = $inquiry->full_name ?? 'The customer';
        $amount = $inquiry->proposal_amount;
        $amountLabel = $amount !== null ? '₱' . number_format((float) $amount, 2) : null;

        $this->notifyCustomer(
            $inquiry,
            'proposal_accepted',
            VenueInquiry::customerStatusLabel(VenueInquiry::STATUSES['ACCEPTED']),
            "Thank you! Your acceptance for \"{$venueName}\" has been recorded. The venue will send a deposit request next.",
        );

        $organization = $venue?->organization;
        $venueUrl = $venue
            ? '/general-admin/venues/' . $venue->uuid . '?openInquiry=' . $inquiry->uuid
            : '/general-admin/venues';

        if ($organization !== null) {
            $this->notificationService->sendToOrganization(
                $organization->uuid,
                'proposal_accepted',
                'Proposal accepted',
                "{$customerName} accepted your proposal for \"{$venueName}\".",
                $venueUrl,
                [
                    'inquiry_uuid' => $inquiry->uuid,
                    'venue_listing_uuid' => $venue->uuid,
                ],
            );

            if (! empty($organization->email)) {
                $organization->notify(new VenueProposalAcceptedNotification($inquiry->uuid));
            }
        }

        $this->sendCustomerChatText($inquiry, 'Accept Proposal');
    }

    private function afterProposalDeclined(VenueInquiry $inquiry): void
    {
        $inquiry->loadMissing(['venueListing.organization', 'user']);
        $venue = $inquiry->venueListing;
        $venueName = $venue?->name ?? 'the venue';
        $customerName = $inquiry->full_name ?? 'The customer';
        $amount = $inquiry->proposal_amount;
        $amountLabel = $amount !== null ? '₱' . number_format((float) $amount, 2) : null;

        $organization = $venue?->organization;
        $venueUrl = $venue
            ? '/general-admin/venues/' . $venue->uuid . '?openInquiry=' . $inquiry->uuid
            : '/general-admin/venues';

        if ($organization !== null) {
            $this->notificationService->sendToOrganization(
                $organization->uuid,
                'proposal_declined',
                'Proposal declined',
                "{$customerName} declined your proposal for \"{$venueName}\".",
                $venueUrl,
                [
                    'inquiry_uuid' => $inquiry->uuid,
                    'venue_listing_uuid' => $venue->uuid,
                ],
            );

            if (! empty($organization->email)) {
                $organization->notify(new VenueProposalDeclinedNotification($inquiry->uuid));
            }
        }

        $this->sendCustomerChatText($inquiry, 'Decline');

        $body = "{$customerName} has declined the proposal for {$venueName}. "
            . 'Send a revised proposal when ready, or continue the discussion in chat.';

        $this->sendChatCard(
            $inquiry,
            ChatMessage::TYPE_PROPOSAL_DECLINED_CARD,
            $body,
            [
                'venue_name' => $venueName,
                'venue_address' => $this->venueAddress($venue),
                'customer_name' => $customerName,
                'event_date' => $inquiry->event_date?->format('Y-m-d'),
                'event_label' => $inquiry->event_date?->format('F d, Y'),
                'proposal_amount' => $amount !== null ? (float) $amount : null,
                'proposal_amount_label' => $amountLabel,
                'inquiry_uuid' => $inquiry->uuid,
                'merchant_only' => true,
            ],
        );
    }

    private function afterDepositRequested(VenueInquiry $inquiry): void
    {
        $venue = $inquiry->venueListing;
        $venueName = $venue?->name ?? 'the venue';
        $dueDate = $inquiry->deposit_due_date?->format('F d, Y');
        $amount = $inquiry->deposit_amount;
        $amountLabel = $amount !== null ? '₱' . number_format((float) $amount, 2) : null;

        $duePhrase = $dueDate ? " on or before {$dueDate}" : ' soon';
        $this->notifyCustomer(
            $inquiry,
            'deposit_requested',
            VenueInquiry::customerStatusLabel(VenueInquiry::STATUSES['DEPOSIT_REQUESTED']),
            "Please pay your deposit for \"{$venueName}\"{$duePhrase} to reserve your date.",
        );

        $locationLabel = $this->locationLabel($venue);
        $eventLabel = $inquiry->event_date?->format('F d, Y');
        $datePhrase = $eventLabel ? " on {$eventLabel}" : '';
        $body = "Your booking for {$locationLabel}{$datePhrase} is approved! "
            . "Please pay the deposit{$duePhrase} to secure your date.";

        $this->sendChatCard(
            $inquiry,
            ChatMessage::TYPE_DEPOSIT_REQUESTED_CARD,
            $body,
            [
                'venue_name' => $venueName,
                'venue_address' => $this->venueAddress($venue),
                'event_date' => $inquiry->event_date?->format('Y-m-d'),
                'event_label' => $eventLabel,
                'deposit_amount' => $amount !== null ? (float) $amount : null,
                'deposit_amount_label' => $amountLabel,
                'due_date' => $inquiry->deposit_due_date?->format('Y-m-d'),
                'due_date_label' => $dueDate,
                'inquiry_uuid' => $inquiry->uuid,
            ],
        );
    }

    private function afterDepositPaid(VenueInquiry $inquiry, ?Transaction $transaction): void
    {
        $inquiry->loadMissing(['venueListing.organization', 'user']);
        $venue = $inquiry->venueListing;
        $venueName = $venue?->name ?? 'your venue';
        $customerName = $inquiry->full_name ?? 'The customer';
        $amount = $transaction?->total_amount ?? $inquiry->deposit_amount;
        $amountLabel = $amount !== null ? '₱' . number_format((float) $amount, 2) : null;

        $this->notifyCustomer(
            $inquiry,
            'deposit_paid',
            VenueInquiry::customerStatusLabel(VenueInquiry::STATUSES['DEPOSIT_PAID']),
            "Your deposit for \"{$venueName}\" is confirmed. Your date is tentatively reserved.",
        );

        $body = "Thank you! We've received your deposit for {$venueName}. "
            . 'Your event date is now tentatively reserved while we prepare your final billing.';

        $this->sendChatCard(
            $inquiry,
            ChatMessage::TYPE_DEPOSIT_PAID_CARD,
            $body,
            [
                'venue_name' => $venueName,
                'venue_address' => $this->venueAddress($venue),
                'event_date' => $inquiry->event_date?->format('Y-m-d'),
                'event_label' => $inquiry->event_date?->format('F d, Y'),
                'amount_paid' => $amount !== null ? (float) $amount : null,
                'amount_paid_label' => $amountLabel,
                'inquiry_uuid' => $inquiry->uuid,
            ],
        );

        $organization = $venue?->organization;
        $venueUrl = $venue
            ? '/general-admin/venues/' . $venue->uuid . '?openInquiry=' . $inquiry->uuid
            : '/general-admin/venues';

        if ($organization !== null) {
            $amountPhrase = $amountLabel ? " ({$amountLabel})" : '';
            $this->notificationService->sendToOrganization(
                $organization->uuid,
                'deposit_paid',
                'Deposit received',
                "{$customerName} paid the deposit for \"{$venueName}\"{$amountPhrase}. The event date is tentatively reserved.",
                $venueUrl,
                [
                    'inquiry_uuid' => $inquiry->uuid,
                    'venue_listing_uuid' => $venue->uuid,
                    'deposit_amount' => $amount !== null ? (float) $amount : null,
                ],
            );

            if (! empty($organization->email)) {
                $organization->notify(new VenueDepositPaidNotification($inquiry->uuid));
            }
        }
    }

    private function afterBalanceDue(VenueInquiry $inquiry): void
    {
        $venueName = $inquiry->venueListing?->name ?? 'the venue';
        $dueDate = $inquiry->balance_due_date?->format('F d, Y');
        $total = (float) $inquiry->balance_amount + (float) $inquiry->additional_charges;
        $amountLabel = '₱' . number_format($total, 2);

        $duePhrase = $dueDate ? " by {$dueDate}" : '';
        $this->notifyCustomer(
            $inquiry,
            'balance_due',
            VenueInquiry::customerStatusLabel(VenueInquiry::STATUSES['BALANCE_DUE']),
            "Your final balance for \"{$venueName}\" is due{$duePhrase}.",
        );

        $body = "Your final billing for {$venueName} is ready. "
            . "Please settle the remaining balance{$duePhrase} to complete your booking.";

        $this->sendChatCard(
            $inquiry,
            ChatMessage::TYPE_BALANCE_DUE_CARD,
            $body,
            [
                'venue_name' => $venueName,
                'venue_address' => $this->venueAddress($inquiry->venueListing),
                'event_date' => $inquiry->event_date?->format('Y-m-d'),
                'event_label' => $inquiry->event_date?->format('F d, Y'),
                'balance_amount' => (float) $inquiry->balance_amount,
                'balance_amount_label' => '₱' . number_format((float) $inquiry->balance_amount, 2),
                'additional_charges' => (float) $inquiry->additional_charges,
                'additional_charges_label' => (float) $inquiry->additional_charges > 0
                    ? '₱' . number_format((float) $inquiry->additional_charges, 2)
                    : null,
                'total_due' => $total,
                'total_due_label' => $amountLabel,
                'due_date' => $inquiry->balance_due_date?->format('Y-m-d'),
                'due_date_label' => $dueDate,
                'inquiry_uuid' => $inquiry->uuid,
            ],
        );
    }

    private function afterFullyPaid(VenueInquiry $inquiry, ?Transaction $transaction): void
    {
        try {
            $inquiry->loadMissing(['venueListing.organization', 'user']);
            $venue = $inquiry->venueListing;
            $venueName = $venue?->name ?? 'your venue';
            $customerName = $inquiry->full_name ?? 'The customer';

            if ($venue !== null) {
                $venue->increment('bookings_count');
            }

            if (! empty($inquiry->email)) {
                Notification::route('mail', $inquiry->email)
                    ->notify(new VenueBookingConfirmedNotification(
                        $inquiry->uuid,
                        $transaction?->uuid ?? '',
                    ));
            }

            $this->notifyCustomer(
                $inquiry,
                'fully_paid',
                VenueInquiry::customerStatusLabel(VenueInquiry::STATUSES['FULLY_PAID']),
                "Your booking for \"{$venueName}\" is fully paid. We can't wait for your event!",
            );

            $amount = $transaction?->total_amount
                ?? ((float) $inquiry->balance_amount + (float) $inquiry->additional_charges);
            $amountLabel = $amount !== null ? '₱' . number_format((float) $amount, 2) : null;
            $locationLabel = $this->locationLabel($venue);
            $eventLabel = $inquiry->event_date?->format('F d, Y');
            $datePhrase = $eventLabel ? " on {$eventLabel}" : '';

            $body = "Thank you for booking with us! Your payment is received and your booking "
                . "for {$locationLabel}{$datePhrase} is now fully confirmed. "
                . "We can't wait to host your event — reach us here anytime for arrangements.";

            $this->sendChatCard(
                $inquiry,
                ChatMessage::TYPE_FULLY_PAID_CARD,
                $body,
                [
                    'venue_name' => $venueName,
                    'venue_address' => $this->venueAddress($venue),
                    'event_date' => $inquiry->event_date?->format('Y-m-d'),
                    'event_label' => $eventLabel,
                    'amount_paid' => $amount !== null ? (float) $amount : null,
                    'amount_paid_label' => $amountLabel,
                    'inquiry_uuid' => $inquiry->uuid,
                ],
            );

            $organization = $venue?->organization;
            $venueUrl = $venue
                ? '/general-admin/venues/' . $venue->uuid . '?openInquiry=' . $inquiry->uuid
                : '/general-admin/venues';

            if ($organization !== null) {
                $amountPhrase = $amountLabel ? " ({$amountLabel})" : '';
                $this->notificationService->sendToOrganization(
                    $organization->uuid,
                    'fully_paid',
                    'Booking fully paid',
                    "{$customerName} paid the final balance for \"{$venueName}\"{$amountPhrase}. The booking is fully confirmed.",
                    $venueUrl,
                    [
                        'inquiry_uuid' => $inquiry->uuid,
                        'venue_listing_uuid' => $venue->uuid,
                        'balance_amount' => $amount !== null ? (float) $amount : null,
                    ],
                );

                if (! empty($organization->email)) {
                    $organization->notify(new VenueBalancePaidNotification($inquiry->uuid));
                }
            }
        } catch (\Throwable $e) {
            Log::error('Failed after fully paid workflow', [
                'inquiry_uuid' => $inquiry->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function afterCompleted(VenueInquiry $inquiry): void
    {
        $inquiry->loadMissing(['venueListing.organization', 'user']);
        $venue = $inquiry->venueListing;
        $venueName = $venue?->name ?? 'the venue';
        $customerName = $inquiry->full_name ?? 'The customer';

        $this->notifyCustomer(
            $inquiry,
            'event_completed',
            VenueInquiry::customerStatusLabel(VenueInquiry::STATUSES['COMPLETED']),
            "Congratulations! Your event at \"{$venueName}\" is complete. Thank you for trusting Ticketoc!",
        );

        if (! empty($inquiry->email)) {
            if ($inquiry->user !== null) {
                $inquiry->user->notify(new VenueEventCompletedCustomerNotification($inquiry->uuid));
            } else {
                Notification::route('mail', $inquiry->email)
                    ->notify(new VenueEventCompletedCustomerNotification($inquiry->uuid));
            }
        }

        $organization = $venue?->organization;
        $venueUrl = $venue
            ? '/general-admin/venues/' . $venue->uuid . '?openInquiry=' . $inquiry->uuid
            : '/general-admin/venues';

        if ($organization !== null) {
            $this->notificationService->sendToOrganization(
                $organization->uuid,
                'event_completed',
                'Event marked complete',
                "The event for \"{$venueName}\" with {$customerName} has been marked complete. Congratulations on another successful booking!",
                $venueUrl,
                [
                    'inquiry_uuid' => $inquiry->uuid,
                    'venue_listing_uuid' => $venue->uuid,
                ],
            );

            if (! empty($organization->email)) {
                $organization->notify(new VenueEventCompletedMerchantNotification($inquiry->uuid));
            }
        }
    }

    public function cancelConflictingInquiries(VenueInquiry $reserved): void
    {
        if ($reserved->event_date === null) {
            return;
        }

        $conflicts = VenueInquiry::query()
            ->where('venue_listing_uuid', $reserved->venue_listing_uuid)
            ->whereDate('event_date', $reserved->event_date->toDateString())
            ->where('uuid', '!=', $reserved->uuid)
            ->whereIn('status', VenueInquiry::OPEN_STATUSES)
            ->get();

        foreach ($conflicts as $conflict) {
            try {
                $conflict->update(['status' => VenueInquiry::STATUSES['CANCELLED']]);
                $this->notifyDateUnavailable($conflict);
            } catch (\Throwable $e) {
                Log::error('Failed to cancel conflicting venue inquiry', [
                    'inquiry_uuid' => $conflict->uuid,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function notifyDateUnavailable(VenueInquiry $inquiry): void
    {
        $inquiry->loadMissing('user', 'venueListing');

        if (! empty($inquiry->email)) {
            Notification::route('mail', $inquiry->email)
                ->notify(new VenueBookingUnavailableNotification($inquiry->uuid));
        }

        if ($inquiry->user !== null) {
            $venue = $inquiry->venueListing;
            $venueName = $venue?->name ?? 'the venue';
            $venueUrl = $venue?->slug ? "/venue/{$venue->slug}" : '/venue';

            $this->notificationService->send(
                $inquiry->user,
                'venue_date_unavailable',
                'A date is no longer available',
                "Sorry — your selected date for \"{$venueName}\" was just reserved by someone else. Tap to choose another date.",
                $venueUrl,
                [
                    'inquiry_uuid' => $inquiry->uuid,
                    'venue_listing_uuid' => $inquiry->venue_listing_uuid,
                ],
            );
        }
    }

    private function notifyCustomer(
        VenueInquiry $inquiry,
        string $type,
        string $title,
        string $body,
    ): void {
        if ($inquiry->user === null) {
            return;
        }

        $this->notificationService->send(
            $inquiry->user,
            $type,
            $title,
            $body,
            "/account/inquiries?openChat={$inquiry->uuid}",
            [
                'inquiry_uuid' => $inquiry->uuid,
                'venue_listing_uuid' => $inquiry->venue_listing_uuid,
            ],
        );
    }

    private function visitScheduleLabel(VenueInquiry $inquiry): ?string
    {
        $visitTime = $inquiry->visit_scheduled_time
            ? substr((string) $inquiry->visit_scheduled_time, 0, 5)
            : null;

        return VenueInquiryResource::visitScheduledLabel(
            $inquiry->visit_scheduled_date?->format('Y-m-d'),
            $visitTime,
        );
    }

    private function resetVisitSchedule(VenueInquiry $inquiry): void
    {
        $inquiry->update([
            'visit_scheduled_date' => null,
            'visit_scheduled_time' => null,
        ]);
    }

    private function afterVisitAccepted(VenueInquiry $inquiry): void
    {
        $inquiry->loadMissing(['venueListing.organization', 'user']);
        $venue = $inquiry->venueListing;
        $venueName = $venue?->name ?? 'the venue';
        $customerName = $inquiry->full_name ?? 'The customer';
        $visitLabel = $this->visitScheduleLabel($inquiry) ?? 'the scheduled date';

        $this->notifyMerchantVisitResponse(
            $inquiry,
            'visit_accepted',
            'Site visit accepted',
            "{$customerName} accepted the site visit for \"{$venueName}\" on {$visitLabel}.",
            VenueVisitCustomerResponseNotification::RESPONSE_ACCEPTED,
            scheduledVisitLabel: $visitLabel,
        );

        $this->sendCustomerChatText($inquiry, 'Accept visit');

        $body = "{$customerName} confirmed the site visit for {$venueName} on {$visitLabel}.";

        $this->sendChatCard(
            $inquiry,
            ChatMessage::TYPE_VISIT_ACCEPTED_CARD,
            $body,
            [
                'venue_name' => $venueName,
                'venue_address' => $this->venueAddress($venue),
                'customer_name' => $customerName,
                'visit_label' => $visitLabel,
                'visit_date' => $inquiry->visit_scheduled_date?->format('Y-m-d'),
                'visit_time' => $inquiry->visit_scheduled_time
                    ? substr((string) $inquiry->visit_scheduled_time, 0, 5)
                    : null,
                'inquiry_uuid' => $inquiry->uuid,
                'merchant_only' => true,
            ],
        );
    }

    private function afterVisitDeclined(VenueInquiry $inquiry, ?string $scheduledLabel): void
    {
        $inquiry->loadMissing(['venueListing.organization', 'user']);
        $venue = $inquiry->venueListing;
        $venueName = $venue?->name ?? 'the venue';
        $customerName = $inquiry->full_name ?? 'The customer';
        $visitLabel = $scheduledLabel ?? 'the scheduled visit';

        $this->notifyMerchantVisitResponse(
            $inquiry,
            'visit_declined',
            'Site visit declined',
            "{$customerName} declined the site visit for \"{$venueName}\" ({$visitLabel}).",
            VenueVisitCustomerResponseNotification::RESPONSE_DECLINED,
            scheduledVisitLabel: $visitLabel,
        );

        $this->sendCustomerChatText($inquiry, 'Decline visit');

        $body = "{$customerName} declined the site visit for {$venueName}. "
            . 'The inquiry is back in discussion — propose a new visit date when ready.';

        $this->sendChatCard(
            $inquiry,
            ChatMessage::TYPE_VISIT_DECLINED_CARD,
            $body,
            [
                'venue_name' => $venueName,
                'venue_address' => $this->venueAddress($venue),
                'customer_name' => $customerName,
                'visit_label' => $visitLabel,
                'inquiry_uuid' => $inquiry->uuid,
                'merchant_only' => true,
            ],
        );
    }

    private function afterVisitDateSuggested(
        VenueInquiry $inquiry,
        string $suggestedDate,
        string $suggestedTime,
        ?string $scheduledLabel,
    ): void {
        $inquiry->loadMissing(['venueListing.organization', 'user']);
        $venue = $inquiry->venueListing;
        $venueName = $venue?->name ?? 'the venue';
        $customerName = $inquiry->full_name ?? 'The customer';
        $visitLabel = $scheduledLabel ?? 'the scheduled visit';
        $suggestedDateLabel = \Carbon\Carbon::parse($suggestedDate)->format('l, F d, Y');
        $suggestedTimeLabel = \Carbon\Carbon::parse($suggestedTime)->format('g:i A');
        $suggestedLabel = VenueInquiryResource::visitScheduledLabel(
            $suggestedDateLabel,
            $suggestedTimeLabel,
        ) ?? $suggestedDateLabel;

        $this->notifyMerchantVisitResponse(
            $inquiry,
            'visit_date_suggested',
            'Alternative visit date suggested',
            "{$customerName} suggested {$suggestedLabel} instead of {$visitLabel} for \"{$venueName}\".",
            VenueVisitCustomerResponseNotification::RESPONSE_SUGGESTED,
            suggestedDate: $suggestedDate,
            suggestedTime: $suggestedTime,
            scheduledVisitLabel: $visitLabel,
        );

        $this->sendCustomerChatText($inquiry, 'Suggest visit date: ' . $suggestedLabel);

        $body = "{$customerName} suggested {$suggestedLabel} for the site visit at {$venueName}. "
            . 'The inquiry is back in discussion — send a new visit schedule when ready.';

        $this->sendChatCard(
            $inquiry,
            ChatMessage::TYPE_VISIT_SUGGESTED_CARD,
            $body,
            [
                'venue_name' => $venueName,
                'venue_address' => $this->venueAddress($venue),
                'customer_name' => $customerName,
                'visit_label' => $visitLabel,
                'suggested_date' => $suggestedDate,
                'suggested_time' => substr($suggestedTime, 0, 5),
                'suggested_date_label' => $suggestedDateLabel,
                'suggested_time_label' => $suggestedTimeLabel,
                'suggested_visit_label' => $suggestedLabel,
                'inquiry_uuid' => $inquiry->uuid,
                'merchant_only' => true,
            ],
        );
    }

    private function notifyMerchantVisitResponse(
        VenueInquiry $inquiry,
        string $type,
        string $title,
        string $body,
        string $emailResponse,
        ?string $suggestedDate = null,
        ?string $suggestedTime = null,
        ?string $scheduledVisitLabel = null,
    ): void {
        $venue = $inquiry->venueListing;
        $organization = $venue?->organization;
        $venueUrl = $venue
            ? '/general-admin/venues/' . $venue->uuid . '?openInquiry=' . $inquiry->uuid
            : '/general-admin/venues';

        if ($organization === null) {
            return;
        }

        $this->notificationService->sendToOrganization(
            $organization->uuid,
            $type,
            $title,
            $body,
            $venueUrl,
            [
                'inquiry_uuid' => $inquiry->uuid,
                'venue_listing_uuid' => $venue->uuid,
            ],
        );

        if (! empty($organization->email)) {
            $organization->notify(new VenueVisitCustomerResponseNotification(
                $inquiry->uuid,
                $emailResponse,
                $suggestedDate,
                $scheduledVisitLabel,
                $suggestedTime,
            ));
        }
    }

    private function sendScheduleCard(
        VenueInquiry $inquiry,
        ?VenueListing $venue,
        ?string $visitLabel,
        ?string $visitTime,
        bool $isReschedule,
    ): void {
        $venueName = $venue?->name ?? 'the venue';
        $locationLabel = $this->locationLabel($venue);
        $visitDate = $inquiry->visit_scheduled_date?->format('Y-m-d');

        $body = $isReschedule
            ? "Heads up! We've updated your site visit at {$locationLabel} to {$visitLabel}."
            : "Hello! We've scheduled your site visit at {$locationLabel} on {$visitLabel}.";

        $this->sendChatCard(
            $inquiry,
            ChatMessage::TYPE_SCHEDULE_CARD,
            $body,
            [
                'is_reschedule' => $isReschedule,
                'venue_name' => $venueName,
                'venue_address' => $this->venueAddress($venue),
                'visit_date' => $visitDate,
                'visit_time' => $visitTime,
                'visit_label' => $visitLabel,
                'inquiry_uuid' => $inquiry->uuid,
            ],
        );
    }

    private function sendCustomerChatText(VenueInquiry $inquiry, string $body): void
    {
        try {
            $thread = $this->chatRepository->firstOrCreateThreadForInquiry($inquiry);

            $this->chatService->sendMessage(
                $thread,
                ChatThread::SENDER_CUSTOMER,
                $inquiry->user_uuid,
                $inquiry->full_name ?: $inquiry->email,
                $body,
            );
        } catch (\Throwable $e) {
            Log::error('Failed to send customer chat message', [
                'inquiry_uuid' => $inquiry->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @param array<string, mixed> $metadata */
    private function sendChatCard(
        VenueInquiry $inquiry,
        string $messageType,
        string $body,
        array $metadata,
        ?string $attachmentUploadUuid = null,
        ?string $attachmentName = null,
    ): void {
        try {
            $venueName = $inquiry->venueListing?->name ?? 'the venue';
            $thread = $this->chatRepository->firstOrCreateThreadForInquiry($inquiry);

            $this->chatService->sendMessage(
                $thread,
                ChatThread::SENDER_MERCHANT,
                null,
                $venueName,
                $body,
                $attachmentUploadUuid,
                $attachmentName,
                $messageType,
                $metadata,
            );
        } catch (\Throwable $e) {
            Log::error('Failed to send workflow chat card', [
                'inquiry_uuid' => $inquiry->uuid,
                'message_type' => $messageType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function venueAddress(?VenueListing $venue): ?string
    {
        if ($venue === null) {
            return null;
        }

        $address = trim(implode(', ', array_filter([$venue->address, $venue->city])));

        return $address !== '' ? $address : null;
    }

    private function locationLabel(?VenueListing $venue): string
    {
        $venueName = $venue?->name ?? 'the venue';
        $address = $this->venueAddress($venue);

        return $address !== null ? "{$venueName} ({$address})" : $venueName;
    }
}
