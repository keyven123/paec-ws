<?php

namespace App\Observers;

use App\Models\Upload;
use Illuminate\Support\Str;

class UploadObserver
{
    public function creating(Upload $upload): void
    {
        if (empty($upload->uuid)) {
            $upload->uuid = (string) Str::uuid();
        }
        $upload->created_by = auth('api')->user()->uuid ?? auth('admin')->user()->uuid ?? null;
    }
}
