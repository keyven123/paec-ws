<?php

namespace App\Http\Repositories;

use App\Exceptions\NoTransactionFoundException;
use App\Exceptions\UnauthorizedException;
use App\Models\Transaction;
use App\Helpers\GeneralHelper;
use Illuminate\Contracts\Database\Eloquent\Builder;

class TransactionRepository
{
    /**
     * @param Transaction $transaction
     */
    public function __construct(protected Transaction $transaction)
    {
    }

    /**
     * @param array $filters
     * @return Builder
     */
    public function getAll(array $filters): Builder
    {
        return $this->transaction->with(['user', 'event', 'creator', 'transactionOrders.eventTicket'])
            ->byOrganization()
            ->filters($filters)
            ->orderBy('created_at', 'desc');
    }

    /**
     * Fetch transaction or throw exception if not found.
     *
     * @param string $key
     * @param string $value
     * @return Transaction
     * @throws NoTransactionFoundException
     */
    public function fetchOrThrow(string $key, string $value): Transaction
    {
        $transaction = $this->transaction->with(['user', 'event', 'creator', 'updater', 'tickets'])
            ->where($key, $value)->first();

        if (is_null($transaction)) {
            throw new NoTransactionFoundException();
        }

        return $transaction;
    }

    /**
     * @throws NoTransactionFoundException
     */
    public function fetchOrThrowForViewer(string $uuid): Transaction
    {
        $transaction = $this->transaction->newQuery()
            ->byOrganization()
            ->with([
                'user',
                'event',
                'schedule',
                'scheduleTime',
                'transactionOrders.eventTicket',
                'promoCode',
                'affiliatePartner',
                'affiliateConversion',
                'commissionLedger',
                'transactionable.venueListing',
            ])
            ->where('uuid', $uuid)
            ->first();

        if (is_null($transaction)) {
            throw new NoTransactionFoundException();
        }

        return $transaction;
    }

    /**
     * @param array $payload
     * @return Transaction
     */
    public function create(array $payload): Transaction
    {
        $transactionPayload = GeneralHelper::unsetUnknownAndNullFields($payload, Transaction::DATA);

        // Generate order number if not provided
        if (!isset($transactionPayload['order_number'])) {
            $transactionPayload['order_number'] = $this->generateOrderNumber();
        }

        return $this->transaction->create($transactionPayload);
    }

    /**
     * @param Transaction $transaction
     * @param array $payload
     * @return bool|Transaction
     */
    public function update(Transaction $transaction, array $payload): bool|Transaction
    {
        $transactionPayload = GeneralHelper::unsetUnknownAndNullFields($payload, Transaction::DATA);
        return $transaction->update($transactionPayload);
    }

    /**
     * @param Transaction $transaction
     * @return void
     * @throws UnauthorizedException
     */
    public function delete(Transaction $transaction): void
    {
        // Check if transaction has associated tickets
        if ($transaction->tickets()->exists()) {
            throw new UnauthorizedException('Cannot delete transaction with associated tickets.');
        }

        $transaction->delete();
    }

    /**
     * Generate a unique order number
     * @return string
     */
    private function generateOrderNumber(): string
    {
        do {
            $orderNumber = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        } while ($this->transaction->where('order_number', $orderNumber)->exists());

        return $orderNumber;
    }

    public function getRecentPurchasedTickets(array $filters): Builder
    {
        return $this->transaction->with(['user'])
            ->filters($filters)
            ->latest()
            ->take($filters['per_page'] ?? 10);
    }

    public function getMyTransactions(string $userUuid, array $filters): Builder
    {
        return $this->transaction
            ->with([
                'event',
                'promoCode',
                'transactionOrders.eventTicket',
                'transactionCompliances.activityCompliance',
            ])
            ->withCount('tickets')
            ->filters($filters)
            ->ownedByUser($userUuid)
            ->orderBy('created_at', 'desc');
    }
}
