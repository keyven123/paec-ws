<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class UserRegistrationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('phone_number')) {
            $phone = preg_replace('/[\s\-()]/', '', (string) $this->phone_number);

            if (preg_match('/^09\d{9}$/', $phone)) {
                $phone = '+63' . substr($phone, 1);
            } elseif (preg_match('/^9\d{9}$/', $phone)) {
                $phone = '+63' . $phone;
            } elseif (preg_match('/^63\d{10}$/', $phone)) {
                $phone = '+' . $phone;
            }

            $this->merge(['phone_number' => $phone]);
        }
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:500'],
            'phone_number' => ['required', 'string', 'regex:/^\+[1-9]\d{7,14}$/', $this->uniqueIgnoringGuests('phone_number')],
            'email' => ['required', 'email', 'max:255', $this->uniqueIgnoringGuests('email')],
            'password' => ['required', 'string', 'min:8', 'regex:/^(?=.*[0-9])(?=.*[A-Z])(?=.*[!@#$%^&*_@.]).{8,}$/', 'confirmed'],
            'terms_accepted' => ['required', 'accepted'],
        ];
    }

    /**
     * Guest-checkout customers are claimed on sign-up, so they must not block the email / phone.
     */
    private function uniqueIgnoringGuests(string $column): Unique
    {
        return Rule::unique('users', $column)->where(
            fn ($query) => $query->where(
                fn ($q) => $q->whereNull('provider')->orWhere('provider', '!=', User::GUEST_PROVIDER)
            )
        );
    }

    public function messages(): array
    {
        return [
            'password.confirmed' => 'The password confirmation does not match.',
            'terms_accepted.accepted' => 'You must agree to the terms and conditions.',
            'phone_number.regex' => 'Enter a valid mobile number (e.g. 09171234567).',
        ];
    }
}
