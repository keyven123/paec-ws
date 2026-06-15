<?php

namespace App\Http\Requests\Category;

use Illuminate\Foundation\Http\FormRequest;
use App\Constants\GeneralConstants;
use App\Models\Category;
use Illuminate\Validation\Rule;

class ListCategoryRequest extends FormRequest
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
            'type' => ['nullable'],
            'status' => ['nullable', Rule::in(GeneralConstants::GENERAL_STATUSES)],
            'code' => ['nullable', Rule::exists(Category::class, 'code')],
        ];
    }
}
