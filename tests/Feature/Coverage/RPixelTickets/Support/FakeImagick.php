<?php

/*
 * Minimal stand-in for ext-imagick (not installed on the test machine), only declared when the
 * real extension is absent. Tests make it reachable by shimming App\Services\extension_loaded().
 */

if (! class_exists('Imagick', false)) {
    class Imagick
    {
        public const ALPHACHANNEL_REMOVE = 12;

        public const LAYERMETHOD_FLATTEN = 14;

        public static string $blob = '';

        public static ?string $readFailure = null;

        /** @var list<string> */
        public static array $calls = [];

        public static function resetFake(): void
        {
            self::$blob = '';
            self::$readFailure = null;
            self::$calls = [];
        }

        public function setResolution(float $x, float $y): bool
        {
            self::$calls[] = "setResolution:{$x}x{$y}";

            return true;
        }

        public function readImageBlob(string $image): bool
        {
            if (self::$readFailure !== null) {
                throw new \RuntimeException(self::$readFailure);
            }
            self::$calls[] = 'readImageBlob:' . substr($image, 0, 5);

            return true;
        }

        public function setIteratorIndex(int $index): bool
        {
            self::$calls[] = "setIteratorIndex:{$index}";

            return true;
        }

        public function setImageBackgroundColor(string $color): bool
        {
            self::$calls[] = "setImageBackgroundColor:{$color}";

            return true;
        }

        public function setImageAlphaChannel(int $mode): bool
        {
            self::$calls[] = "setImageAlphaChannel:{$mode}";

            return true;
        }

        public function mergeImageLayers(int $method): self
        {
            self::$calls[] = "mergeImageLayers:{$method}";

            return $this;
        }

        public function setImageFormat(string $format): bool
        {
            self::$calls[] = "setImageFormat:{$format}";

            return true;
        }

        public function getImageBlob(): string
        {
            return self::$blob;
        }

        public function clear(): bool
        {
            self::$calls[] = 'clear';

            return true;
        }

        public function destroy(): bool
        {
            self::$calls[] = 'destroy';

            return true;
        }
    }
}
