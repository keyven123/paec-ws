<?php

/*
 * Namespaced pass-through shims for a few global functions, so tests can simulate environment
 * conditions (Imagick availability, unreadable files, odd realpath results, broken GD decoding)
 * that cannot be produced on the test machine. Unqualified calls inside these namespaces resolve
 * to the shims; with no override registered they delegate to the real global function.
 * Must be loaded at test-collection time, before any of these call sites run for the first time.
 */

namespace Tests\Feature\Coverage\RPixelTickets\Support {
    final class FunctionShims
    {
        /** @var array<string, callable> */
        private static array $overrides = [];

        public static function override(string $function, callable $callback): void
        {
            self::$overrides[$function] = $callback;
        }

        public static function reset(): void
        {
            self::$overrides = [];
        }

        public static function call(string $function, array $args): mixed
        {
            if (isset(self::$overrides[$function])) {
                return (self::$overrides[$function])(...$args);
            }

            return ('\\' . $function)(...$args);
        }
    }
}

namespace App\Services {
    use Tests\Feature\Coverage\RPixelTickets\Support\FunctionShims;

    if (! function_exists(__NAMESPACE__ . '\\extension_loaded')) {
        function extension_loaded(string $extension): bool
        {
            return FunctionShims::call('extension_loaded', [$extension]);
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\is_readable')) {
        function is_readable(string $filename): bool
        {
            return FunctionShims::call('is_readable', [$filename]);
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\is_file')) {
        function is_file(string $filename): bool
        {
            return FunctionShims::call('is_file', [$filename]);
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\realpath')) {
        function realpath(string $path): string|false
        {
            return FunctionShims::call('realpath', [$path]);
        }
    }
}

namespace Endroid\QrCode\ImageData {
    use Tests\Feature\Coverage\RPixelTickets\Support\FunctionShims;

    if (! function_exists(__NAMESPACE__ . '\\imagecreatefromstring')) {
        function imagecreatefromstring(string $data): \GdImage|false
        {
            return FunctionShims::call('imagecreatefromstring', [$data]);
        }
    }
}
