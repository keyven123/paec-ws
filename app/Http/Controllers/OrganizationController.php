<?php

namespace App\Http\Controllers;

use App\Constants\GeneralConstants;
use App\Http\Repositories\OrganizationRepository;
use App\Http\Requests\Organization\CreateOrganizationRequest;
use App\Http\Requests\Organization\ListOrganizationRequest;
use App\Http\Requests\Organization\ListPublicOrganizationsRequest;
use App\Http\Resources\OrganizationResource;
use App\Exceptions\NoOrganizationFoundException;
use App\Exceptions\UnauthorizedException;
use App\Http\Repositories\AdminUserRepository;
use App\Services\Platform\OrganizationPlatformComService;
use App\Http\Requests\Organization\OnboardingRegisterRequest;
use App\Http\Requests\Organization\OnboardingRequest;
use App\Http\Requests\Organization\RegisterOrganizationRequest;
use App\Http\Requests\Organization\UpdateOrganizationCommissionPercentageRequest;
use App\Http\Requests\Organization\UpdateOrganizationPaymentMethodsRequest;
use App\Http\Requests\Organization\UpdateOrganizationRequest;
use App\Http\Resources\AdminUserResource;
use App\Http\Resources\PublicOrganizationResource;
use App\Models\AdminUser;
use Carbon\Carbon;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class OrganizationController extends Controller
{
    public function __construct(
        protected OrganizationRepository $organizationRepository,
        protected AdminUserRepository $adminUserRepository,
        protected OrganizationPlatformComService $organizationPlatformComService,
    ) {
    }

    /**
     * Display a listing of organizations.
     * @param ListOrganizationRequest $request
     * @return JsonResponse
     */
    public function index(ListOrganizationRequest $request): JsonResponse
    {
        $perPage = $request->get('per_page', 15);
        $list = $this->organizationRepository->getAll($request->validated());
        return OrganizationResource::collection($list->paginate($perPage))->response();
    }

    public function publicOrganizations(ListPublicOrganizationsRequest $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->input('per_page', 15)));
        $validated = $request->validated();

        $filters = [
            'statuses' => [
                GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
                GeneralConstants::ORGANIZER_STATUSES['ONBOARDED'],
            ],
            'sort_by' => 'desc',
        ];

        $q = isset($validated['q']) ? trim((string) $validated['q']) : '';
        if ($q !== '') {
            $filters['q'] = $q;
        }

        $list = $this->organizationRepository->getAll($filters);

        return PublicOrganizationResource::collection($list->paginate($perPage))->response();
    }

    /**
     * Get organization statistics.
     * @return JsonResponse
     */
    public function stats(): JsonResponse
    {
        $stats = $this->organizationRepository->getStats();
        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Store a newly created organization.
     * @param CreateOrganizationRequest $request
     * @return JsonResponse
     */
    public function store(CreateOrganizationRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $org = $this->organizationRepository->create($payload);
        return (new OrganizationResource($org))->response()->setStatusCode(201);
    }

    /**
     * Display the specified organization.
     * @param string $uuid
     * @return JsonResponse
     */
    public function show(string $uuid): JsonResponse
    {
        try {
            $org = $this->organizationRepository->fetchOrThrow('uuid', $uuid);
            return (new OrganizationResource($org))->response();
        } catch (NoOrganizationFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not found'
            ], 404);
        }
    }

    /**
     * Update the specified organization.
     * @param UpdateOrganizationRequest $request
     * @param string $uuid
     * @return JsonResponse
     */
    public function update(UpdateOrganizationRequest $request, string $uuid): JsonResponse
    {
        try {
            $payload = $request->validated();
            $org = $this->organizationRepository->fetchOrThrow('uuid', $uuid);
            $this->organizationRepository->update($org, $payload);

            return (new OrganizationResource($org->fresh()))->response();
        } catch (NoOrganizationFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not found'
            ], 404);
        }
    }

    public function updateCommissionPercentage(
        UpdateOrganizationCommissionPercentageRequest $request,
        string $uuid
    ): JsonResponse {
        try {
            $org = $this->organizationRepository->fetchOrThrow('uuid', $uuid);
            $previousCommission = $org->commission_percentage !== null
                ? (float) $org->commission_percentage
                : null;
            $newCommission = (float) $request->validated('commission_percentage');

            $org = $this->organizationRepository->updateCommissionPercentage($org, $newCommission);

            $this->organizationPlatformComService->logCommissionChange(
                $previousCommission,
                $newCommission,
                $org->uuid,
            );

            return (new OrganizationResource($org))->response();
        } catch (NoOrganizationFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not found',
            ], 404);
        }
    }

    public function updatePaymentMethods(
        UpdateOrganizationPaymentMethodsRequest $request,
        string $uuid
    ): JsonResponse {
        try {
            $org = $this->organizationRepository->fetchOrThrow('uuid', $uuid);
            $org = $this->organizationRepository->updatePaymentMethods(
                $org,
                $request->validated()['payment_methods'],
            );

            return (new OrganizationResource($org))->response();
        } catch (NoOrganizationFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not found',
            ], 404);
        }
    }

    /**
     * Remove the specified organization from storage.
     * @param string $uuid
     * @return Response|JsonResponse
     */
    public function destroy(string $uuid): Response|JsonResponse
    {
        try {
            $org = $this->organizationRepository->fetchOrThrow('uuid', $uuid);
            $this->organizationRepository->delete($org);
            return $this->noContent();
        } catch (NoOrganizationFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not found'
            ], 404);
        } catch (UnauthorizedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 403);
        }
    }

    public function register(RegisterOrganizationRequest $request): JsonResponse
    {
        DB::beginTransaction();
        $payload = $request->validated();
        $payload['accepted_terms'] = true;
        $payload['accepted_terms_at'] = Carbon::now()->toDateString();
        $org = $this->organizationRepository->create($payload);
        $initialCommission = $org->commission_percentage !== null
            ? (float) $org->commission_percentage
            : null;

        $adminUser = $this->organizationRepository->onboardingRegister(array_merge(
            $payload,
            [
                'phone_number' => $payload['contact_number'],
                'first_name' => $payload['representative_first_name'],
                'last_name' => $payload['representative_last_name'],
            ]
        ), $org->uuid);

        if ($adminUser) {
            $adminUser->update(['email_verified_at' => now()]);
            $org->update(['status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED']]);

            if ($initialCommission !== null) {
                $this->organizationPlatformComService->logCommissionChange(
                    null,
                    $initialCommission,
                    $org->uuid,
                    $adminUser->uuid,
                );
            }

            // Generate JWT token for admin user
            $token = auth('admin')->login($adminUser);

            // Update last login timestamp
            $this->adminUserRepository->updateLastLogin($adminUser);
            DB::commit();

            return $this->respondWithToken($token, $adminUser->fresh());
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Registration failed, please try again'
            ], 400);
        }
    }

    public function oldRegister(RegisterOrganizationRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $this->organizationRepository->oldCreate($payload);
        return response()->json([
            'success' => true,
            'message' => 'We will review your organization and get back to you soon.'
        ], 201);
    }

    public function approve(string $uuid): JsonResponse
    {
        $org = $this->organizationRepository->fetchOrThrow('uuid', $uuid);
        $this->organizationRepository->approve($org);
        return response()->json([
            'success' => true,
            'message' => 'Organization approved successfully'
        ], 200);
    }

    public function reject(string $uuid): JsonResponse
    {
        $org = $this->organizationRepository->fetchOrThrow('uuid', $uuid);
        $this->organizationRepository->reject($org);
        return response()->json([
            'success' => true,
            'message' => 'Organization rejected successfully'
        ], 200);
    }

    public function onboard(string $uuid): JsonResponse
    {
        $org = $this->organizationRepository->fetchOrThrow('uuid', $uuid);
        $this->organizationRepository->onboard($org);
        return response()->json([
            'success' => true,
            'message' => 'Organization onboarded successfully'
        ], 200);
    }

    public function sendInvitation(string $uuid): JsonResponse
    {
        $org = $this->organizationRepository->fetchOrThrow('uuid', $uuid);
        $this->organizationRepository->sendInvitation($org);
        return response()->json([
            'success' => true,
            'message' => 'Invitation sent successfully'
        ], 200);
    }

    public function onboarding(OnboardingRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $org = $this->organizationRepository->fetchOrThrow('email', $payload['email']);

        if ($org->secret_expired_at < now()) {
            return response()->json([
                'success' => false,
                'message' => 'Expired invitation please request for a new invitation to the admin.'
            ], 400);
        }

        if (!Hash::check($payload['secret'], $org->secret)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid secret'
            ], 400);
        }

        $secret = sprintf("%06d", mt_rand(1, 999999));
        $org->update([
            'secret' => Hash::make($secret),
            'secret_expired_at' => Carbon::now()->addHour(),
        ]);

        $org = $org->fresh();
        return response()->json([
            'success' => true,
            'message' => 'Onboarding successful',
            'secret' => $secret,
            'data' => new OrganizationResource($org)
        ], 200);
    }

    public function onboardingRegister(OnboardingRegisterRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $org = $this->organizationRepository->fetchOrThrow('email', $payload['email']);
        if ($org->secret_expired_at < now()) {
            return response()->json([
                'success' => false,
                'message' => 'Expired invitation please request for a new invitation to the admin.'
            ], 400);
        }

        if (!Hash::check($payload['secret'], $org->secret)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid secret'
            ], 400);
        }

        $adminUser = $this->organizationRepository->onboardingRegister($payload, $org->uuid);
        if ($adminUser) {
            $org->update([
                'status' => GeneralConstants::ORGANIZER_STATUSES['ONBOARDED'],
                'secret' => null,
                'secret_expired_at' => null,
            ]);
            return response()->json([
                'success' => true,
                'message' => 'Onboarding registration successful, you can now login to your account'
            ], 200);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Onboarding registration failed, please try again'
            ], 400);
        }
    }

    /**
     * Respond with token
     * @param string $token
     * @param AdminUser|null $adminUser
     * @return JsonResponse
     */
    protected function respondWithToken(string $token, AdminUser $adminUser): JsonResponse
    {
        if ($adminUser) {
            $adminUser->load(['role.permissions', 'organization']);
        }

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => Carbon::now()->addMinutes(auth('admin')->factory()->getTTL())->toDateTimeString(),
            'admin_user' => $adminUser ? new AdminUserResource($adminUser) : null,
            'role' => $adminUser->role->name,
            'is_admin' => !is_null($adminUser->organization_uuid) ? false : true,
            'permissions' => $adminUser && $adminUser->role ?
                \App\Models\RolePermission::where('role_uuid', $adminUser->role->uuid)
                    ->pluck('access')->toArray() : []
        ]);
    }
}
