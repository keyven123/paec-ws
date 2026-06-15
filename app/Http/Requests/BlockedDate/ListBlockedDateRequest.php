<?php

namespace App\Http\Requests\BlockedDate;

use Illuminate\Foundation\Http\FormRequest;

class ListBlockedDateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string'],
        ];
    }
}
