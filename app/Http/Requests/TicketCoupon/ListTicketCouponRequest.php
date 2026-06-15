<?php

namespace App\Http\Requests\TicketCoupon;

use App\Constants\GeneralConstants;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListTicketCouponRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'sort_by' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'status' => ['nullable', 'string', Rule::in(array_values(GeneralConstants::TICKET_COUPON_STATUSES))],
            'event_uuid' => ['nullable', 'uuid', 'exists:events,uuid'],
            'schedule_uuid' => ['nullable', 'uuid', 'exists:schedules,uuid'],
            'schedule_time_uuid' => ['nullable', 'uuid', 'exists:schedule_times,uuid'],
        ];
    }
}
