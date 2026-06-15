<?php

namespace App\Http\Requests\Upload;

use App\Constants\GeneralConstants;
use App\Models\Upload;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeleteUploadRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'uuid' => ['required', 'uuid', Rule::exists(Upload::class, 'uuid')],
            'model_type' => ['nullable', 'string', Rule::in(array_values(GeneralConstants::MODEL))],
            'model_uuid' => ['nullable', 'uuid'],
            'category' => ['nullable', 'string'],
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
}
