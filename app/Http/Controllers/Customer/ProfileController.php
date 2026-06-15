<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Controllers\UploadController;
use App\Http\Requests\Customer\UpdateCustomerProfileRequest;
use App\Http\Repositories\AdminUserRepository;
use App\Http\Repositories\OrganizationRepository;
use App\Http\Repositories\UserRepository;
use App\Http\Requests\Organizer\UpdateOrganizationProfileRequest;
use App\Http\Requests\Organizer\UpdateOrganizerProfileRequest;
use App\Http\Resources\AdminUserResource;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\UserResource;
use App\Models\Dataset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Request;

class ProfileController extends Controller
{
    public function __construct(
        protected UserRepository $userRepository,
        protected AdminUserRepository $adminUserRepository,
        protected OrganizationRepository $organizationRepository,
    ) {
    }

    public function updateProfile(UpdateCustomerProfileRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $user = $this->userRepository
            ->fetchOrThrow('uuid', $request->user()->uuid);
        $this->userRepository->update($user, $payload);

        return (new UserResource($user->fresh()->load(['role', 'profileImage'])))->response();
    }

    public function updateProfileImage(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required','file','mimes:jpg,jpeg,png,webp','max:5120']]);

        // 1) Create Upload (could call UploadController@store or inline the logic above)
        $uploadResponse = app(UploadController::class)->store($request);
        $data = $uploadResponse->getData(true)['upload'];

        // 2) Attach to user
        $user = $request->user();
        $user->profile_image_uuid = $data['uuid'];
        $user->save();

        return response()->json([
            'message' => 'Profile image updated.',
            'profile_image_uuid' => $user->profile_image_uuid,
            'url' => $data['url'] ?? null,
        ]);
    }

    public function getOrganizerProfile(Request $request): JsonResponse
    {
        $user = $this->adminUserRepository->fetchOrThrow('uuid', auth('admin')->user()->uuid);
        return (new AdminUserResource($user))->response();
    }

    public function getOrganizationProfile(Request $request): JsonResponse
    {
        $organization = $this->organizationRepository->fetchOrThrow('uuid', auth('admin')->user()->organization_uuid);
        $data = (new OrganizationResource($organization))->resolve();
        $data['platform_default_commission_percentage'] = Dataset::merchantCommissionPercent();

        return response()->json(['data' => $data]);
    }

    public function updateOrganizerProfile(UpdateOrganizerProfileRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $adminUser = $this->adminUserRepository
            ->fetchOrThrow('uuid', auth('admin')->user()->uuid);
        $this->adminUserRepository->update($adminUser, $payload);

        return (new AdminUserResource($adminUser->fresh()))->response();
    }

    public function updateOrganizationProfile(UpdateOrganizationProfileRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $commissionExplicit = array_key_exists('commission_percentage', $validated);
        $commissionValue = $commissionExplicit ? $validated['commission_percentage'] : null;
        if ($commissionExplicit) {
            unset($validated['commission_percentage']);
        }

        $organization = $this->organizationRepository
            ->fetchOrThrow('uuid', auth('admin')->user()->organization_uuid);
        $this->organizationRepository->update($organization, $validated);

        if ($commissionExplicit) {
            $organization->commission_percentage = $commissionValue;
            $organization->save();
        }

        $fresh = $organization->fresh()->load(['image', 'banks']);
        $data = (new OrganizationResource($fresh))->resolve();
        $data['platform_default_commission_percentage'] = Dataset::merchantCommissionPercent();

        return response()->json(['data' => $data]);
    }

    public function deleteAccount(Request $request): JsonResponse
    {
        $user = $this->userRepository->fetchOrThrow('uuid', auth('api')->user()->uuid);
        $this->userRepository->deleteAccount($user);

        return response()->json([
            'message' => 'Account deleted successfully.',
        ]);
    }
}
