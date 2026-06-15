<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Repositories\VenueInquiryRepository;
use App\Http\Requests\VenueListing\ListMyVenueInquiryRequest;
use App\Http\Requests\VenueListing\PayVenueInquiryRequest;
use App\Http\Requests\VenueListing\SuggestVenueVisitDateRequest;
use App\Http\Resources\VenueInquiryCustomerResource;
use App\Services\Checkout\VenueBookingCheckoutService;
use App\Services\VenueInquiryWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VenueInquiryController extends Controller
{
    public function __construct(
        protected VenueInquiryRepository $venueInquiryRepository,
        protected VenueBookingCheckoutService $venueBookingCheckoutService,
        protected VenueInquiryWorkflowService $workflowService,
    ) {
    }

    /**
     * List venue inquiries for the authenticated customer.
     *
     * @param ListMyVenueInquiryRequest $request
     * @return JsonResponse
     */
    public function getMyInquiries(ListMyVenueInquiryRequest $request): JsonResponse
    {
        $user = $request->user();
        $perPage = $request->get('per_page', 15);
        $list = $this->venueInquiryRepository->getMyInquiries(
            $user->uuid,
            $user->email,
            $request->validated()
        );

        return VenueInquiryCustomerResource::collection($list->paginate($perPage))->response();
    }

    /**
     * Start payment for an approved venue inquiry. Creates a shared transaction
     * header and opens a payment intent; the resulting transaction is finalized
     * through the same /transactions/{uuid}/complete endpoint used by tickets.
     *
     * @param PayVenueInquiryRequest $request
     * @param string $uuid
     * @return JsonResponse
     */
    public function pay(PayVenueInquiryRequest $request, string $uuid): JsonResponse
    {
        $inquiry = $this->venueInquiryRepository->fetchOrThrow($uuid);

        DB::beginTransaction();

        try {
            $result = $this->venueBookingCheckoutService->startPayment(
                $inquiry,
                $request->user()->uuid,
                $request->validated(),
            );

            DB::commit();

            $paymentResult = $result['payment_result'];
            $response = [
                'success' => true,
                'transaction' => $result['transaction'],
                'payment' => [
                    'provider' => $request->validated()['payment_provider'],
                    'payment_id' => $paymentResult['payment_id'] ?? null,
                    'status' => $paymentResult['status'] ?? 'pending',
                ],
            ];

            if (!empty($paymentResult['checkout_url'])) {
                $response['payment']['checkout_url'] = $paymentResult['checkout_url'];
                $response['redirect_url'] = $paymentResult['checkout_url'];
            } elseif (!empty($paymentResult['approval_url'])) {
                $response['payment']['approval_url'] = $paymentResult['approval_url'];
                $response['redirect_url'] = $paymentResult['approval_url'];
            }

            return response()->json($response, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Venue inquiry payment failed', [
                'venue_inquiry_uuid' => $uuid,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to start venue payment. Please try again.',
            ], 500);
        }
    }

    public function acceptProposal(string $uuid): JsonResponse
    {
        $inquiry = $this->venueInquiryRepository->fetchOrThrow($uuid);
        $this->authorizeCustomerInquiry($inquiry, request()->user());

        $inquiry = $this->workflowService->acceptProposal($inquiry);

        return (new VenueInquiryCustomerResource($inquiry))->response();
    }

    public function declineProposal(string $uuid): JsonResponse
    {
        $inquiry = $this->venueInquiryRepository->fetchOrThrow($uuid);
        $this->authorizeCustomerInquiry($inquiry, request()->user());

        $inquiry = $this->workflowService->declineProposal($inquiry);

        return (new VenueInquiryCustomerResource($inquiry))->response();
    }

    public function acceptVisitSchedule(string $uuid): JsonResponse
    {
        $inquiry = $this->venueInquiryRepository->fetchOrThrow($uuid);
        $this->authorizeCustomerInquiry($inquiry, request()->user());

        $inquiry = $this->workflowService->acceptVisitSchedule($inquiry);

        return (new VenueInquiryCustomerResource($inquiry))->response();
    }

    public function declineVisitSchedule(string $uuid): JsonResponse
    {
        $inquiry = $this->venueInquiryRepository->fetchOrThrow($uuid);
        $this->authorizeCustomerInquiry($inquiry, request()->user());

        $inquiry = $this->workflowService->declineVisitSchedule($inquiry);

        return (new VenueInquiryCustomerResource($inquiry))->response();
    }

    public function suggestVisitDate(SuggestVenueVisitDateRequest $request, string $uuid): JsonResponse
    {
        $inquiry = $this->venueInquiryRepository->fetchOrThrow($uuid);
        $this->authorizeCustomerInquiry($inquiry, request()->user());

        $validated = $request->validated();

        $inquiry = $this->workflowService->suggestVisitDate(
            $inquiry,
            (string) $validated['suggested_date'],
            (string) $validated['suggested_time'],
        );

        return (new VenueInquiryCustomerResource($inquiry))->response();
    }

    private function authorizeCustomerInquiry($inquiry, $user): void
    {
        if ($inquiry->user_uuid !== null && $inquiry->user_uuid !== $user->uuid) {
            abort(403, 'You do not have access to this inquiry.');
        }

        if ($inquiry->user_uuid === null && strcasecmp($inquiry->email, $user->email) !== 0) {
            abort(403, 'You do not have access to this inquiry.');
        }
    }
}
