<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CmsSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    protected $casts = [
        'value' => 'json',
    ];

    public static function getValue(string $key, mixed $default = null): mixed
    {
        $setting = static::query()->where('key', $key)->first();

        return $setting?->value ?? $default;
    }

    public static function setValue(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value],
        );
    }

    public static function footerPayload(): array
    {
        return [
            'company_description' => (string) static::getValue('footer_company_description', ''),
            'contact_email' => (string) static::getValue('footer_contact_email', ''),
            'contact_phone' => (string) static::getValue('footer_contact_phone', ''),
            'contact_address' => (string) static::getValue('footer_contact_address', ''),
            'copyright' => (string) static::getValue('footer_copyright', ''),
            'explore_links' => static::getValue('footer_explore_links', []),
            'support_links' => static::getValue('footer_support_links', []),
        ];
    }
}
