<?php

namespace App\Services\Chat;

class ContactInfoFilter
{
    /**
     * Email addresses.
     */
    private const EMAIL_PATTERN = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/';

    /**
     * External links (http(s):// or bare www.) — another common way to push
     * contact off-platform.
     */
    private const URL_PATTERN = '/\b((https?:\/\/)|(www\.))[^\s]+/i';

    /**
     * Phone-number-like runs: an optional leading +, then 7–15 digits that may
     * be separated by spaces, dashes, dots or parentheses. The digit count is
     * verified separately to avoid masking short numbers like guest counts.
     */
    private const PHONE_PATTERN = '/\+?\d[\d\s().\-]{5,}\d/';

    public function isEnabled(): bool
    {
        return (bool) config('chat.mask_contact_info', true);
    }

    /**
     * Replace any email address, external link or phone number in the given
     * text with the configured placeholder. Returns the text unchanged when
     * masking is disabled.
     */
    public function mask(string $body): string
    {
        if (!$this->isEnabled()) {
            return $body;
        }

        $placeholder = (string) config('chat.mask_placeholder', '[hidden]');

        $masked = preg_replace(self::EMAIL_PATTERN, $placeholder, $body) ?? $body;
        $masked = preg_replace(self::URL_PATTERN, $placeholder, $masked) ?? $masked;

        $masked = preg_replace_callback(
            self::PHONE_PATTERN,
            function (array $matches) use ($placeholder) {
                $digitCount = strlen(preg_replace('/\D/', '', $matches[0]));

                // Only treat it as a phone number when there are enough digits.
                return $digitCount >= 7 ? $placeholder : $matches[0];
            },
            $masked,
        ) ?? $masked;

        return $masked;
    }

    /**
     * Whether the given text contains contact information that would be masked.
     * Used to surface a friendly hint to the sender.
     */
    public function containsContactInfo(string $body): bool
    {
        return $this->mask($body) !== $body;
    }
}
