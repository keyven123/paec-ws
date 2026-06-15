<?php

namespace App\Http\Requests\Ticket;

use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMyTicketRequest extends FormRequest
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
                Rule::exists(Ticket::class, 'uuid')
                    ->where(function ($query) {
                        $query->whereNull('deleted_at')
                            ->whereNull('used_at')
                            ->whereNull('transferred_at')
                            ->where('user_uuid', $this->user()->uuid);
                    }),
            ],
            'attendee_name' => ['sometimes', 'string', 'max:50'],
            'attendee_email' => ['sometimes', 'email', 'max:50'],
            'attendee_contact' => ['nullable', 'string', 'max:20'],
            'other_info' => ['sometimes', 'array'],
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
            'uuid.required' => 'The ticket id field is required.',
            'uuid.uuid' => 'The ticket id must be a valid ID.',
            'uuid.exists' => 'The selected ticket does not exist or is not active.',
        ];
    }
}
