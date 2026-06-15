<?php

namespace App\Http\Requests\Event;

use App\Models\EventSection;
use Illuminate\Foundation\Http\FormRequest;

class BrowseByCityRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): array
    {
        $validated = parent::validated($key, $default);

        if (!isset($validated['type']) || $validated['type'] === '') {
            $validated['type'] = EventSection::AMUSEMENT_SECTION;
        }

        if (!isset($validated['limit'])) {
            $validated['limit'] = 12;
        }

        return $validated;
    }
}
