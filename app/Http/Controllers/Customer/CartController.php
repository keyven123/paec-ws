<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CartItemRequest;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\EventTicket;
use App\Services\TicketMarkupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CartController extends Controller
{
    public const MAX_ITEMS = 10;

    public function show(Request $request): JsonResponse
    {
        return $this->cartResponse(Cart::forUser($request->user()));
    }

    public function storeItem(CartItemRequest $request): JsonResponse
    {
        $cart = Cart::forUser($request->user());

        if ($cart->items()->count() >= self::MAX_ITEMS) {
            throw ValidationException::withMessages([
                'cart' => ['Your cart can hold up to ' . self::MAX_ITEMS . ' activities. Check out or remove one first.'],
            ]);
        }

        $item = $cart->items()->create($this->itemAttributes($request));
        $cart->touchChanged();

        return $this->cartResponse($cart, 201, $item);
    }

    public function updateItem(CartItemRequest $request, string $uuid): JsonResponse
    {
        $cart = Cart::forUser($request->user());
        $item = $this->findItem($cart, $uuid);

        $item->update($this->itemAttributes($request));
        $cart->touchChanged();

        return $this->cartResponse($cart, 200, $item);
    }

    public function destroyItem(Request $request, string $uuid): JsonResponse
    {
        $cart = Cart::forUser($request->user());
        $this->findItem($cart, $uuid)->delete();
        $cart->touchChanged();

        return $this->cartResponse($cart);
    }

    private function findItem(Cart $cart, string $uuid): CartItem
    {
        return $cart->items()->where('uuid', $uuid)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function itemAttributes(CartItemRequest $request): array
    {
        $payload = $request->validated();

        return [
            'event_uuid' => $payload['event_uuid'],
            'event_location_uuid' => $payload['event_location_uuid'] ?? null,
            'visit_date' => $payload['visit_date'],
            'promo_code_uuid' => $payload['promo_code_uuid'] ?? null,
            'tickets' => array_map(fn (array $ticket) => [
                'event_ticket_uuid' => $ticket['event_ticket_uuid'],
                'quantity' => (int) $ticket['quantity'],
            ], $payload['tickets']),
            // validated() would keep only the details keys that have their own rules.
            'details' => $request->input('details'),
        ];
    }

    private function cartResponse(Cart $cart, int $status = 200, ?CartItem $item = null): JsonResponse
    {
        $cartItems = $cart->items()->with('event:uuid,event_name,slug')->get();
        $eventTickets = EventTicket::query()
            ->whereIn('uuid', $cartItems->pluck('tickets')->flatten(1)->pluck('event_ticket_uuid')->unique())
            ->get()
            ->keyBy('uuid');

        $items = $cartItems->map(fn (CartItem $cartItem) => [
            'uuid' => $cartItem->uuid,
            'event_uuid' => $cartItem->event_uuid,
            'event_name' => $cartItem->event?->event_name,
            'event_slug' => $cartItem->event?->slug,
            'event_location_uuid' => $cartItem->event_location_uuid,
            'visit_date' => $cartItem->visit_date->toDateString(),
            'promo_code_uuid' => $cartItem->promo_code_uuid,
            'tickets' => array_map(function (array $ticket) use ($eventTickets) {
                $eventTicket = $eventTickets->get($ticket['event_ticket_uuid']);
                // What checkout charges for one ticket, so the cart shows the same total.
                $line = $eventTicket ? TicketMarkupService::buildOrderLine($eventTicket, 1) : null;

                return $ticket + [
                    'name' => $eventTicket?->name,
                    'unit_price' => $line ? $line['total_amount'] : null,
                    'original_unit_price' => $line ? round($line['price'] + $line['markup'] + $line['markup_discount'], 2) : null,
                ];
            }, $cartItem->tickets),
            'details' => $cartItem->details,
            'updated_at' => $cartItem->updated_at,
        ]);

        return response()->json(array_filter([
            'items' => $items->values()->all(),
            'item_uuid' => $item?->uuid,
        ], fn ($value) => $value !== null), $status);
    }
}
