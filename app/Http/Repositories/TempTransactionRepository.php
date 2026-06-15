<?php

namespace App\Http\Repositories;

use App\Constants\GeneralConstants;
use App\Exceptions\NoResourceFoundException;
use App\Models\TempTransactionOrder;
use App\Models\TempTransaction;
use App\Helpers\GeneralHelper;
use App\Models\Event;
use App\Services\ActivityComplianceService;
use App\Services\TicketMarkupService;
use App\Models\TicketCoupon;
use App\Models\Transaction;
use App\Models\TransactionOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class TempTransactionRepository
{
    /**
     * @param TempTransaction $tempTransaction
     * @param TempTransactionOrder $tempTransactionOrder
     * @param Transaction $transaction
     */
    public function __construct(
        protected TempTransaction $tempTransaction,
        protected TempTransactionOrder $tempTransactionOrder,
        protected Transaction $transaction
    ) {
    }

    public function getSpecificTempTransactionByUser(string $userUuid, array $filters): TempTransaction | null
    {
        return $this->tempTransaction
            ->with(['tempTransactionOrders'])
            ->ownedBy($userUuid)
            ->filters($filters)
            ->first();
    }

    public function fetchOrThrow(string $key, string $value): TempTransaction
    {
        $tempTransaction = $this->tempTransaction->with([
            'tempTransactionOrders.eventTicket',
            'schedule',
            'scheduleTime',
            'event',
        ])->where($key, $value)->first();
        if (is_null($tempTransaction)) {
            throw new NoResourceFoundException('Temp transaction not found');
        }
        return $tempTransaction;
    }

    public function create(array $payload): TempTransaction
    {
        $tempTransactionPayload = $this->filterTempTransactionPayload($payload);

        return $this->tempTransaction->create($tempTransactionPayload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function filterTempTransactionPayload(array $payload): array
    {
        $numericFields = [
            'total_amount',
            'sub_total',
            'markup_value',
            'markup_amount',
            'markup_discount',
            'tax_amount',
            'discount',
            'promo_code_discount',
        ];

        $filtered = GeneralHelper::unsetUnknownAndNullFields($payload, TempTransaction::DATA);

        foreach ($numericFields as $field) {
            if (array_key_exists($field, $payload) && in_array($field, TempTransaction::DATA, true)) {
                $filtered[$field] = $payload[$field];
            }
        }

        return $filtered;
    }

    /**
     * Align header totals with order lines and refresh tax / total (markup_amount = sum of line markup).
     */
    public function syncPricingTotals(TempTransaction $tempTransaction, float $promoCodeDiscount): TempTransaction
    {
        $tempTransaction->loadMissing(['tempTransactionOrders', 'event']);
        $aggregated = TicketMarkupService::aggregateFromPersistedOrders($tempTransaction->tempTransactionOrders);

        $event = $tempTransaction->event ?? Event::query()->find($tempTransaction->event_uuid);
        $complianceAmounts = $event
            ? ActivityComplianceService::applyToCheckoutAmounts($event, [
                'sub_total' => $aggregated['sub_total'],
                'discount' => $aggregated['discount'],
                'promo_code_discount' => $promoCodeDiscount,
                'markup_amount' => $aggregated['markup_amount'],
            ])
            : [
                'tax_amount' => $tempTransaction->tax_amount,
                'total_amount' => $tempTransaction->total_amount,
            ];

        $tempTransaction->update([
            'sub_total' => $aggregated['sub_total'],
            'discount' => $aggregated['discount'],
            'markup_type' => $aggregated['markup_type'],
            'markup_value' => $aggregated['markup_value'],
            'markup_amount' => $aggregated['markup_amount'],
            'markup_discount' => $aggregated['markup_discount'],
            'promo_code_discount' => $promoCodeDiscount,
            'tax_amount' => $complianceAmounts['tax_amount'],
            'total_amount' => $complianceAmounts['total_amount'],
        ]);

        return $tempTransaction->fresh(['tempTransactionOrders', 'event']);
    }

    public function createTempTransactionOrders(array $tempTransactionOrders, string $userUuid, string $tempTransactionUuid): array
    {
        $totalDiscount = 0;
        $createdTempTransactionOrders = [];
        foreach ($tempTransactionOrders as $tempTransactionOrder) {
            $tempTransactionOrder = $this->tempTransactionOrder->create([
                'user_uuid' => $userUuid,
                'temp_transaction_uuid' => $tempTransactionUuid,
                'event_ticket_uuid' => $tempTransactionOrder['event_ticket_uuid'],
                'quantity' => $tempTransactionOrder['quantity'],
                'price' => $tempTransactionOrder['price'],
                'markup_type' => $tempTransactionOrder['markup_type'] ?? null,
                'markup_value' => $tempTransactionOrder['markup_value'] ?? null,
                'markup' => $tempTransactionOrder['markup'] ?? 0,
                'markup_discount' => $tempTransactionOrder['markup_discount'] ?? 0,
                'total_amount' => $tempTransactionOrder['total_amount'],
                'discount' => $tempTransactionOrder['discount'],
                'valid_until' => $tempTransactionOrder['valid_until'] ?? null,
                'seats' => $tempTransactionOrder['seats'] ?? null,
            ]);
            $createdTempTransactionOrders[] = $tempTransactionOrder;
        }
        return [
            'data' => $createdTempTransactionOrders,
            'total_discount' => $totalDiscount,
        ];
    }

    public function update(TempTransaction $tempTransaction, array $payload): bool
    {
        $tempTransactionPayload = $this->filterTempTransactionPayload($payload);

        return $tempTransaction->update($tempTransactionPayload);
    }

    public function updateTempTransactionOrders(TempTransaction $tempTransaction, array $payloadTempTransactionOrders): array
    {
        $tempTransaction->tempTransactionOrders()->delete();
        $updatedTempTransactionOrders = [];
        foreach ($payloadTempTransactionOrders as $tempTransactionOrder) {
            $tempTransactionOrder = $this->tempTransactionOrder->create([
                'user_uuid' => $tempTransaction->user_uuid,
                'temp_transaction_uuid' => $tempTransaction->uuid,
                'event_ticket_uuid' => $tempTransactionOrder['event_ticket_uuid'],
                'quantity' => $tempTransactionOrder['quantity'],
                'price' => $tempTransactionOrder['price'],
                'markup_type' => $tempTransactionOrder['markup_type'] ?? null,
                'markup_value' => $tempTransactionOrder['markup_value'] ?? null,
                'markup' => $tempTransactionOrder['markup'] ?? 0,
                'markup_discount' => $tempTransactionOrder['markup_discount'] ?? 0,
                'total_amount' => $tempTransactionOrder['total_amount'],
                'discount' => $tempTransactionOrder['discount'],
                'valid_until' => $tempTransactionOrder['valid_until'] ? Carbon::parse($tempTransactionOrder['valid_until'])->endOfDay()->format('Y-m-d H:i:s') : null,
                'seats' => $tempTransactionOrder['seats'] ?? null,
            ]);
            $updatedTempTransactionOrders[] = $tempTransactionOrder;
        }
        return $updatedTempTransactionOrders;
    }

    public function checkout(TempTransaction $tempTransaction, array $payload): array
    {
        // get voucher or discount here then subtract from total amount
        $discount = floatval($tempTransaction->discount);
        if ($tempTransaction->voucher_uuid) {
            // $voucher = $this->voucher->find($tempTransaction->voucher_uuid);
            // $discount = $voucher->discount;
        }

        $prefix = $tempTransaction->event->ticket_prefix ?? 'OR';
        $transaction = $this->transaction->create([
            'user_uuid' => $tempTransaction->user_uuid,
            'transactionable_type' => 'event',
            'transactionable_uuid' => $tempTransaction->event_uuid,
            'event_uuid' => $tempTransaction->event_uuid,
            'event_location_uuid' => $tempTransaction->event_location_uuid,
            'schedule_uuid' => $tempTransaction->schedule_uuid ?? null,
            'schedule_time_uuid' => $tempTransaction->schedule_time_uuid ?? null,
            'organization_uuid' => $tempTransaction->organization_uuid,
            'affiliate_partner_uuid' => $tempTransaction->affiliate_partner_uuid,
            'payment_order_id' => GeneralHelper::generatePaymentOrderId(),
            'payment_provider' => $payload['payment_provider'],
            'order_number' => GeneralHelper::generateOrderNumber($prefix),
            'total_amount' => $tempTransaction->total_amount,
            'sub_total' => $tempTransaction->sub_total,
            'tax_amount' => $tempTransaction->tax_amount,
            'discount' => $discount,
            'markup_type' => $tempTransaction->markup_type,
            'markup_value' => $tempTransaction->markup_value,
            'markup_amount' => $tempTransaction->markup_amount ?? 0,
            'markup_discount' => $tempTransaction->markup_discount ?? 0,
            'promo_code_uuid' => $tempTransaction->promo_code_uuid ?? null,
            'promo_code_discount' => $tempTransaction->promo_code_discount ?? 0,
        ]);

        $tempTransaction->loadMissing('event');
        $compliance = ActivityComplianceService::applyToCheckoutAmounts($tempTransaction->event, [
            'sub_total' => $tempTransaction->sub_total,
            'discount' => $tempTransaction->discount,
            'promo_code_discount' => $tempTransaction->promo_code_discount ?? 0,
            'markup_amount' => $tempTransaction->markup_amount ?? 0,
        ]);
        ActivityComplianceService::recordForTransaction($transaction, $compliance['compliance_lines']);

        $tempTransaction->load('tempTransactionOrders.eventTicket');
        $tempTransactionOrders = $tempTransaction->tempTransactionOrders;

        $tickets = [];
        $transactionOrders = [];
        $otherInfoIndex = 0;
        $otherInfo = $payload['other_info'] ?? [];

        foreach ($tempTransactionOrders as $tempTransactionOrder) {
            $eventTicket = $tempTransactionOrder->eventTicket;
            $validUntil = null;
            if ($tempTransactionOrder->valid_until) {
                $validUntil = Carbon::parse($tempTransactionOrder->valid_until)->endOfDay();
            } elseif ($eventTicket) {
                if ($eventTicket->visit_policy === 'flexible' && $eventTicket->validity_days) {
                    $validUntil = now()->addDays((int) $eventTicket->validity_days)->endOfDay();
                }
            }

            if (is_array($tempTransactionOrder->seats) && count($tempTransactionOrder->seats) > 0) {
                $seats = $tempTransactionOrder->seats;
                foreach ($seats as $seat) {
                    $ticketData = [
                        'user_uuid' => $tempTransaction->user_uuid,
                        'organization_uuid' => $tempTransaction->organization_uuid,
                        'transaction_uuid' => $transaction->uuid,
                        'event_uuid' => $transaction->event_uuid,
                        'event_location_uuid' => $tempTransaction->event_location_uuid,
                        'event_ticket_uuid' => $tempTransactionOrder->event_ticket_uuid,
                        'venue_seat_uuid' => $seat['uuid'],
                        'ticket_number' => GeneralHelper::generateUuidTicketNumber($transaction->event->ticket_prefix ?? 'TKT'),
                        'col' => $seat['seat_no'],
                        'row' => $seat['row'],
                        'attendee_name' => $transaction->user->full_name,
                        'attendee_email' => $transaction->user->email,
                        'attendee_contact' => $transaction->user->phone_number,
                        'visit_policy' => $eventTicket->visit_policy,
                        'status' => GeneralConstants::TICKET_STATUSES['PENDING'],
                        'price' => $tempTransactionOrder->price - ($tempTransactionOrder->discount / $tempTransactionOrder->quantity),
                        'discount' => $tempTransactionOrder->discount / $tempTransactionOrder->quantity,
                    ];
                    if ($validUntil) {
                        $ticketData['valid_until'] = $validUntil;
                    }

                    // Add other_info if available and update default fields if provided
                    if (isset($otherInfo[$otherInfoIndex]) && is_array($otherInfo[$otherInfoIndex])) {
                        // Override default fields if provided in other_info
                        if (isset($otherInfo[$otherInfoIndex]['attendee_name']) && !empty($otherInfo[$otherInfoIndex]['attendee_name'])) {
                            $ticketData['attendee_name'] = $otherInfo[$otherInfoIndex]['attendee_name'];
                        }
                        if (isset($otherInfo[$otherInfoIndex]['attendee_email']) && !empty($otherInfo[$otherInfoIndex]['attendee_email'])) {
                            $ticketData['attendee_email'] = $otherInfo[$otherInfoIndex]['attendee_email'];
                        }
                        if (isset($otherInfo[$otherInfoIndex]['attendee_contact']) && !empty($otherInfo[$otherInfoIndex]['attendee_contact'])) {
                            $ticketData['attendee_contact'] = $otherInfo[$otherInfoIndex]['attendee_contact'];
                        }
                        $ticketData['other_info'] = $otherInfo[$otherInfoIndex];
                    }

                    $ticket = $transaction->tickets()->create($ticketData);
                    $tickets[] = $ticket;
                    $eventTicketCoupons = $eventTicket->coupons;
                    foreach ($eventTicketCoupons as $key => $eventTicketCoupon) {
                        if ($eventTicketCoupon->once_only && $key > 0) {
                            continue;
                        }
                        $ticket->coupons()->create([
                            'user_uuid' => $tempTransaction->user_uuid,
                            'event_uuid' => $transaction->event_uuid,
                            'event_ticket_coupon_uuid' => $eventTicketCoupon->uuid,
                            'name' => $eventTicketCoupon->name,
                            'qr_code' => GeneralHelper::generateQrCode(new TicketCoupon(), 'COUPON_'),
                        ]);
                    }
                    $otherInfoIndex++;
                }
            } else {
                for ($i = 0; $i < $tempTransactionOrder->quantity; $i++) {
                    $ticketData = [
                        'user_uuid' => $tempTransaction->user_uuid,
                        'organization_uuid' => $tempTransaction->organization_uuid,
                        'transaction_uuid' => $transaction->uuid,
                        'event_uuid' => $transaction->event_uuid,
                        'event_location_uuid' => $tempTransaction->event_location_uuid,
                        'event_ticket_uuid' => $tempTransactionOrder->event_ticket_uuid,
                        'ticket_number' => GeneralHelper::generateUuidTicketNumber($transaction->event->ticket_prefix ?? 'TKT'),
                        'visit_policy' => $eventTicket->visit_policy,
                        'attendee_name' => $transaction->user->full_name,
                        'attendee_email' => $transaction->user->email,
                        'attendee_contact' => $transaction->user->phone_number,
                        'status' => GeneralConstants::GENERAL_STATUSES['INACTIVE'],
                        'price' => $tempTransactionOrder->price - ($tempTransactionOrder->discount / $tempTransactionOrder->quantity),
                        'discount' => $tempTransactionOrder->discount / $tempTransactionOrder->quantity,
                        'is_bundle' => $eventTicket->is_bundle ?? false,
                        'bundle_quantity' => $eventTicket->bundle_quantity ?? null,
                    ];
                    if ($validUntil) {
                        $ticketData['valid_until'] = $validUntil;
                    }

                    // Add other_info if available and update default fields if provided
                    if (isset($otherInfo[$otherInfoIndex]) && is_array($otherInfo[$otherInfoIndex])) {
                        // Override default fields if provided in other_info
                        $ticketData['other_info'] = $otherInfo[$otherInfoIndex];
                        if (isset($otherInfo[$otherInfoIndex]['attendee_name']) && !empty($otherInfo[$otherInfoIndex]['attendee_name'])) {
                            $ticketData['attendee_name'] = $otherInfo[$otherInfoIndex]['attendee_name'];
                            unset($ticketData['other_info']['attendee_name']);
                        }
                        if (isset($otherInfo[$otherInfoIndex]['attendee_email']) && !empty($otherInfo[$otherInfoIndex]['attendee_email'])) {
                            $ticketData['attendee_email'] = $otherInfo[$otherInfoIndex]['attendee_email'];
                            unset($ticketData['other_info']['attendee_name']);
                        }
                        if (isset($otherInfo[$otherInfoIndex]['attendee_contact']) && !empty($otherInfo[$otherInfoIndex]['attendee_contact'])) {
                            $ticketData['attendee_contact'] = $otherInfo[$otherInfoIndex]['attendee_contact'];
                            unset($ticketData['other_info']['attendee_name']);
                        }
                    }

                    $eventTicketCoupons = $eventTicket->coupons;
                    if ($eventTicket->is_bundle) {
                        for ($i = 0; $i < $eventTicket->bundle_quantity; $i++) {
                            $ticketData['ticket_number'] = GeneralHelper::generateUuidTicketNumber($transaction->event->ticket_prefix ?? 'TKTB') . '-' . ($i + 1);
                            $ticket = $transaction->tickets()->create($ticketData);
                            $tickets[] = $ticket;

                            foreach ($eventTicketCoupons as $key => $eventTicketCoupon) {
                                if ($eventTicketCoupon->once_only && $i > 0) {
                                    continue;
                                }
                                $ticket->coupons()->create([
                                    'user_uuid' => $tempTransaction->user_uuid,
                                    'event_uuid' => $transaction->event_uuid,
                                    'event_ticket_coupon_uuid' => $eventTicketCoupon->uuid,
                                    'name' => $eventTicketCoupon->name,
                                    'qr_code' => GeneralHelper::generateQrCode(new TicketCoupon(), 'COUPON_'),
                                ]);
                            }
                        }
                    } else {
                        $ticket = $transaction->tickets()->create($ticketData);
                        $tickets[] = $ticket;

                        foreach ($eventTicketCoupons as $eventTicketCoupon) {
                            $ticket->coupons()->create([
                                'user_uuid' => $tempTransaction->user_uuid,
                                'event_uuid' => $transaction->event_uuid,
                                'event_ticket_coupon_uuid' => $eventTicketCoupon->uuid,
                                'name' => $eventTicketCoupon->name,
                                'qr_code' => GeneralHelper::generateQrCode(new TicketCoupon(), 'COUPON_'),
                            ]);
                        }
                    }

                    $otherInfoIndex++;
                }
            }
            $transactionOrder = TransactionOrder::create([
                'user_uuid' => $tempTransaction->user_uuid,
                'transaction_uuid' => $transaction->uuid,
                'event_ticket_uuid' => $tempTransactionOrder->event_ticket_uuid,
                'quantity' => $tempTransactionOrder->quantity,
                'price' => $tempTransactionOrder->price,
                'markup_type' => $tempTransactionOrder->markup_type,
                'markup_value' => $tempTransactionOrder->markup_value,
                'markup' => $tempTransactionOrder->markup ?? 0,
                'markup_discount' => $tempTransactionOrder->markup_discount ?? 0,
                'total_amount' => $tempTransactionOrder->total_amount,
                'discount' => $tempTransactionOrder->discount,
                'seats' => $tempTransactionOrder->seats ?? [],
                'valid_until' => $tempTransactionOrder->valid_until ?? null,
            ]);
            $transactionOrders[] = $transactionOrder;
        }
        return [
            'transaction' => $transaction,
            'tickets' => $tickets,
            'orders' => $transactionOrders,
            'event' => $transaction->event,
        ];
    }

    public function delete(TempTransaction $tempTransaction): void
    {
        $tempTransaction->tempTransactionOrders()->delete();
        $tempTransaction->delete();
    }
}
