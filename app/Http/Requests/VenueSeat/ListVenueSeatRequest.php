<?php

namespace App\Http\Requests\VenueSeat;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListVenueSeatRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['string'],
            'sort' => ['nullable'],
            'per_page' => ['integer'],
            'page' => ['integer'],
            'venue_uuid' => ['nullable', 'uuid'],
            'category' => ['nullable', Rule::in(['bronze', 'silver', 'gold', 'vip', 'svip'])],
            'color' => ['nullable', Rule::in(['bronze', 'silver', 'gold', 'red', 'green'])],
            'status' => ['nullable', 'string'],
            'col' => ['nullable', 'string'],
            'row' => ['nullable', 'integer'],
        ];
    }
}
