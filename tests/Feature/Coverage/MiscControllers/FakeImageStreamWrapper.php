<?php

namespace Tests\Feature\Coverage\MiscControllers;

/**
 * Serves canned bytes for `example://` URLs so UploadController::proxyImage can be
 * exercised without network access. Hosts: missing.test fails to open, explode.test throws.
 */
class FakeImageStreamWrapper
{
    public const PROTOCOL = 'example';

    /** @var resource|null */
    public $context;

    private string $data = '';

    private int $position = 0;

    public static function register(): void
    {
        if (!in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }
    }

    public static function unregister(): void
    {
        if (in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::PROTOCOL);
        }
    }

    public static function pngBytes(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $host = parse_url($path, PHP_URL_HOST);

        if ($host === 'explode.test') {
            throw new \RuntimeException('stream exploded');
        }

        if ($host === 'missing.test') {
            return false;
        }

        $this->data = self::pngBytes();
        $this->position = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->data, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->data);
    }

    public function stream_stat(): array
    {
        return ['size' => strlen($this->data)];
    }
}
