<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

class Upload extends Model
{
    use HasUuids;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'uploadable_type',
        'uploadable_uuid',
        'collection',
        'type',
        'mime_type',
        'extension',
        'disk',
        'path',
        'size_bytes',
        'width',
        'height',
        'dominant_color',
        'checksum',
        'order_number',
        'name',
        'alt_text',
        'created_by',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'width'      => 'integer',
        'height'     => 'integer',
    ];

    protected $appends = ['url'];

    protected $hidden = [
        'created_by',
        'created_at',
        'updated_at',
    ];

    /**
     * Polymorphic parent — the model that owns this upload.
     * Uses custom morph key columns to align with the UUID-primary-key convention.
     */
    public function uploadable(): MorphTo
    {
        return $this->morphTo('uploadable', 'uploadable_type', 'uploadable_uuid');
    }

    // Accessor: resolved public URL
    protected function url(): Attribute
    {
        return Attribute::get(function () {
            if (str_starts_with((string) $this->path, 'http://') || str_starts_with((string) $this->path, 'https://')) {
                return $this->path;
            }

            try {
                /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
                $disk = Storage::disk($this->disk ?: config('filesystems.default'));

                return $disk->url($this->path);
            } catch (\Throwable) {
                return (string) $this->path;
            }
        });
    }

    // Accessor: human-readable file size
    protected function sizeHuman(): Attribute
    {
        return Attribute::get(function () {
            $bytes = (int) $this->size_bytes;
            $units = ['B', 'KB', 'MB', 'GB', 'TB'];
            $i     = 0;
            while ($bytes >= 1024 && $i < count($units) - 1) {
                $bytes /= 1024;
                $i++;
            }
            return number_format($bytes, $i ? 2 : 0) . ' ' . $units[$i];
        });
    }
}
