<?php

namespace App\Http\Requests\Event;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEventAffiliateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'affiliate_enabled' => ['required', 'boolean'],
            'affiliate_commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'affiliate_ends_at' => ['nullable', 'date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $percent = $this->input('affiliate_commission_percent');
        if ($percent === '' || $percent === null) {
            $this->merge(['affiliate_commission_percent' => null]);
        }
        $ends = $this->input('affiliate_ends_at');
        if ($ends === '' || $ends === null) {
            $this->merge(['affiliate_ends_at' => null]);
        }
        if (!$this->boolean('affiliate_enabled')) {
            $this->merge([
                'affiliate_commission_percent' => null,
                'affiliate_ends_at' => null,
            ]);
        }
    }
}
