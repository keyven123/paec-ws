<?php

namespace App\Http\Controllers;

use App\Http\Repositories\TransactionRepository;
use App\Http\Requests\Transaction\CreateTransactionRequest;
use App\Http\Requests\Transaction\UpdateTransactionRequest;
use App\Http\Requests\Transaction\ListTransactionRequest;
use App\Http\Requests\Transaction\SendTransactionTicketsRequest;
use App\Http\Resources\TransactionResource;
use App\Exceptions\NoTransactionFoundException;
use App\Exceptions\UnauthorizedException;
use App\Services\TransactionTicketDeliveryService;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class TransactionController extends Controller
{
    public function __construct(protected TransactionRepository $transactionRepository)
    {
    }

    /**
     * Display a listing of transactions.
     * @param ListTransactionRequest $request
     * @return JsonResponse
     */
    public function index(ListTransactionRequest $request): JsonResponse
    {
        $perPage = $request->get('per_page', 15);
        $filters = $request->validated();

        $user = auth('admin')->user();
        if ($user?->role && ! $user->role->is_admin) {
            unset($filters['organization_uuid']);
        }

        $list = $this->transactionRepository->getAll($filters);

        return TransactionResource::collection($list->paginate($perPage))->response();
    }

    /**
     * Store a newly created transaction.
     * @param CreateTransactionRequest $request
     * @return JsonResponse
     */
    public function store(CreateTransactionRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $transaction = $this->transactionRepository->create($payload);
        return (new TransactionResource($transaction))->response()->setStatusCode(201);
    }

    /**
     * Display the specified transaction.
     * @param string $uuid
     * @return JsonResponse
     */
    public function show(string $uuid): JsonResponse
    {
        try {
            $transaction = $this->transactionRepository->fetchOrThrowForViewer($uuid);

            return (new TransactionResource($transaction))->response();
        } catch (NoTransactionFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found'
            ], 404);
        }
    }

    /**
     * Send the transaction's active tickets to the account for an email, transferring them when it is another account.
     */
    public function sendTickets(
        SendTransactionTicketsRequest $request,
        string $uuid,
        TransactionTicketDeliveryService $deliveryService,
    ): JsonResponse {
        try {
            $transaction = $this->transactionRepository->fetchOrThrowForViewer($uuid);
            $result = $deliveryService->send($transaction, $request->validated('email'));
        } catch (NoTransactionFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found'
            ], 404);
        } catch (UnauthorizedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }

        $recipient = $result['recipient'];
        $message = $result['mode'] === TransactionTicketDeliveryService::MODE_RESENT
            ? "Tickets sent to {$recipient->email}."
            : "{$result['transferred_count']} " . Str::plural('ticket', $result['transferred_count'])
                . " transferred to {$recipient->email}.";

        return response()->json([
            'success' => true,
            'message' => $message,
            'mode' => $result['mode'],
            'ticket_count' => $result['ticket_count'],
            'transferred_count' => $result['transferred_count'],
            'recipient' => [
                'name' => Str::squish($recipient->full_name),
                'email' => $recipient->email,
                'is_new_account' => $recipient->wasRecentlyCreated,
            ],
        ]);
    }

    /**
     * Update the specified transaction.
     * @param UpdateTransactionRequest $request
     * @param string $uuid
     * @return JsonResponse
     */
    public function update(UpdateTransactionRequest $request, string $uuid): JsonResponse
    {
        try {
            $payload = $request->validated();
            $transaction = $this->transactionRepository->fetchOrThrow('uuid', $uuid);
            $this->transactionRepository->update($transaction, $payload);
            return (new TransactionResource($transaction->fresh()))->response();
        } catch (NoTransactionFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found'
            ], 404);
        }
    }

    /**
     * Remove the specified transaction from storage.
     * @param string $uuid
     * @return Response|JsonResponse
     */
    public function destroy(string $uuid): Response|JsonResponse
    {
        try {
            $transaction = $this->transactionRepository->fetchOrThrow('uuid', $uuid);
            $this->transactionRepository->delete($transaction);
            return $this->noContent();
        } catch (NoTransactionFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found'
            ], 404);
        } catch (UnauthorizedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 403);
        }
    }

    public function getMyTransactions(ListTransactionRequest $request): JsonResponse
    {
        $perPage = $request->get('per_page', 10);
        $payload = $request->validated();
        $transactions = $this->transactionRepository->getMyTransactions($request->user()->uuid, $payload);
        return TransactionResource::collection($transactions->paginate($perPage))->response();
    }
}
