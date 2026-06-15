<?php

namespace App\Services\Payments;

use App\Models\Transaction;

interface PaymentServiceInterface
{
    /**
     * Create a payment intent/order
     *
     * @param array $paymentData
     * @return array
     */
    public function createPayment(array $paymentData): array;

    /**
     * Capture/confirm a payment
     *
     * @param string $paymentId
     * @param array $additionalData
     * @return array
     */
    public function capturePayment(string $paymentId, array $additionalData = []): array;

    /**
     * Refund a payment
     *
     * @param string $paymentId
     * @param float $amount
     * @param string $reason
     * @return array
     */
    public function refundPayment(string $paymentId, float $amount, string $reason = ''): array;

    /**
     * Get payment status
     *
     * @param string $paymentId
     * @return array
     */
    public function getPaymentStatus(string $paymentId): array;

    /**
     * Verify webhook signature
     *
     * @param string $payload
     * @param string $signature
     * @return bool
     */
    public function verifyWebhook(string $payload, string $signature): bool;
}
