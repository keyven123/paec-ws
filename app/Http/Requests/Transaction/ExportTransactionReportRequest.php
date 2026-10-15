<?php

namespace App\Http\Requests\Transaction;

use App\Models\Event;
use App\Models\Transaction;
use App\Services\TransactionReportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportTransactionReportRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'event_uuid' => ['nullable', 'uuid', Rule::exists(Event::class, 'uuid')],
            'order_status' => ['nullable', Rule::in(Transaction::ORDER_STATUS)],
            'date_type' => ['nullable', Rule::in([TransactionReportService::DATE_PURCHASE, TransactionReportService::DATE_VISIT])],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'event_uuid.exists' => 'The selected attraction no longer exists.',
            'end_date.after_or_equal' => 'The end date must be on or after the start date.',
        ];
    }
}
