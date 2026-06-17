<?php

namespace App\Http\Repositories;

use App\Constants\GeneralConstants;
use App\Exceptions\NoUserFoundException;
use App\Exceptions\UnauthorizedException;
use App\Models\User;
use App\Models\UserAffiliate;
use App\Helpers\GeneralHelper;
use App\Models\Ticket;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserRepository
{
    /**
     * @param User $user
     */
    public function __construct(
        protected User $user,
    ) {
    }

    /**
     * @param array $filters
     * @return Builder
     */
    public function getAll(array $filters): Builder
    {
        return $this->user->filters($filters)
            ->with(['profileImage', 'role'])
            ->orderBy('first_name', 'desc');
    }

    public function getAllForOrganization(string $organizationUuid, array $filters): Builder
    {
        return $this->user->query()
            ->visibleToOrganizer($organizationUuid)
            ->filters($filters)
            ->with(['profileImage', 'role'])
            ->orderBy('first_name', 'desc');
    }


    /**
     * Fetch company from ES, throw exception if not found.
     *
     * @param string $key
     * @param string $value
     * @return User
     * @throws NoUserFoundException
     */
    public function fetchOrThrow(string $key, string $value): User
    {
        $user = $this->user->where($key, $value)->first();

        if (is_null($user)) {
            throw new NoUserFoundException();
        }

        return $user;
    }

    /**
     * @param array $payload
     * @return User $user
     */
    public function create(array $payload): User
    {
        $userPayload = GeneralHelper::unsetUnknownAndNullFields($payload, User::DATA);
        return $this->user->create($userPayload);
    }

    /**
     * @param User $user
     * @param array $payload
     * @return bool|User
     */
    public function update(User $user, array $payload): bool|User
    {
        $userPayload = GeneralHelper::unsetUnknownAndNullFields($payload, User::DATA);
        return $user->update($userPayload);
    }

    /**
     * @param User $user
     * @return void
     * @throws UnauthorizedException
     */
    public function delete(User $user): void
    {
        // Prevent self-deletion (for admin use)
        if ($user->uuid === auth('api')->user()->uuid) {
            throw new UnauthorizedException('You cannot delete yourself');
        }

        $user->delete();
    }

    /**
     * Delete user account (self-deletion allowed)
     * @param User $user
     * @return void
     */
    public function deleteAccount(User $user): void
    {
        $user->delete();
    }

    /**
     * Get user statistics
     * @param User $user
     * @return array
     */
    public function getUserStats(User $user, ?string $organizationUuid = null): array
    {
        // Total Purchase - sum of total_amount in transactions table where payment_status is paid
        $transactionsQuery = $user->transactions()
            ->where('payment_status', Transaction::PAYMENT_STATUS['PAID']);

        if ($organizationUuid) {
            $transactionsQuery->where('organization_uuid', $organizationUuid);
        }

        $totalPurchase = $transactionsQuery->sum('total_amount');

        // On hand tickets - count of status = active tickets
        $onHandTicketsQuery = $user->tickets()
            ->where('status', GeneralConstants::TICKET_STATUSES['ACTIVE']);

        if ($organizationUuid) {
            $onHandTicketsQuery->where('organization_uuid', $organizationUuid);
        }

        $onHandTickets = $onHandTicketsQuery->count();

        // Transferred Tickets - count of status = transferred tickets
        $transferredTicketsQuery = $user->tickets()
            ->where('status', GeneralConstants::TICKET_STATUSES['TRANSFERRED']);

        if ($organizationUuid) {
            $transferredTicketsQuery->where('organization_uuid', $organizationUuid);
        }

        $transferredTickets = $transferredTicketsQuery->count();

        // Used Tickets - count of status = used tickets
        $usedTicketsQuery = $user->tickets()
            ->where('status', GeneralConstants::TICKET_STATUSES['USED']);

        if ($organizationUuid) {
            $usedTicketsQuery->where('organization_uuid', $organizationUuid);
        }

        $usedTickets = $usedTicketsQuery->count();

        return [
            'total_purchase' => (float) $totalPurchase,
            'on_hand_tickets' => $onHandTickets,
            'transferred_tickets' => $transferredTickets,
            'used_tickets' => $usedTickets,
        ];
    }

    /**
     * Get user recent activity
     * @param User $user
     * @return mixed
     */
    public function getUserRecentActivity(User $user, ?string $organizationUuid = null): mixed
    {
        // Get 3 latest purchased tickets (transactions)
        $purchaseActivitiesQuery = $user->transactions()
            ->where('payment_status', Transaction::PAYMENT_STATUS['PAID']);

        if ($organizationUuid) {
            $purchaseActivitiesQuery->where('organization_uuid', $organizationUuid);
        }

        $purchaseActivities = $purchaseActivitiesQuery
            ->latest()
            ->limit(5)
            ->get()
            ->map(function ($purchase) {
                $eventName = $purchase->event ? $purchase->event->event_name : 'an event';
                $userName = $purchase->user ? ($purchase->user->full_name ?? $purchase->user->name ?? 'User') : 'User';
                if ($purchase->payment_order_id) {
                    return [
                        'type' => 'purchase',
                        'message' => "$userName purchased tickets for $eventName with total amount of {$purchase->total_amount}",
                        'timestamp' => $purchase->paid_at,
                        'data' => $purchase,
                    ];
                }
                if (!$purchase->payment_order_id) {
                    $transaction = Transaction::find($purchase->uuid)->first();
                    return [
                        'type' => 'purchase',
                        'message' => $transaction->createdBy->full_name ?? 'System' . " manually added ticket to user {$userName} for {$eventName}",
                        'timestamp' => $purchase->created_at,
                        'data' => $purchase,
                    ];
                }
            });

        // Get 3 most transferred tickets
        $transferredTicketsQuery = $user->tickets()
            ->where('status', GeneralConstants::TICKET_STATUSES['TRANSFERRED']);

        if ($organizationUuid) {
            $transferredTicketsQuery->where('organization_uuid', $organizationUuid);
        }

        $transferredTicketsActivities = $transferredTicketsQuery
            ->latest()
            ->limit(3)
            ->get()
            ->map(function ($ticket) {
                $transferredToUser = $ticket->transferredToUser;
                $ticketUser = $ticket->user->full_name ?? '';
                $transferUser = $transferredToUser->full_name ?? '';
                return [
                    'type' => 'transferred_ticket',
                    'message' => "{$ticketUser} transferred ticket to {$transferUser}",
                    'timestamp' => $ticket->transferred_at,
                    'data' => $ticket,
                ];
            });

        // Get 2 most recent ticket
        $usedTicketsQuery = $user->tickets()
            ->where('status', GeneralConstants::TICKET_STATUSES['USED']);

        if ($organizationUuid) {
            $usedTicketsQuery->where('organization_uuid', $organizationUuid);
        }

        $usedTicketsActivities = $usedTicketsQuery
            ->latest()
            ->limit(2)
            ->get()
            ->map(function ($ticket) {
                return [
                    'type' => 'ticket_used',
                    'message' => "{$ticket->user->full_name} used ticket for {$ticket->event->event_name}",
                    'timestamp' => $ticket->used_at,
                    'data' => $ticket,
                ];
            });

        // Merge and sort all activities by timestamp desc, limit 15
        return collect()
            ->merge($purchaseActivities)
            ->merge($transferredTicketsActivities)
            ->merge($usedTicketsActivities)
            ->sortByDesc('timestamp')
            ->take(15)
            ->values();
    }

    /**
     * Get user tickets
     * @param User $user
     * @param string|null $status
     * @return Builder
     */
    public function getUserTickets(User $user, ?string $status = null, ?string $q = null, ?string $organizationUuid = null): Builder
    {
        $query = $user->tickets()
            ->with(['event:uuid,event_name', 'transaction:uuid,payment_order_id,total_amount', 'transaction.schedule:uuid,date_from,date_to', 'transaction.scheduleTime:uuid,time_start,time_end', 'eventTicket:uuid,name'])
            ->orderBy('created_at', 'desc');

        if ($organizationUuid) {
            $query->where('organization_uuid', $organizationUuid);
        }

        if ($status) {
            $query->where('tickets.status', $status);
        }

        if ($q !== null && $q !== '') {
            $keyword = $q;
            $query->where(function ($subQuery) use ($keyword) {
                $subQuery->where('ticket_number', 'LIKE', "%{$keyword}%")
                    ->orWhere('qr_code', 'LIKE', "%{$keyword}%");
            });
        }

        return $query;
    }

    /**
     * Export users.
     * @return string
     */
    public function exportUsers(?string $organizationUuid = null): string
    {
        $userTable = $this->user->getTable();

        $usersQuery = $this->user->query();

        if ($organizationUuid) {
            $usersQuery->visibleToOrganizer($organizationUuid);
        }

        $organizationFilter = $organizationUuid
            ? ' AND transactions.organization_uuid = ' . DB::getPdo()->quote($organizationUuid)
            : '';
        $organizationTicketFilter = $organizationUuid
            ? ' AND tickets.organization_uuid = ' . DB::getPdo()->quote($organizationUuid)
            : '';

        $users = $usersQuery
            ->select([
                $userTable . '.uuid',
                $userTable . '.email',
                $userTable . '.first_name',
                $userTable . '.last_name',
                $userTable . '.phone_number',
                $userTable . '.birth_date',
                $userTable . '.created_at',
            ])
            ->selectRaw('(
                SELECT COALESCE(SUM(total_amount), 0)
                FROM transactions
                WHERE transactions.user_uuid = ' . $userTable . '.uuid
                AND transactions.payment_status = ?
                AND transactions.deleted_at IS NULL' . $organizationFilter . '
            ) as total_purchased', [Transaction::PAYMENT_STATUS['PAID']])
            ->selectRaw('(
                SELECT COUNT(*)
                FROM tickets
                WHERE tickets.user_uuid = ' . $userTable . '.uuid
                AND tickets.status = ?
                AND tickets.deleted_at IS NULL' . $organizationTicketFilter . '
            ) as on_hand_tickets', [GeneralConstants::TICKET_STATUSES['ACTIVE']])
            ->selectRaw('(
                SELECT COUNT(*)
                FROM tickets
                WHERE tickets.user_uuid = ' . $userTable . '.uuid
                AND tickets.status = ?
                AND tickets.deleted_at IS NULL' . $organizationTicketFilter . '
            ) as transferred_tickets', [GeneralConstants::TICKET_STATUSES['TRANSFERRED']])
            ->selectRaw('(
                SELECT COUNT(*)
                FROM tickets
                WHERE tickets.user_uuid = ' . $userTable . '.uuid
                AND tickets.status = ?
                AND tickets.deleted_at IS NULL' . $organizationTicketFilter . '
            ) as used_tickets', [GeneralConstants::TICKET_STATUSES['USED']])
            ->get();

        $data = [];

        // Add CSV headers
        $headers = [
            'Email',
            'First Name',
            'Last Name',
            'Created At',
            'Total Purchased',
            'On Hand Ticket',
            'Transferred Ticket',
            'Used Tickets'
        ];

        $data[] = $headers;

        foreach ($users as $user) {
            $fields = [
                $user->email ?? '',
                $user->first_name ?? '',
                $user->last_name ?? '',
                Carbon::parse($user->created_at)->format('m-d-Y H:i'),
                number_format((float) ($user->total_purchased ?? 0), 2, '.', ''),
                (int) ($user->on_hand_tickets ?? 0),
                (int) ($user->transferred_tickets ?? 0),
                (int) ($user->used_tickets ?? 0),
            ];

            $data[] = $fields;
        }

        $rows = count($users) + 1;
        $data[] = [
            '','','','',
            "=sum(E2:E$rows)",
            "=sum(F2:F$rows)",
            "=sum(G2:G$rows)",
            "=sum(H2:H$rows)",
        ];

        // Generate CSV content
        $csvContent = '';
        foreach ($data as $row) {
            $csvContent .= '"' . implode('","', $row) . '"' . "\n";
        }

        return $csvContent;
    }

    public function enrollAffiliatePartner(User $user): User
    {
        $code = $this->generateUniqueAffiliateCode();
        $now = Carbon::now();
        $user->userAffiliate()->updateOrCreate(
            ['user_uuid' => $user->uuid],
            [
                'affiliate_status' => GeneralConstants::AFFILIATE_STATUSES['APPROVED'],
                'affiliate_code' => $code,
                'affiliate_applied_at' => $now,
                'affiliate_approved_at' => $now,
            ]
        );

        return $user->fresh();
    }

    protected function generateUniqueAffiliateCode(): string
    {
        do {
            $code = strtoupper(bin2hex(random_bytes(4)));
        } while (UserAffiliate::query()->where('affiliate_code', $code)->exists());

        return $code;
    }
}
