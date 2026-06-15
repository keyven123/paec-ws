<?php

namespace App\Http\Controllers;

use App\Http\Repositories\TransactionRepository;
use App\Http\Requests\Transaction\CreateTransactionRequest;
use App\Http\Requests\Transaction\UpdateTransactionRequest;
use App\Http\Requests\Transaction\ListTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Exceptions\NoTransactionFoundException;
use App\Exceptions\UnauthorizedException;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;

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
