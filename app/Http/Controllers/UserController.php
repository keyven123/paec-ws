<?php

namespace App\Http\Controllers;

use App\Constants\GeneralConstants;
use App\Http\Repositories\AffiliateConversionRepository;
use App\Http\Repositories\UserRepository;
use App\Http\Resources\AffiliateConversionResource;
use App\Services\AffiliatePartnerStatsService;
use Carbon\Carbon;
use App\Http\Requests\User\CreateUserRequest;
use App\Http\Requests\User\SuspendAffiliatePartnerRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Requests\User\ListUserRequest;
use App\Http\Resources\UserResource;
use App\Exceptions\NoUserFoundException;
use App\Exceptions\UnauthorizedException;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    public function __construct(
        protected UserRepository $userRepository,
        protected AffiliateConversionRepository $affiliateConversionRepository,
    ) {
    }

    /**
     * Display a listing of users.
     * @param ListUserRequest $request
     * @return JsonResponse
     */
    public function index(ListUserRequest $request): JsonResponse
    {
        $perPage = $request->get('per_page', 15);

        $list = $this->userRepository->getAll($request->validated());

        return UserResource::collection($list->paginate($perPage))->response();
    }

    /**
     * Store a newly created user.
     * @param CreateUserRequest $uuid
     * @return JsonResponse
     */
    public function store(CreateUserRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $user = $this->userRepository->create($payload);
        return (new UserResource($user))->response()->setStatusCode(201);
    }

    /**
     * Display the specified user.
     * @param string $uuid
     * @return JsonResponse
     * @throws NoUserFoundException
     */
    public function show(string $uuid): JsonResponse
    {
        try {
            $user = $this->userRepository->fetchOrThrow('uuid', $uuid);
            $user->load(['profileImage', 'role']);

            return (new UserResource($user))->response();
        } catch (NoUserFoundException $e) {
            throw new NoUserFoundException();
        }
    }

    /**
     * Update the specified user.
     * @param UpdateUserRequest $request
     * @param string $uuid
     * @return JsonResponse
     * @throws NoUserFoundException
     */
    public function update(UpdateUserRequest $request, string $uuid): JsonResponse
    {
        try {
            $payload = $request->validated();
            $user = $this->userRepository->fetchOrThrow('uuid', $uuid);
            $this->userRepository->update($user, $payload);
            return (new UserResource($user->fresh()))->response();
        } catch (NoUserFoundException $e) {
            throw new NoUserFoundException();
        }
    }

    /**
     * Remove the specified user from storage.
     * @param string $uuid
     * @return Response|JsonResponse
     * @throws NoUserFoundException
     * @throws UnauthorizedException
     */
    public function destroy(string $uuid): Response|JsonResponse
    {
        try {
            $user = $this->userRepository->fetchOrThrow('uuid', $uuid);
            $this->userRepository->delete($user);
            return $this->noContent();
        } catch (NoUserFoundException $e) {
            throw new NoUserFoundException();
        } catch (UnauthorizedException $e) {
            throw new UnauthorizedException();
        }
    }

    /**
     * Get user statistics.
     * @param string $uuid
     * @return JsonResponse
     * @throws NoUserFoundException
     */
    public function stats(string $uuid): JsonResponse
    {
        try {
            $user = $this->userRepository->fetchOrThrow('uuid', $uuid);
            $stats = $this->userRepository->getUserStats($user);

            return response()->json([
                'success' => true,
                'message' => 'User statistics retrieved successfully',
                'data' => $stats,
            ]);
        } catch (NoUserFoundException $e) {
            throw new NoUserFoundException();
        }
    }

    /**
     * Get user recent activity.
     * @param string $uuid
     * @return JsonResponse
     * @throws NoUserFoundException
     */
    public function recentActivity(string $uuid): JsonResponse
    {
        try {
            $user = $this->userRepository->fetchOrThrow('uuid', $uuid);
            $activity = $this->userRepository->getUserRecentActivity($user);

            return response()->json([
                'success' => true,
                'message' => 'User recent activity retrieved successfully',
                'data' => $activity,
            ]);
        } catch (NoUserFoundException $e) {
            throw new NoUserFoundException();
        }
    }

    /**
     * Get user tickets.
     * @param string $uuid
     * @return JsonResponse
     * @throws NoUserFoundException
     */
    public function tickets(string $uuid): JsonResponse
    {
        try {
            $user = $this->userRepository->fetchOrThrow('uuid', $uuid);
            $perPage = request()->get('per_page', 10);
            $status = request()->get('status');
            $q = request()->get('q');
            $search = is_string($q) ? trim($q) : null;

            $tickets = $this->userRepository->getUserTickets(
                $user,
                $status,
                $search !== '' ? $search : null,
            );

            return response()->json($tickets->paginate($perPage));
        } catch (NoUserFoundException $e) {
            throw new NoUserFoundException();
        }
    }

    /**
     * Admin: affiliate partner overview (metrics, bank snapshot, commission history page).
     */
    public function affiliatePartnerStats(string $uuid): JsonResponse
    {
        try {
            $user = $this->userRepository->fetchOrThrow('uuid', $uuid);
            $perPage = min(50, max(1, (int) request()->query('conversions_per_page', 15)));
            $page = max(1, (int) request()->query('conversions_page', 1));

            $conversions = $this->affiliateConversionRepository->getByUserForPage(
                $user->uuid,
                $perPage,
                $page,
                'conversions_page',
            );

            return response()->json([
                'data' => [
                    'user' => [
                        'uuid' => $user->uuid,
                        'full_name' => trim(preg_replace('/\s+/', ' ', $user->full_name)),
                        'email' => $user->email,
                        'affiliate_code' => $user->userAffiliate?->affiliate_code,
                        'affiliate_status' => $user->userAffiliate?->affiliate_status ?? 'none',
                        'affiliate_applied_at' => $user->userAffiliate?->affiliate_applied_at
                            ? Carbon::parse($user->userAffiliate->affiliate_applied_at)->toIso8601String()
                            : null,
                        'affiliate_approved_at' => $user->userAffiliate?->affiliate_approved_at
                            ? Carbon::parse($user->userAffiliate->affiliate_approved_at)->toIso8601String()
                            : null,
                        'terms_accepted_at' => $user->terms_accepted_at
                            ? Carbon::parse($user->terms_accepted_at)->toIso8601String()
                            : null,
                        'affiliate_suspend_reason' => $user->userAffiliate?->affiliate_suspend_reason,
                        'affiliate_suspended_at' => $user->userAffiliate?->affiliate_suspended_at
                            ? Carbon::parse($user->userAffiliate->affiliate_suspended_at)->toIso8601String()
                            : null,
                    ],
                    'stats' => AffiliatePartnerStatsService::dashboardStatsForUser($user),
                    'bank_details' => [
                        'bank' => $user->userAffiliate?->affiliate_bank_name,
                        'branch' => $user->userAffiliate?->affiliate_bank_branch,
                        'account_name' => $user->userAffiliate?->affiliate_bank_account_name,
                        'account_number' => $user->userAffiliate?->affiliate_bank_account_number,
                        'tin' => $user->userAffiliate?->affiliate_bank_tin,
                    ],
                    'conversions' => [
                        'data' => AffiliateConversionResource::collection($conversions->items())->resolve(),
                        'meta' => [
                            'current_page' => $conversions->currentPage(),
                            'last_page' => $conversions->lastPage(),
                            'per_page' => $conversions->perPage(),
                            'total' => $conversions->total(),
                            'from' => $conversions->firstItem(),
                            'to' => $conversions->lastItem(),
                        ],
                    ],
                ],
            ]);
        } catch (NoUserFoundException $e) {
            throw new NoUserFoundException();
        }
    }

    public function affiliateSuspend(SuspendAffiliatePartnerRequest $request, string $uuid): JsonResponse
    {
        try {
            $user = $this->userRepository->fetchOrThrow('uuid', $uuid);
            if (($user->userAffiliate?->affiliate_status ?? GeneralConstants::AFFILIATE_STATUSES['NONE']) !== GeneralConstants::AFFILIATE_STATUSES['APPROVED']) {
                return response()->json([
                    'message' => 'Only approved affiliate partners can be suspended.',
                ], 422);
            }

            $user->userAffiliate()->updateOrCreate(
                ['user_uuid' => $user->uuid],
                [
                    'affiliate_status' => GeneralConstants::AFFILIATE_STATUSES['SUSPENDED'],
                    'affiliate_suspend_reason' => $request->validated()['reason'],
                    'affiliate_suspended_at' => now(),
                ]
            );

            return response()->json([
                'message' => 'Affiliate partner suspended.',
                'data' => new UserResource($user->fresh()),
            ]);
        } catch (NoUserFoundException $e) {
            throw new NoUserFoundException();
        }
    }

    public function affiliateReinstate(string $uuid): JsonResponse
    {
        try {
            $user = $this->userRepository->fetchOrThrow('uuid', $uuid);
            if (($user->userAffiliate?->affiliate_status ?? GeneralConstants::AFFILIATE_STATUSES['NONE']) !== GeneralConstants::AFFILIATE_STATUSES['SUSPENDED']) {
                return response()->json([
                    'message' => 'Only suspended affiliate partners can be reinstated.',
                ], 422);
            }

            $user->userAffiliate()->updateOrCreate(
                ['user_uuid' => $user->uuid],
                [
                    'affiliate_status' => GeneralConstants::AFFILIATE_STATUSES['APPROVED'],
                    'affiliate_suspend_reason' => null,
                    'affiliate_suspended_at' => null,
                ]
            );

            return response()->json([
                'message' => 'Affiliate partner reinstated.',
                'data' => new UserResource($user->fresh()),
            ]);
        } catch (NoUserFoundException $e) {
            throw new NoUserFoundException();
        }
    }

    /**
     * Export users.
     * @return \Illuminate\Http\Response
     */
    public function export(): \Illuminate\Http\Response
    {
        $csvContent = $this->userRepository->exportUsers();

        $fileName = 'list_of_users_' . now()->format('Y-m-d_H-i-s') . '.csv';

        return response($csvContent, 200, [
            'Content-Type'              => 'text/csv; charset=utf-8',
            'Content-Disposition'       => 'attachment; filename="' . $fileName . '"',
            'Access-Control-Expose-Headers' => 'Content-Disposition, Content-Type',
            'Cache-Control'             => 'no-cache, private',
            'Pragma'                    => 'no-cache',
        ]);
    }
}
