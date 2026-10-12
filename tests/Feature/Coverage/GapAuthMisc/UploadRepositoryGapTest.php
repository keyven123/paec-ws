<?php

namespace Tests\Feature\Coverage\GapAuthMisc;

use App\Constants\GeneralConstants;
use App\Exceptions\NoResourceFoundException;
use App\Http\Repositories\UploadRepository;
use App\Models\Event;
use App\Models\Upload;
use App\Support\UploadDisk;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Legacy FK-style model (no uploads() relation) over the events table; event_showcase stays a raw JSON string.
 */
class GapRawShowcaseModel extends Model
{
    protected $table = 'events';
    protected $primaryKey = 'uuid';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $guarded = [];
}

class GapCastShowcaseModel extends GapRawShowcaseModel
{
    protected $casts = ['event_showcase' => 'array'];
}

class UploadRepositoryGapTest extends TestCase
{
    use GapAuthMiscFixtures;
    use RefreshDatabase;

    private string $disk;

    private UploadRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disk = UploadDisk::forUploads();
        Storage::fake($this->disk);
        $this->repository = app(UploadRepository::class);

        if (!class_exists('App\\Models\\GapRawShowcase', false)) {
            class_alias(GapRawShowcaseModel::class, 'App\\Models\\GapRawShowcase');
            class_alias(GapCastShowcaseModel::class, 'App\\Models\\GapCastShowcase');
        }
    }

    private function makeUpload(array $attributes = []): Upload
    {
        $path = 'uploads/' . Str::uuid() . '.png';
        Storage::disk($this->disk)->put($path, 'content');

        return Upload::create(array_merge([
            'type' => 'image',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'disk' => $this->disk,
            'path' => $path,
        ], $attributes));
    }

    private function makeEvent(array $attributes = []): Event
    {
        return Event::create(array_merge([
            'event_name' => 'Gap Upload Event',
            'event_description' => 'Upload coverage',
            'contact_email' => 'event@test.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'tags' => [],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
        ], $attributes));
    }

    #[Test]
    public function createIgnoresUnreadableImageDimensions(): void
    {
        $file = UploadedFile::fake()->create('broken.png', 0, 'image/png');

        $upload = $this->repository->create(['file' => $file]);

        $this->assertSame('image', $upload->type);
        $this->assertNull($upload->width);
        $this->assertNull($upload->height);
        Storage::disk($this->disk)->assertExists($upload->path);
    }

    #[Test]
    public function createGlobalUploadIgnoresUnreadableImageDimensions(): void
    {
        $file = UploadedFile::fake()->create('broken.png', 0, 'image/png');

        $upload = $this->repository->createGlobalUpload(['file' => $file, 'category' => 'misc']);

        $this->assertSame('image', $upload->type);
        $this->assertSame('misc', $upload->collection);
        $this->assertNull($upload->width);
        Storage::disk($this->disk)->assertExists($upload->path);
    }

    public static function nonImageFiles(): array
    {
        return [
            'csv' => ['report.csv', 'text/csv', 'csv'],
            'xlsx' => ['sheet.xlsx', 'application/octet-stream', 'xlsx'],
            'xls' => ['legacy.xls', 'application/octet-stream', 'xlsx'],
            'video' => ['clip.mp4', 'video/mp4', 'video'],
            'audio' => ['song.mp3', 'audio/mpeg', 'audio'],
        ];
    }

    #[Test]
    #[DataProvider('nonImageFiles')]
    public function createDetectsTypeFromExtension(string $name, string $mime, string $expectedType): void
    {
        $upload = $this->repository->create(['file' => UploadedFile::fake()->create($name, 1, $mime)]);

        $this->assertSame($expectedType, $upload->type);
        $this->assertDatabaseHas('uploads', ['uuid' => $upload->uuid, 'type' => $expectedType]);
    }

    #[Test]
    public function attachWithoutModelInfoLeavesUploadUnlinked(): void
    {
        $upload = $this->makeUpload();

        $this->repository->handleAttachModel($upload, ['category' => 'featured']);

        $this->assertNull($upload->fresh()->uploadable_uuid);
    }

    #[Test]
    public function attachToUnknownModelTypeThrows(): void
    {
        $this->expectException(NoResourceFoundException::class);
        $this->expectExceptionMessage('Model type DoesNotExist not found');

        $this->repository->handleAttachModel($this->makeUpload(), [
            'model_type' => 'DoesNotExist',
            'model_uuid' => (string) Str::uuid(),
        ]);
    }

    #[Test]
    public function attachToMissingModelRecordIsIgnored(): void
    {
        $upload = $this->makeUpload();

        $this->repository->handleAttachModel($upload, [
            'model_type' => 'Organization',
            'model_uuid' => (string) Str::uuid(),
            'category' => 'organization_image',
        ]);

        $this->assertNull($upload->fresh()->uploadable_uuid);
    }

    #[Test]
    public function attachFeaturedReplacesExistingFeaturedUpload(): void
    {
        $event = $this->makeEvent();
        $oldPath = 'uploads/' . Str::uuid() . '.png';
        Storage::disk($this->disk)->put($oldPath, 'old');
        $old = $event->uploads()->create([
            'type' => 'image',
            'disk' => $this->disk,
            'path' => $oldPath,
            'collection' => 'featured',
        ]);
        $new = $this->makeUpload();

        $this->repository->handleAttachModel($new, [
            'model_type' => 'Event',
            'model_uuid' => $event->uuid,
            'category' => 'featured',
        ]);

        $this->assertDatabaseMissing('uploads', ['uuid' => $old->uuid]);
        Storage::disk($this->disk)->assertMissing($old->path);
        $this->assertDatabaseHas('uploads', [
            'uuid' => $new->uuid,
            'uploadable_type' => Event::class,
            'uploadable_uuid' => $event->uuid,
            'collection' => 'featured',
        ]);
    }

    #[Test]
    public function attachToLegacyModelWithoutCategoryDoesNothing(): void
    {
        $organization = $this->makeOrganization();
        $upload = $this->makeUpload();

        $this->repository->handleAttachModel($upload, [
            'model_type' => 'Organization',
            'model_uuid' => $organization->uuid,
        ]);

        $this->assertNull($organization->fresh()->image_uuid);
        $this->assertNull($upload->fresh()->uploadable_uuid);
    }

    #[Test]
    public function attachOrganizationImageSetsImageUuid(): void
    {
        $organization = $this->makeOrganization();
        $upload = $this->makeUpload();

        $this->repository->handleAttachModel($upload, [
            'model_type' => 'Organization',
            'model_uuid' => $organization->uuid,
            'category' => 'organization_image',
        ]);

        $this->assertSame($upload->uuid, $organization->fresh()->image_uuid);
    }

    #[Test]
    public function attachWithColumnCategorySetsThatColumn(): void
    {
        $customer = $this->makeCustomer();
        $upload = $this->makeUpload();

        $this->repository->handleAttachModel($upload, [
            'model_type' => 'User',
            'model_uuid' => $customer->uuid,
            'category' => 'profile_image_uuid',
        ]);

        $this->assertSame($upload->uuid, $customer->fresh()->profile_image_uuid);
    }

    #[Test]
    public function attachShowcaseAppendsToJsonEncodedList(): void
    {
        $event = $this->makeEvent(['event_showcase' => ['existing-uuid']]);
        $upload = $this->makeUpload();

        $this->repository->handleAttachModel($upload, [
            'model_type' => 'GapRawShowcase',
            'model_uuid' => $event->uuid,
            'category' => 'event_showcase',
        ]);

        $this->assertSame(['existing-uuid', $upload->uuid], $event->fresh()->event_showcase);
    }

    #[Test]
    public function attachShowcaseResetsNonListValue(): void
    {
        $event = $this->makeEvent();
        DB::table('events')->where('uuid', $event->uuid)->update(['event_showcase' => '"not-a-list"']);
        $upload = $this->makeUpload();

        $this->repository->handleAttachModel($upload, [
            'model_type' => 'GapRawShowcase',
            'model_uuid' => $event->uuid,
            'category' => 'event_showcase',
        ]);

        $this->assertSame([$upload->uuid], $event->fresh()->event_showcase);
    }

    #[Test]
    public function detachWithoutModelInfoKeepsLink(): void
    {
        $event = $this->makeEvent();
        $upload = $this->makeUpload(['uploadable_type' => Event::class, 'uploadable_uuid' => $event->uuid]);

        $this->repository->handleDetachModel($upload, []);

        $this->assertSame($event->uuid, $upload->fresh()->uploadable_uuid);
    }

    #[Test]
    public function detachFromMissingModelRecordIsIgnored(): void
    {
        $event = $this->makeEvent();
        $upload = $this->makeUpload(['uploadable_type' => Event::class, 'uploadable_uuid' => $event->uuid]);

        $this->repository->handleDetachModel($upload, [
            'model_type' => 'Event',
            'model_uuid' => (string) Str::uuid(),
        ]);

        $this->assertSame($event->uuid, $upload->fresh()->uploadable_uuid);
    }

    #[Test]
    public function detachShowcaseRemovesUploadFromLegacyList(): void
    {
        $upload = $this->makeUpload();
        $event = $this->makeEvent(['event_showcase' => ['keep-uuid', $upload->uuid]]);

        $this->repository->handleDetachModel($upload, [
            'model_type' => 'GapCastShowcase',
            'model_uuid' => $event->uuid,
            'category' => 'event_showcase',
        ]);

        $this->assertSame(['keep-uuid'], array_values((array) $event->fresh()->event_showcase));
    }

    #[Test]
    public function deleteClearsLegacyColumnOnModel(): void
    {
        $upload = $this->makeUpload();
        $organization = $this->makeOrganization(['image_uuid' => $upload->uuid]);

        $this->assertTrue($this->repository->delete($upload, [
            'model_type' => 'Organization',
            'model_uuid' => $organization->uuid,
            'category' => 'image_uuid',
        ]));

        $this->assertNull($organization->fresh()->image_uuid);
        Storage::disk($this->disk)->assertMissing($upload->path);
    }
}
