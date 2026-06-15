<?php

namespace App\Services\Checkout;

use App\Models\Transaction;

/**
 * Resolves the CheckoutHandlerInterface for a transaction based on its
 * polymorphic transactionable_type.
 */
class CheckoutHandlerRegistry
{
    /**
     * @var array<string, CheckoutHandlerInterface>
     */
    private array $handlers = [];

    /**
     * Morph alias used when a transaction has no transactionable_type set
     * (legacy rows created before the polymorphic columns existed).
     */
    private string $defaultAlias = 'event';

    public function register(CheckoutHandlerInterface $handler): void
    {
        $this->handlers[$handler->morphAlias()] = $handler;
    }

    public function resolveByAlias(?string $alias): ?CheckoutHandlerInterface
    {
        $alias = $alias ?: $this->defaultAlias;

        return $this->handlers[$alias] ?? null;
    }

    public function resolveFor(Transaction $transaction): ?CheckoutHandlerInterface
    {
        return $this->resolveByAlias($transaction->transactionable_type);
    }
}
