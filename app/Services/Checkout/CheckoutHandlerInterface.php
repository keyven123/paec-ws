<?php

namespace App\Services\Checkout;

use App\Models\Transaction;

/**
 * Contract for module-specific transaction behaviour.
 *
 * The transactions table is a shared, module-agnostic payment header. Anything
 * that differs per module (post-payment fulfillment such as ticket generation
 * or booking confirmation) lives behind this interface and is resolved from the
 * transaction's polymorphic transactionable_type.
 */
interface CheckoutHandlerInterface
{
    /**
     * The morph map alias this handler is responsible for (e.g. 'event',
     * 'venue_inquiry'). Must match a key registered in Relation::morphMap().
     */
    public function morphAlias(): string;

    /**
     * Run side effects after a transaction is confirmed paid (ticket issuance,
     * commission/affiliate recording, notifications, booking confirmation, ...).
     */
    public function handlePaid(Transaction $transaction, bool $syncFulfillment = false): void;
}
