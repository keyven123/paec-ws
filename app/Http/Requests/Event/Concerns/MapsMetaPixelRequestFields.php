<?php

namespace App\Http\Requests\Event\Concerns;

trait MapsMetaPixelRequestFields
{
    protected function prepareMetaPixelFieldsForValidation(): void
    {
        $merge = [];

        if ($this->has('track_event_meta')) {
            $merge['track_event_meta'] = $this->normalizeBooleanInput($this->input('track_event_meta'));
        } elseif ($this->has('enable_meta_pixel')) {
            $merge['track_event_meta'] = $this->normalizeBooleanInput($this->input('enable_meta_pixel'));
        }

        if ($this->filled('meta_pixel_access_token') && !$this->filled('meta_pixel_key')) {
            $merge['meta_pixel_key'] = $this->input('meta_pixel_access_token');
        }

        if (!empty($merge)) {
            $this->merge($merge);
        }
    }

    private function normalizeBooleanInput(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }
}
