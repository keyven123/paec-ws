<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class SendMerchantChatMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:4000', 'required_without:file'],
            'file' => [
                'nullable',
                'file',
                'max:' . (int) config('chat.attachment_max_kb', 10240),
                'mimetypes:' . implode(',', config('chat.attachment_mimes', [])),
            ],
            'send_as_proposal' => ['nullable', 'boolean'],
            'proposal_amount' => ['nullable', 'numeric', 'min:0'],
            'proposal_valid_until' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimetypes' => 'That file type is not allowed. Please upload a PDF, image or document.',
            'file.max' => 'The attachment is too large.',
            'body.required_without' => 'Type a message or attach a file.',
        ];
    }
}
