<?php

namespace App\Http\Requests\Upload;

use Illuminate\Foundation\Http\FormRequest;

class UploadRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:2048'],
            'type' => ['nullable', 'in:image,csv,xlsx,pdf,video,audio,other'],
            'disk' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'File is required.',
            'file.max' => 'File must be less than 2MB.',
            'file.mimes' => 'File must be a valid image (PNG, JPG, JPEG) or video (MP4).',
        ];
    }
}
