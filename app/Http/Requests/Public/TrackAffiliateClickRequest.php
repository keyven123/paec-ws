<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

class TrackAffiliateClickRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ref' => ['required', 'string', 'max:32'],
            'path' => ['nullable', 'string', 'max:512'],
        ];
    }
}
