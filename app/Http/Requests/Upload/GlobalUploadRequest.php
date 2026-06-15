<?php

namespace App\Http\Requests\Upload;

use App\Constants\GeneralConstants;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GlobalUploadRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:5072', 'mimes:png,jpg,jpeg,jfif,mp4'],
            'disk' => ['nullable', 'string'],
            'model_type' => ['nullable', 'string', Rule::in(array_values(GeneralConstants::MODEL))],
            'model_uuid' => ['nullable', 'uuid'],
            'category' => ['nullable', 'string'],
            'order_number' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'File is required.',
            'file.max' => 'File must be less than 2MB.',
            'file.mimes' => 'File must be a valid image (PNG, JPG, JPEG, JFIF) or video (MP4).',
        ];
    }
}
