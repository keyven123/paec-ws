<?php

namespace App\Http\Requests\Organizer;

use App\Constants\GeneralConstants;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrganizerListCustomerRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string'],
            'sort' => ['nullable'],
            'per_page' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(array_values(GeneralConstants::GENERAL_STATUSES))],
        ];
    }
}
