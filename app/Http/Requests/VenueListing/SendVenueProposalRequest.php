<?php

namespace App\Http\Requests\VenueListing;

use Illuminate\Foundation\Http\FormRequest;

class SendVenueProposalRequest extends FormRequest
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
            'proposal_amount' => ['required', 'numeric', 'min:0'],
            'proposal_valid_until' => ['required', 'date'],
            'file' => ['required', 'file', 'max:10240'],
        ];
    }
}
