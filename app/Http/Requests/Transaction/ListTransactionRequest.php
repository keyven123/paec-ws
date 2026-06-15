<?php

namespace App\Http\Requests\Transaction;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListTransactionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $user = auth('admin')->user();
        if ($user?->role && ! $user->role->is_admin) {
            $this->request->remove('organization_uuid');
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = auth('admin')->user();
        $isPlatformAdmin = $user?->role?->is_admin ?? false;

        return [
            'uuid' => ['nullable', 'uuid'],
            'organization_uuid' => [
                Rule::prohibitedIf(! $isPlatformAdmin),
                'nullable',
                'uuid',
                Rule::exists(Organization::class, 'uuid')->whereNull('deleted_at'),
            ],
            'q' => ['nullable', 'string'],
            'sort' => ['nullable'],
            'per_page' => ['integer'],
            'page' => ['integer'],
            'user_uuid' => ['nullable', 'uuid'],
            'event_uuid' => ['nullable', 'uuid'],
            'transactionable_type' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'payment_status' => ['nullable', 'string'],
            'order_status' => ['nullable', 'string'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'min:0'],
            'visit_start_date' => ['nullable', 'date'],
            'visit_end_date' => ['nullable', 'date', 'after_or_equal:visit_start_date'],
        ];
    }
}
