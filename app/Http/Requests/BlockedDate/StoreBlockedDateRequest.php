<?php

namespace App\Http\Requests\BlockedDate;

use Illuminate\Foundation\Http\FormRequest;

class StoreBlockedDateRequest extends FormRequest
{
    public const MAX_RANGE_DAYS = 366;

    public function rules(): array
    {
        return [
            'blocked_date' => ['required', 'date'],
            'blocked_date_end' => ['nullable', 'date', 'after_or_equal:blocked_date'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'blocked_date_end.after_or_equal' => 'The end date must be on or after the start date.',
        ];
    }
}
