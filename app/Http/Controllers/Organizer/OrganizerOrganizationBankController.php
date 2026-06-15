<?php

namespace App\Http\Controllers\Organizer;

use App\Exceptions\NoResourceFoundException;
use App\Http\Controllers\Controller;
use App\Http\Repositories\OrganizationBankRepository;
use App\Http\Repositories\OrganizationRepository;
use App\Http\Requests\Organizer\StoreOrganizationBankRequest;
use App\Http\Requests\Organizer\SyncOrganizationBanksRequest;
use App\Http\Requests\Organizer\UpdateOrganizationBankRequest;
use App\Http\Resources\OrganizationBankResource;
use Illuminate\Http\JsonResponse;

class OrganizerOrganizationBankController extends Controller
{
    public function __construct(
        protected OrganizationRepository $organizationRepository,
        protected OrganizationBankRepository $organizationBankRepository,
    ) {
    }

    public function index(): JsonResponse
    {
        $organization = $this->organizationRepository->fetchOrThrow(
            'uuid',
            auth('admin')->user()->organization_uuid
        );

        $banks = $this->organizationBankRepository->listForOrganization($organization->uuid);

        return response()->json([
            'data' => OrganizationBankResource::collection($banks),
        ]);
    }

    public function store(StoreOrganizationBankRequest $request): JsonResponse
    {
        $organization = $this->organizationRepository->fetchOrThrow(
            'uuid',
            auth('admin')->user()->organization_uuid
        );

        $bank = $this->organizationBankRepository->createForOrganization(
            $organization,
            $request->validated()
        );

        return (new OrganizationBankResource($bank))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateOrganizationBankRequest $request, string $uuid): JsonResponse
    {
        try {
            $organization = $this->organizationRepository->fetchOrThrow(
                'uuid',
                auth('admin')->user()->organization_uuid
            );

            $bank = $this->organizationBankRepository->fetchForOrganizationOrThrow(
                $organization->uuid,
                $uuid
            );

            $bank = $this->organizationBankRepository->updateBank($bank, $request->validated());

            return (new OrganizationBankResource($bank))->response();
        } catch (NoResourceFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Organization bank account not found',
            ], 404);
        }
    }

    public function destroy(string $uuid): JsonResponse
    {
        try {
            $organization = $this->organizationRepository->fetchOrThrow(
                'uuid',
                auth('admin')->user()->organization_uuid
            );

            $bank = $this->organizationBankRepository->fetchForOrganizationOrThrow(
                $organization->uuid,
                $uuid
            );

            $this->organizationBankRepository->deleteBank($bank);

            return response()->json([
                'success' => true,
                'message' => 'Bank account removed successfully.',
            ]);
        } catch (NoResourceFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Organization bank account not found',
            ], 404);
        }
    }

    public function sync(SyncOrganizationBanksRequest $request): JsonResponse
    {
        $organization = $this->organizationRepository->fetchOrThrow(
            'uuid',
            auth('admin')->user()->organization_uuid
        );

        $banks = $this->organizationBankRepository->syncForOrganization(
            $organization,
            $request->validated('banks')
        );

        return response()->json([
            'data' => OrganizationBankResource::collection($banks),
        ]);
    }
}
