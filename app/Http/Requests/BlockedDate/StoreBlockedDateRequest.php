<?php

namespace App\Http\Requests\BlockedDate;

use Illuminate\Foundation\Http\FormRequest;

class StoreBlockedDateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'blocked_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
