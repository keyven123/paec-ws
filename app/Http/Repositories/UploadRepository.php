<?php

namespace App\Http\Repositories;

use App\Exceptions\NoResourceFoundException;
use App\Helpers\GeneralHelper;
use App\Models\Event;
use App\Models\Upload;
use App\Support\UploadDisk;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPOpenSourceSaver\JWTAuth\Payload;

class UploadRepository
{
    /**
     * @param AdminUser $adminUser
     */
    public function __construct(protected Upload $upload)
    {
    }

    public function fetchOrThrow(string $key, string $value): Upload
    {
        $upload = $this->upload->where($key, $value)->first();
        if (!$upload) {
            throw new NoResourceFoundException('File not found');
        }
        return $upload;
    }

    public function create(array $payload, $orderNumber = null): Upload
    {
        $file = $payload['file'];
        $uuid = (string) Str::uuid();
        $disk = $payload['disk'] ?? UploadDisk::forUploads();

        $ext = strtolower($file->getClientOriginalExtension());
        $mime = $file->getClientMimeType();
        $type = $payload['type'] ?? $this->detectType($mime, $ext);


        // If the file is a video, we need to make a queuing status for the video uploaded status.
        // it stucks at uploading status.

        $path = "uploads/" . now()->format('Y/m') . "/{$uuid}.{$ext}";
        Storage::disk($disk)->put(
            $path,
            file_get_contents($file->getRealPath()),
            ['visibility' => 'public'],
        );

        // Try to read dimensions if image
        $width = $height = null;
        if ($type === 'image') {
            try {
                $manager = ImageManager::gd()->read($file->getRealPath());
                $width = $manager->width();
                $height = $manager->height();
            } catch (\Throwable $e) {
                // ignore dimension errors
            }
        }

        return $this->upload->create([
            'type' => $type,
            'mime_type' => $mime,
            'extension' => $ext,
            'disk' => $disk,
            'path' => $path,
            'collection' => $payload['collection'] ?? null,
            'size_bytes' => $file->getSize(),
            'width' => $width,
            'height' => $height,
            'checksum' => hash_file('sha256', $file->getRealPath()),
            'order_number' => $orderNumber,
            'created_by' => auth('api')->user()->uuid ?? auth('admin')->user()->uuid ?? null,
        ]);
    }

    public function createGlobalUpload(array $payload): Upload
    {
        DB::beginTransaction();
        $file = $payload['file'];
        $uuid = (string) Str::uuid();
        $disk = $payload['disk'] ?? UploadDisk::forUploads();
        $ext = strtolower($file->getClientOriginalExtension());
        $mime = $file->getClientMimeType();
        $type = $payload['type'] ?? $this->detectType($mime, $ext);

        $path = "uploads/" . now()->format('Y/m') . "/{$uuid}.{$ext}";
        Storage::disk($disk)->put(
            $path,
            file_get_contents($file->getRealPath()),
            ['visibility' => 'public'],
        );

        $width = $height = null;
        if ($type === 'image') {
            try {
                $manager = ImageManager::gd()->read($file->getRealPath());
                $width = $manager->width();
                $height = $manager->height();
            } catch (\Throwable $e) {
                // ignore
            }
        }

        $upload = $this->upload->create([
            'type' => $type,
            'mime_type' => $mime,
            'extension' => $ext,
            'disk' => $disk,
            'path' => $path,
            'collection' => $payload['category'] ?? $payload['collection'] ?? null,
            'size_bytes' => $file->getSize(),
            'width' => $width,
            'height' => $height,
            'checksum' => hash_file('sha256', $file->getRealPath()),
            'order_number' => $payload['order_number'] ?? null,
            'created_by' => auth('api')->user()->uuid ?? auth('admin')->user()->uuid ?? null,
        ]);

        $this->handleAttachModel($upload, $payload);

        DB::commit();
        return $upload;
    }

    /**
     * Link event image uploads to their morph collection after the event row exists.
     *
     * @param  list<string>  $showcaseUploadUuids
     */
    public function attachEventUploads(
        Event $event,
        ?Upload $portrait = null,
        ?Upload $featured = null,
        array $showcaseUploadUuids = [],
    ): void {
        if ($portrait) {
            $this->attachUploadToModel($portrait, $event, 'portrait');
        }

        if ($featured) {
            $this->attachUploadToModel($featured, $event, 'featured');
        }

        foreach ($showcaseUploadUuids as $uploadUuid) {
            $upload = $this->upload->newQuery()->where('uuid', $uploadUuid)->first();
            if ($upload) {
                $this->attachUploadToModel($upload, $event, 'showcase');
            }
        }
    }

    public function attachUploadToModel(Upload $upload, Model $model, string $collection): void
    {
        $upload->update([
            'uploadable_type' => get_class($model),
            'uploadable_uuid' => $model->uuid,
            'collection' => $collection,
        ]);
    }

    // public function uploadVideo(array $payload): Upload
    // {
    //     $file = $payload['file'];
    //     $uuid = (string) Str::uuid();
    //     $disk = $validated['disk'] ?? 'public';
    // }

    private function detectType(string $mime, string $ext): string
    {
        if (str_starts_with($mime, 'image/')) {
            return 'image';
        };

        return match ($ext) {
            'csv' => 'csv',
            'xlsx','xls' => 'xlsx',
            'pdf' => 'pdf',
            'mp4','mov','mkv','webm' => 'video',
            'mp3','wav','flac' => 'audio',
            default => 'other',
        };
    }

    public function delete(Upload $upload, array $payload): bool
    {
        DB::beginTransaction();
        Storage::disk($upload->disk)->delete($upload->path);
        $this->handleDetachModel($upload, $payload);
        DB::commit();
        return $upload->delete();
    }

    public function handleAttachModel(Upload $upload, array $payload): void
    {
        if (empty($payload['model_type']) || empty($payload['model_uuid'])) {
            return;
        }

        $modelClass = GeneralHelper::resolveModelClass($payload['model_type']);

        if (!class_exists($modelClass)) {
            throw new NoResourceFoundException("Model type {$payload['model_type']} not found");
        }

        /** @var Model|null $model */
        $model = $modelClass::where('uuid', $payload['model_uuid'])->first();

        if (!$model) {
            return;
        }

        $category = $payload['category'] ?? null;

        // ── Morph-based approach (e.g. VenueListing) ────────────────────────────
        // If the model defines an uploads() morphMany, we store the relationship
        // directly on the upload record instead of an FK column on the parent.
        if (method_exists($model, 'uploads')) {
            $collection = $category ?? 'default';

            // Featured is a single-image collection — replace any existing upload first.
            if ($collection === 'featured') {
                $model->uploads()
                    ->where('collection', 'featured')
                    ->get()
                    ->each(function (Upload $existing) {
                        Storage::disk($existing->disk)->delete($existing->path);
                        $existing->delete();
                    });
            }

            $upload->update([
                'uploadable_type' => get_class($model),
                'uploadable_uuid' => $model->uuid,
                'collection'      => $collection,
            ]);
            return;
        }

        // ── Legacy FK-based approach (Event, Organization, User, Venue) ──────────
        if (empty($category)) {
            return;
        }

        if ($category === 'event_showcase') {
            $existingShowcase = $model->event_showcase;
            if (is_string($existingShowcase)) {
                $existingShowcase = json_decode($existingShowcase, true) ?? [];
            }
            if (!is_array($existingShowcase)) {
                $existingShowcase = [];
            }
            $model->update([
                'event_showcase' => array_merge($existingShowcase, [$upload->uuid]),
            ]);
        } elseif ($category === 'organization_image') {
            $model->update(['image_uuid' => $upload->uuid]);
        } else {
            $model->update([$category => $upload->uuid]);
        }
    }

    public function handleDetachModel(Upload $upload, array $payload): void
    {
        if (empty($payload['model_type']) || empty($payload['model_uuid'])) {
            return;
        }

        $modelClass = GeneralHelper::resolveModelClass($payload['model_type']);
        $model      = $modelClass::where('uuid', $payload['model_uuid'])->first();

        if (!$model) {
            return;
        }

        $category = $payload['category'] ?? null;

        // ── Morph-based approach ────────────────────────────────────────────────
        if (method_exists($model, 'uploads')) {
            $upload->update([
                'uploadable_type' => null,
                'uploadable_uuid' => null,
                'collection'      => null,
            ]);
            return;
        }

        // ── Legacy FK-based approach ─────────────────────────────────────────────
        if ($category === 'event_showcase') {
            $model->update([
                $category => array_diff((array) $model[$category], [$upload->uuid]),
            ]);
        } else {
            $model->update([$category => null]);
        }
    }
}
