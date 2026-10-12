<?php

namespace Tests\Feature\Coverage\MiscControllers;

use App\Models\Upload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UploadControllerCoverageTest extends TestCase
{
    use MiscFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        FakeImageStreamWrapper::register();
    }

    protected function tearDown(): void
    {
        FakeImageStreamWrapper::unregister();

        parent::tearDown();
    }

    private function storeUpload(): Upload
    {
        $uuid = $this->withHeaders($this->adminHeaders())
            ->post('/api/v1/uploads', [
                'file' => UploadedFile::fake()->image('photo.png', 40, 30),
                'disk' => 'public',
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.uuid');

        return Upload::findOrFail($uuid);
    }

    #[Test]
    public function showReturnsStoredUpload(): void
    {
        $upload = $this->storeUpload();

        $this->withHeaders($this->adminHeaders())
            ->getJson("/api/v1/uploads/{$upload->uuid}")
            ->assertOk()
            ->assertJsonPath('data.uuid', $upload->uuid)
            ->assertJsonPath('data.type', 'image')
            ->assertJsonPath('data.width', 40)
            ->assertJsonPath('data.height', 30);
    }

    #[Test]
    public function globalUploadStoresFileAndAttachesItToTheEvent(): void
    {
        $event = $this->createEvent();

        $response = $this->withHeaders($this->adminHeaders())
            ->post('/api/v1/uploads/global', [
                'file' => UploadedFile::fake()->image('banner.jpg', 60, 20),
                'disk' => 'public',
                'model_type' => 'Event',
                'model_uuid' => $event->uuid,
                'category' => 'showcase',
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.extension', 'jpg');

        $upload = Upload::findOrFail($response->json('data.uuid'));
        Storage::disk('public')->assertExists($upload->path);
        $this->assertSame(\App\Models\Event::class, $upload->uploadable_type);
        $this->assertSame($event->uuid, $upload->uploadable_uuid);
        $this->assertSame('showcase', $upload->collection);
        $this->assertSame($this->superAdmin->uuid, $upload->created_by);
    }

    #[Test]
    public function destroyDeletesFileAndDetachesItFromTheEvent(): void
    {
        $upload = $this->storeUpload();
        $event = $this->createEvent();
        $upload->update([
            'uploadable_type' => \App\Models\Event::class,
            'uploadable_uuid' => $event->uuid,
            'collection' => 'showcase',
        ]);
        Storage::disk('public')->assertExists($upload->path);

        $this->withHeaders($this->adminHeaders())
            ->deleteJson("/api/v1/uploads/{$upload->uuid}", [
                'model_type' => 'Event',
                'model_uuid' => $event->uuid,
                'category' => 'showcase',
            ])
            ->assertNoContent();

        Storage::disk('public')->assertMissing($upload->path);
        $this->assertNull(Upload::find($upload->uuid));
        $this->assertSame(0, $event->uploads()->count());
    }

    #[Test]
    public function proxyImageReturnsBase64DataUrl(): void
    {
        $response = $this->postJson('/api/v1/uploads/proxy-image', ['url' => 'example://images.test/pixel.png'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.mimeType', 'image/png');

        $this->assertSame(
            'data:image/png;base64,' . base64_encode(FakeImageStreamWrapper::pngBytes()),
            $response->json('data.dataUrl'),
        );
    }

    #[Test]
    public function proxyImageReturns400WhenImageCannotBeFetched(): void
    {
        $this->postJson('/api/v1/uploads/proxy-image', ['url' => 'example://missing.test/nothing.png'])
            ->assertStatus(400)
            ->assertExactJson(['success' => false, 'message' => 'Failed to fetch image']);
    }

    #[Test]
    public function proxyImageReturns500WhenFetchingThrows(): void
    {
        $this->postJson('/api/v1/uploads/proxy-image', ['url' => 'example://explode.test/boom.png'])
            ->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Failed to proxy image: stream exploded']);
    }

    #[Test]
    public function proxyImageValidatesUrl(): void
    {
        $this->postJson('/api/v1/uploads/proxy-image', ['url' => 'not a url'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['url']);
    }
}
