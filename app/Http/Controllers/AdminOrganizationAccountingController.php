<?php

namespace App\Http\Controllers;

use App\Http\Requests\Organization\OrganizationAccountingRequest;
use App\Models\Organization;
use App\Services\Organizer\OrganizerAccountingReportService;
use Illuminate\Http\JsonResponse;

class AdminOrganizationAccountingController extends Controller
{
    public function __construct(
        protected OrganizerAccountingReportService $reportService,
    ) {
    }

    public function summary(OrganizationAccountingRequest $request, string $uuid): JsonResponse
    {
        $this->resolveOrganization($uuid);

        return response()->json([
            'success' => true,
            'message' => 'Organization accounting summary',
            'data' => $this->reportService->summary($uuid, $request->eventUuid()),
        ]);
    }

    public function transactions(OrganizationAccountingRequest $request, string $uuid): JsonResponse
    {
        $this->resolveOrganization($uuid);

        $bucket = (string) $request->query('bucket', '');
        if (! in_array($bucket, ['available', 'pending'], true)) {
            return response()->json([
                'message' => 'Query parameter "bucket" is required and must be "available" or "pending".',
            ], 422);
        }

        $perPage = (int) $request->query('per_page', 15);
        $page = (int) $request->query('page', 1);

        return response()->json([
            'success' => true,
            'message' => 'Organization accounting transactions',
            'data' => $this->reportService->transactions($uuid, $bucket, $page, $perPage, $request->eventUuid()),
        ]);
    }

    public function remittanceBuckets(OrganizationAccountingRequest $request, string $uuid): JsonResponse
    {
        $this->resolveOrganization($uuid);

        $bucket = (string) $request->query('bucket', '');
        if (! in_array($bucket, ['available', 'pending'], true)) {
            return response()->json([
                'message' => 'Query parameter "bucket" is required and must be "available" or "pending".',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Organization remittance buckets',
            'data' => $this->reportService->remittanceBuckets($uuid, $bucket, $request->eventUuid()),
        ]);
    }

    public function payoutRequests(OrganizationAccountingRequest $request, string $uuid): JsonResponse
    {
        $this->resolveOrganization($uuid);

        $perPage = min(50, max(5, (int) $request->query('per_page', 10)));

        return response()->json([
            'success' => true,
            'message' => 'Organization payout requests',
            'data' => $this->reportService->payoutRequests(
                $uuid,
                $request->eventUuid(),
                max(1, (int) $request->query('pending_page', 1)),
                max(1, (int) $request->query('success_page', 1)),
                max(1, (int) $request->query('declined_page', 1)),
                $perPage,
            ),
        ]);
    }

    private function resolveOrganization(string $uuid): Organization
    {
        return Organization::query()->where('uuid', $uuid)->firstOrFail();
    }
}
