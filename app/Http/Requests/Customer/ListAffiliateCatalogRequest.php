<?php

namespace App\Http\Requests\Customer;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAffiliateCatalogRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'sort' => ['nullable', Rule::in(['asc', 'desc'])],
            'sort_by' => ['nullable', Rule::in(['created_at'])],
            'fun_type' => ['nullable', 'string', Rule::in(array_values(Event::FUN_TYPES))],
        ];
    }
}
