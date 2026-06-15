<?php

namespace App\Http\Requests\EventTicket;

use App\Constants\GeneralConstants;
use App\Models\Event;
use App\Models\EventSection;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkCreateEventTicketsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $tickets = $this->input('tickets', []);
        if (! is_array($tickets)) {
            return;
        }
        foreach ($tickets as $i => $t) {
            if (! is_array($t)) {
                continue;
            }
            foreach (['schedule_uuid', 'schedule_time_uuid'] as $k) {
                if (array_key_exists($k, $t) && $t[$k] === '') {
                    $tickets[$i][$k] = null;
                }
            }
        }
        $this->merge(['tickets' => $tickets]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $event = Event::with('eventSection')->find($this->input('event_uuid'));
        $scheduleOptional = $event && (
            $event->event_type == Event::EVENT_TYPES['DAILY']
            || $event->eventSection?->name === EventSection::AMUSEMENT_SECTION
        );

        if ($scheduleOptional) {
            $scheduleUuidRule = 'nullable';
            $scheduleTimeUuidRule = 'nullable';
        } else {
            $scheduleUuidRule = 'required';
            $scheduleTimeUuidRule = 'required';
        }

        return [
            'event_uuid' => [
                'required',
                'uuid',
                Rule::exists(Event::class, 'uuid')->whereNull('deleted_at'),
            ],
            'tickets' => ['required', 'array', 'min:1'],
            'tickets.*.schedule_uuid' => [
                $scheduleUuidRule,
                'uuid',
                Rule::exists(Schedule::class, 'uuid')->whereNull('deleted_at'),
            ],
            'tickets.*.schedule_time_uuid' => [
                $scheduleTimeUuidRule,
                'uuid',
                Rule::exists(ScheduleTime::class, 'uuid')->whereNull('deleted_at'),
            ],
            'tickets.*.code' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('event_tickets', 'code')
                    ->where('event_uuid', $this->input('event_uuid'))
                    ->whereNull('deleted_at'),
            ],
            'tickets.*.name' => ['required', 'string', 'max:255'],
            'tickets.*.description' => ['nullable', 'string'],
            'tickets.*.price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'tickets.*.is_bundle' => ['nullable', 'boolean'],
            'tickets.*.bundle_quantity' => ['nullable', 'integer', 'min:1'],
            'tickets.*.discount_type' => ['nullable', 'string', Rule::in(array_values(GeneralConstants::DISCOUNT_TYPES))],
            'tickets.*.discount_value' => ['nullable', 'numeric', 'min:0'],
            'tickets.*.bundle_tickets' => ['nullable', 'array'],
            'tickets.*.bundle_tickets.*' => ['uuid', 'exists:event_tickets,uuid'],
            'tickets.*.available_from' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'tickets.*.available_to' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'tickets.*.display_order' => ['nullable', 'integer', 'min:1'],
            'tickets.*.max_ticket' => ['nullable', 'integer', 'min:0'],
            'tickets.*.ticket_limit_per_user' => ['nullable', 'integer', 'min:1'],
            'tickets.*.is_unlimited' => ['required', 'boolean'],
            'tickets.*.is_virtual' => ['nullable', 'boolean'],
            'tickets.*.virtual_event_url' => ['nullable', 'url', 'max:500'],
            'tickets.*.visit_policy' => ['nullable', 'string', Rule::in(['priority', 'flexible'])],
            'tickets.*.validity_days' => ['nullable', 'integer', 'min:1'],
            'tickets.*.status' => ['nullable', 'string', Rule::in(GeneralConstants::GENERAL_STATUSES)],
            'tickets.*.bg_color' => ['nullable', 'string', 'max:255'],
            'tickets.*.with_coupon' => ['nullable', 'boolean'],
            'tickets.*.with_discount' => ['nullable', 'boolean'],
            'tickets.*.coupons' => ['nullable', 'array'],
            'tickets.*.coupons.*' => ['required', 'array'],
            'tickets.*.coupons.*.name' => ['required', 'string', 'max:255'],
            'tickets.*.coupons.*.once_only' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $tickets = $this->input('tickets', []);
            $codes = collect($tickets)->pluck('code')->map(fn ($c) => is_string($c) ? strtolower(trim($c)) : '')->filter(fn ($c) => $c !== '');
            if ($codes->count() !== $codes->unique()->count()) {
                $validator->errors()->add('tickets', 'Each ticket code must be unique in this request.');
            }

            foreach ($tickets as $index => $ticket) {
                if (! is_array($ticket)) {
                    continue;
                }

                $from = $ticket['available_from'] ?? null;
                $to = $ticket['available_to'] ?? null;
                if ($from && $to && strtotime((string) $to) < strtotime((string) $from)) {
                    $validator->errors()->add(
                        "tickets.$index.available_to",
                        'The available to date must be after or equal to the available from date.'
                    );
                }

                if (($ticket['visit_policy'] ?? null) === 'flexible') {
                    $validityDays = $ticket['validity_days'] ?? null;
                    if ($validityDays === null || $validityDays === '' || (int) $validityDays < 1) {
                        $validator->errors()->add("tickets.$index.validity_days", 'Number of days is required when visit policy is Flexible Access.');
                    }
                }

                if (($ticket['is_virtual'] ?? false) === true) {
                    $url = $ticket['virtual_event_url'] ?? null;
                    if (empty($url)) {
                        $validator->errors()->add("tickets.$index.virtual_event_url", 'The virtual activity link is required when ticket is virtual.');
                    }
                }

                if (! empty($ticket['with_coupon'])) {
                    $couponList = $ticket['coupons'] ?? [];
                    if (! is_array($couponList) || count($couponList) === 0) {
                        $validator->errors()->add("tickets.$index.coupons", 'At least one coupon is required when coupons are enabled.');
                        continue;
                    }
                    $hasName = false;
                    foreach ($couponList as $c) {
                        $name = is_array($c) ? trim((string) ($c['name'] ?? '')) : trim((string) $c);
                        if ($name !== '') {
                            $hasName = true;
                            break;
                        }
                    }
                    if (! $hasName) {
                        $validator->errors()->add("tickets.$index.coupons", 'At least one coupon name is required when coupons are enabled.');
                    }
                }

                $hasDiscount = ! empty($ticket['discount_type'])
                    || (($ticket['discount_value'] ?? null) !== null && $ticket['discount_value'] !== '');
                if ($hasDiscount) {
                    $dt = $ticket['discount_type'] ?? null;
                    $dv = $ticket['discount_value'] ?? null;
                    $price = isset($ticket['price']) ? (float) $ticket['price'] : null;
                    if (empty($dt)) {
                        $validator->errors()->add("tickets.$index.discount_type", 'Discount type is required when discount is set.');
                    }
                    if ($dv === null || $dv === '' || (float) $dv <= 0) {
                        $validator->errors()->add("tickets.$index.discount_value", 'Discount value is required and must be greater than 0 when discount is set.');
                    } elseif ($dt === GeneralConstants::DISCOUNT_TYPES['PERCENTAGE']) {
                        $f = (float) $dv;
                        if ($f < 0 || $f > 100) {
                            $validator->errors()->add("tickets.$index.discount_value", 'Discount percentage must be between 0 and 100.');
                        }
                    } elseif ($dt === GeneralConstants::DISCOUNT_TYPES['AMOUNT'] && $price !== null) {
                        $f = (float) $dv;
                        if ($f < 0 || $f > $price) {
                            $validator->errors()->add("tickets.$index.discount_value", "Discount amount must be between 0 and {$price}.");
                        }
                    }
                }

                if (! empty($ticket['is_bundle'])) {
                    if (empty($ticket['bundle_quantity']) || (int) $ticket['bundle_quantity'] < 1) {
                        $validator->errors()->add("tickets.$index.bundle_quantity", 'Quantity is required and must be at least 1 when bundle is enabled.');
                    }
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'tickets.*.code.unique' => 'This code is already used for another ticket in this event.',
            'event_uuid.exists' => 'The selected event does not exist.',
        ];
    }

    /**
     * Normalize payload for repository create + coupon sync.
     *
     * @return array{event_uuid: string, tickets: array<int, array<string, mixed>>}
     */
    public function validatedTicketsPayload(): array
    {
        $validated = $this->validated();
        $eventUuid = $validated['event_uuid'];
        $tickets = [];

        foreach ($validated['tickets'] as $row) {
            $withDiscount = ! empty($row['with_discount'] ?? false);
            unset($row['with_discount']);
            if (! $withDiscount) {
                $row['discount_type'] = null;
                $row['discount_value'] = null;
            }

            $withCoupon = ! empty($row['with_coupon']);
            if (! $withCoupon) {
                $row['coupons'] = [];
            } elseif (! empty($row['coupons']) && is_array($row['coupons'])) {
                $row['coupons'] = array_values(array_filter($row['coupons'], function ($c) {
                    $name = is_array($c) ? trim((string) ($c['name'] ?? '')) : trim((string) $c);

                    return $name !== '';
                }));
            }
            $row['event_uuid'] = $eventUuid;
            $tickets[] = $row;
        }

        return ['event_uuid' => $eventUuid, 'tickets' => $tickets];
    }
}
