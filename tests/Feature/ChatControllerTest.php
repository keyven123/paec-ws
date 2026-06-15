<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Events\ChatMessageSent;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\VenueInquiry;
use App\Models\VenueListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesVenueListingFixtures;
use Tests\TestCase;

class ChatControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesVenueListingFixtures;

    private Role $customerRole;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customerRole = Role::create([
            'name' => 'Customer',
            'code' => GeneralConstants::ROLES['CUSTOMER']['name'],
        ]);

        $this->customer = User::factory()->create([
            'role_uuid' => $this->customerRole->uuid,
            'email' => 'chat-customer@test.com',
        ]);
    }

    private function makeInquiry(Organization $organization, ?string $userUuid): VenueInquiry
    {
        $listing = VenueListing::factory()->create([
            'organization_uuid' => $organization->uuid,
        ]);

        return VenueInquiry::factory()->create([
            'venue_listing_uuid' => $listing->uuid,
            'user_uuid' => $userUuid,
        ]);
    }

    #[Test]
    public function customer_can_open_chat_thread_for_their_inquiry(): void
    {
        $organization = Organization::factory()->create();
        $inquiry = $this->makeInquiry($organization, $this->customer->uuid);

        $response = $this->actingAs($this->customer, 'api')
            ->getJson("/api/v1/customer/venue-inquiries/{$inquiry->uuid}/chat");

        $response->assertOk()
            ->assertJsonPath('thread.venue_inquiry_uuid', $inquiry->uuid)
            ->assertJsonPath('thread.channel_name', fn ($name) => str_starts_with($name, 'chat.thread.'))
            ->assertJsonCount(0, 'messages');

        $this->assertDatabaseHas('chat_threads', [
            'venue_inquiry_uuid' => $inquiry->uuid,
            'organization_uuid' => $organization->uuid,
            'customer_uuid' => $this->customer->uuid,
        ]);
    }

    #[Test]
    public function opening_chat_twice_reuses_the_same_thread(): void
    {
        $organization = Organization::factory()->create();
        $inquiry = $this->makeInquiry($organization, $this->customer->uuid);

        $first = $this->actingAs($this->customer, 'api')
            ->getJson("/api/v1/customer/venue-inquiries/{$inquiry->uuid}/chat")
            ->json('thread.uuid');

        $second = $this->actingAs($this->customer, 'api')
            ->getJson("/api/v1/customer/venue-inquiries/{$inquiry->uuid}/chat")
            ->json('thread.uuid');

        $this->assertSame($first, $second);
        $this->assertSame(1, ChatThread::query()->count());
    }

    #[Test]
    public function customer_can_send_a_message_and_it_broadcasts(): void
    {
        Event::fake([ChatMessageSent::class]);

        $organization = Organization::factory()->create();
        $inquiry = $this->makeInquiry($organization, $this->customer->uuid);

        $response = $this->actingAs($this->customer, 'api')
            ->postJson("/api/v1/customer/venue-inquiries/{$inquiry->uuid}/chat/messages", [
                'body' => 'Hi, is this venue available on my date?',
            ]);

        $response->assertCreated()
            ->assertJsonPath('message.sender_type', ChatThread::SENDER_CUSTOMER)
            ->assertJsonPath('message.body', 'Hi, is this venue available on my date?');

        $this->assertDatabaseHas('chat_messages', [
            'sender_type' => ChatThread::SENDER_CUSTOMER,
            'sender_uuid' => $this->customer->uuid,
            'body' => 'Hi, is this venue available on my date?',
        ]);

        Event::assertDispatched(ChatMessageSent::class);
    }

    #[Test]
    public function customer_cannot_access_another_customers_inquiry_chat(): void
    {
        $organization = Organization::factory()->create();
        $otherUser = User::factory()->create(['role_uuid' => $this->customerRole->uuid]);
        $inquiry = $this->makeInquiry($organization, $otherUser->uuid);

        $this->actingAs($this->customer, 'api')
            ->getJson("/api/v1/customer/venue-inquiries/{$inquiry->uuid}/chat")
            ->assertForbidden();
    }

    #[Test]
    public function merchant_can_open_and_reply_in_a_thread_for_their_organization(): void
    {
        Event::fake([ChatMessageSent::class]);

        [$headers, $organization] = $this->createVenueListingMerchantAuth();
        $inquiry = $this->makeInquiry($organization, $this->customer->uuid);

        // Customer opens and sends first.
        $this->actingAs($this->customer, 'api')
            ->postJson("/api/v1/customer/venue-inquiries/{$inquiry->uuid}/chat/messages", [
                'body' => 'Hello there',
            ])->assertCreated();

        $this->withHeaders($headers)
            ->getJson("/api/v1/venue-listings/inquiries/{$inquiry->uuid}/chat")
            ->assertOk()
            ->assertJsonCount(1, 'messages');

        $this->withHeaders($headers)
            ->postJson("/api/v1/venue-listings/inquiries/{$inquiry->uuid}/chat/messages", [
                'body' => 'Yes it is available!',
            ])
            ->assertCreated()
            ->assertJsonPath('message.sender_type', ChatThread::SENDER_MERCHANT);

        $this->assertDatabaseHas('chat_messages', [
            'sender_type' => ChatThread::SENDER_MERCHANT,
            'body' => 'Yes it is available!',
        ]);
    }

    #[Test]
    public function merchant_from_another_organization_cannot_access_thread(): void
    {
        [$headers] = $this->createVenueListingMerchantAuth();

        $otherOrganization = Organization::factory()->create();
        $inquiry = $this->makeInquiry($otherOrganization, $this->customer->uuid);

        $this->withHeaders($headers)
            ->getJson("/api/v1/venue-listings/inquiries/{$inquiry->uuid}/chat")
            ->assertForbidden();
    }

    #[Test]
    public function contact_information_in_a_message_is_masked(): void
    {
        $organization = Organization::factory()->create();
        $inquiry = $this->makeInquiry($organization, $this->customer->uuid);

        $this->actingAs($this->customer, 'api')
            ->postJson("/api/v1/customer/venue-inquiries/{$inquiry->uuid}/chat/messages", [
                'body' => 'Reach me at me@example.com or 0917 123 4567 or https://wa.me/123',
            ])
            ->assertCreated()
            ->assertJsonPath('message.body', fn ($body) =>
                ! str_contains($body, 'me@example.com')
                && ! str_contains($body, 'wa.me')
                && ! str_contains($body, '0917')
                && str_contains($body, '[hidden]'));

        $stored = ChatMessage::query()->firstOrFail();
        $this->assertStringNotContainsString('me@example.com', $stored->body);
        $this->assertStringContainsString('[hidden]', $stored->body);
    }

    #[Test]
    public function merchant_can_send_a_message_with_a_pdf_attachment(): void
    {
        Storage::fake('local');
        Event::fake([ChatMessageSent::class]);

        [$headers, $organization] = $this->createVenueListingMerchantAuth();
        $inquiry = $this->makeInquiry($organization, $this->customer->uuid);

        $file = UploadedFile::fake()->create('quotation.pdf', 120, 'application/pdf');

        $response = $this->withHeaders($headers)
            ->post("/api/v1/venue-listings/inquiries/{$inquiry->uuid}/chat/messages", [
                'body' => 'Here is your quotation',
                'file' => $file,
            ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('message.attachment.name', 'quotation.pdf')
            ->assertJsonPath('message.attachment.type', 'pdf');

        $this->assertDatabaseHas('chat_messages', [
            'sender_type' => ChatThread::SENDER_MERCHANT,
            'attachment_name' => 'quotation.pdf',
        ]);

        $message = ChatMessage::query()->firstOrFail();
        $this->assertNotNull($message->attachment_upload_uuid);
    }

    #[Test]
    public function merchant_attachment_only_message_is_allowed_without_a_body(): void
    {
        Storage::fake('local');

        [$headers, $organization] = $this->createVenueListingMerchantAuth();
        $inquiry = $this->makeInquiry($organization, $this->customer->uuid);

        $file = UploadedFile::fake()->image('floorplan.png');

        $this->withHeaders($headers)
            ->post("/api/v1/venue-listings/inquiries/{$inquiry->uuid}/chat/messages", [
                'file' => $file,
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('message.attachment.type', 'image');
    }

    #[Test]
    public function message_broadcasts_to_recipient_personal_channel(): void
    {
        $organization = Organization::factory()->create();
        $inquiry = $this->makeInquiry($organization, $this->customer->uuid);

        $thread = app(\App\Http\Repositories\ChatRepository::class)
            ->firstOrCreateThreadForInquiry($inquiry);

        // Customer message → should notify the merchant org channel.
        $customerMessage = ChatMessage::create([
            'chat_thread_uuid' => $thread->uuid,
            'sender_type' => ChatThread::SENDER_CUSTOMER,
            'sender_uuid' => $this->customer->uuid,
            'sender_name' => 'Customer',
            'body' => 'hi',
        ]);

        $channels = collect((new ChatMessageSent($customerMessage))->broadcastOn())
            ->map(fn ($channel) => $channel->name);

        $this->assertTrue($channels->contains('private-chat.thread.' . $thread->uuid));
        $this->assertTrue($channels->contains('private-chat.org.' . $organization->uuid));

        // Merchant message → should notify the customer's personal channel.
        $merchantMessage = ChatMessage::create([
            'chat_thread_uuid' => $thread->uuid,
            'sender_type' => ChatThread::SENDER_MERCHANT,
            'sender_uuid' => 'admin-uuid',
            'sender_name' => 'Merchant',
            'body' => 'hello',
        ]);

        $channels = collect((new ChatMessageSent($merchantMessage))->broadcastOn())
            ->map(fn ($channel) => $channel->name);

        $this->assertTrue($channels->contains('private-chat.user.' . $this->customer->uuid));
    }

    #[Test]
    public function customer_unread_summary_returns_counts_per_inquiry(): void
    {
        [$headers, $organization] = $this->createVenueListingMerchantAuth();
        $inquiry = $this->makeInquiry($organization, $this->customer->uuid);

        // Merchant sends two messages the customer has not seen.
        $this->withHeaders($headers)
            ->postJson("/api/v1/venue-listings/inquiries/{$inquiry->uuid}/chat/messages", ['body' => 'Hello!'])
            ->assertCreated();
        $this->withHeaders($headers)
            ->postJson("/api/v1/venue-listings/inquiries/{$inquiry->uuid}/chat/messages", ['body' => 'Are you there?'])
            ->assertCreated();

        $this->actingAs($this->customer, 'api')
            ->getJson('/api/v1/customer/chat/unread-summary')
            ->assertOk()
            ->assertJsonFragment(['venue_inquiry_uuid' => $inquiry->uuid, 'unread_count' => 2]);
    }

    #[Test]
    public function merchant_unread_summary_returns_counts_per_inquiry(): void
    {
        [$headers, $organization] = $this->createVenueListingMerchantAuth();
        $inquiry = $this->makeInquiry($organization, $this->customer->uuid);

        // Customer sends one message the merchant has not seen.
        $this->actingAs($this->customer, 'api')
            ->postJson("/api/v1/customer/venue-inquiries/{$inquiry->uuid}/chat/messages", ['body' => 'Hi'])
            ->assertCreated();

        $this->withHeaders($headers)
            ->getJson('/api/v1/venue-listings/chat/unread-summary')
            ->assertOk()
            ->assertJsonFragment(['venue_inquiry_uuid' => $inquiry->uuid, 'unread_count' => 1]);
    }

    #[Test]
    public function unread_count_reflects_messages_from_the_other_side(): void
    {
        [$headers, $organization] = $this->createVenueListingMerchantAuth();
        $inquiry = $this->makeInquiry($organization, $this->customer->uuid);

        // Customer sends two messages the merchant has not seen yet.
        $this->actingAs($this->customer, 'api')
            ->postJson("/api/v1/customer/venue-inquiries/{$inquiry->uuid}/chat/messages", ['body' => 'one'])
            ->assertCreated();
        $this->actingAs($this->customer, 'api')
            ->postJson("/api/v1/customer/venue-inquiries/{$inquiry->uuid}/chat/messages", ['body' => 'two'])
            ->assertCreated();

        $thread = ChatThread::query()->where('venue_inquiry_uuid', $inquiry->uuid)->firstOrFail();
        $this->assertSame(2, $thread->unreadCountFor(ChatThread::SENDER_MERCHANT));

        // Opening the thread as the merchant marks it read.
        $this->withHeaders($headers)
            ->getJson("/api/v1/venue-listings/inquiries/{$inquiry->uuid}/chat")
            ->assertOk()
            ->assertJsonPath('thread.unread_count', 0);
    }
}
