<?php

namespace App\Http\Requests\Customer;

class GuestTempTransactionRequest extends TempTransactionRequest
{
    protected function prepareForValidation(): void
    {
        $guest = $this->input('guest');
        if (! is_array($guest) || ! isset($guest['phone_number'])) {
            return;
        }

        $phone = preg_replace('/[\s\-()]/', '', (string) $guest['phone_number']);

        if (preg_match('/^09\d{9}$/', $phone)) {
            $phone = '+63' . substr($phone, 1);
        } elseif (preg_match('/^9\d{9}$/', $phone)) {
            $phone = '+63' . $phone;
        } elseif (preg_match('/^63\d{10}$/', $phone)) {
            $phone = '+' . $phone;
        }

        $guest['phone_number'] = $phone;
        $this->merge(['guest' => $guest]);
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'guest' => ['required', 'array'],
            'guest.first_name' => ['required', 'string', 'max:255'],
            'guest.last_name' => ['required', 'string', 'max:255'],
            'guest.address' => ['required', 'string', 'max:500'],
            'guest.email' => ['required', 'email', 'max:255'],
            'guest.phone_number' => ['required', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'guest.first_name.required' => 'First name is required.',
            'guest.last_name.required' => 'Last name is required.',
            'guest.address.required' => 'Address is required.',
            'guest.email.required' => 'Email address is required.',
            'guest.email.email' => 'Enter a valid email address.',
            'guest.phone_number.required' => 'Mobile number is required.',
            'guest.phone_number.regex' => 'Enter a valid mobile number (e.g. 09171234567).',
        ]);
    }
}
