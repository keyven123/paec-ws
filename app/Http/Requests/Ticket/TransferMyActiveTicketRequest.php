<?php

namespace App\Http\Requests\Ticket;

use App\Constants\GeneralConstants;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransferMyActiveTicketRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request..
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
                    ->where('status', GeneralConstants::TICKET_STATUSES['ACTIVE'])
                    ->where(function ($query) {
                        $query->whereNull('deleted_at')
                            ->whereNull('used_at')
                            ->whereNull('transferred_at')
                            ->where('user_uuid', $this->user()->uuid);
                    }),
            ],
            'email' => [
                'required',
                'email',
                Rule::exists(User::class, 'email')
                    ->whereNull('deleted_at')
                    ->where('uuid', '!=', $this->user()->uuid)
                    ->where('status', GeneralConstants::GENERAL_STATUSES['ACTIVE'])
            ],
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
            'email.exists' => 'The selected email does not exist or is not active or is the same as the current user.',
            'email.email' => 'The selected email is not valid.',
            'email.required' => 'The email field is required.',
            'uuid.required' => 'The ticket id field is required.',
            'uuid.uuid' => 'The ticket id must be a valid ID.',
            'uuid.exists' => 'The selected ticket does not exist or is not active.',
        ];
    }
}
