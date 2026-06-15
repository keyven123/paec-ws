<?php

namespace App\Http\Requests\Category;

use App\Constants\GeneralConstants;
use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'uuid' => [
                'required',
                'uuid',
                Rule::exists(Category::class, 'uuid')
                    ->whereNull('deleted_at')
            ],
            'name' => ['sometimes', 'string', 'max:255', Rule::unique(Category::class, 'name')->ignore($this->route('uuid'), 'uuid')],
            'status' => ['sometimes', 'nullable', Rule::in(array_values(GeneralConstants::GENERAL_STATUSES))],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'uuid' => $this->route('uuid')
        ]);
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'This category name already exists.',
        ];
    }
}
