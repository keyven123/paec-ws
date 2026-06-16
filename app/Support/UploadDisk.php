<?php

namespace App\Support;

class UploadDisk
{
    /**
     * Resolve the disk used for user-facing uploads (images, attachments).
     *
     * When the default disk is local we store on the public disk so files are
     * web-accessible. When DigitalOcean Spaces credentials are configured the
     * default disk should be "digitalocean".
     */
    public static function forUploads(): string
    {
        $disk = (string) config('filesystems.default', 'local');

        return $disk === 'local' ? 'public' : $disk;
    }

    public static function isCloud(): bool
    {
        return self::forUploads() !== 'public' && self::forUploads() !== 'local';
    }
}
