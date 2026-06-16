<?php

namespace Tests\Unit;

use App\Support\UploadDisk;
use Tests\TestCase;

class UploadDiskTest extends TestCase
{
    public function test_for_uploads_uses_public_disk_when_default_is_local(): void
    {
        config(['filesystems.default' => 'local']);

        $this->assertSame('public', UploadDisk::forUploads());
        $this->assertFalse(UploadDisk::isCloud());
    }

    public function test_for_uploads_uses_digitalocean_when_configured(): void
    {
        config(['filesystems.default' => 'digitalocean']);

        $this->assertSame('digitalocean', UploadDisk::forUploads());
        $this->assertTrue(UploadDisk::isCloud());
    }
}
