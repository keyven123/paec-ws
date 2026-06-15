<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\AffiliateLinkClick;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsUserAffiliate;
use Tests\TestCase;

class AffiliatePublicControllerTest extends TestCase
{
    use RefreshDatabase;
    use SeedsUserAffiliate;

    private User $partner;
    private Role $customerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customerRole = Role::create([
            'name' => 'Customer',
            'code' => GeneralConstants::ROLES['CUSTOMER']['name'],
        ]);

        $this->partner = User::create([
            'role_uuid' => $this->customerRole->uuid,
            'email' => 'partner@test.com',
            'password' => bcrypt('password123'),
            'first_name' => 'Partner',
            'last_name' => 'User',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
        $this->seedApprovedAffiliate($this->partner, 'TESTCODE');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanTrackClickForApprovedPartner()
    {
        $response = $this->postJson('/api/v1/public/affiliate/track-click', [
            'ref' => 'TESTCODE',
            'path' => '/events/some-event?ref=TESTCODE',
        ]);

        $response->assertStatus(200)
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('affiliate_link_clicks', [
            'partner_user_uuid' => $this->partner->uuid,
            'ref_code' => 'TESTCODE',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itNormalizesRefCodeToUppercase()
    {
        $response = $this->postJson('/api/v1/public/affiliate/track-click', [
            'ref' => 'testcode',
            'path' => '/browse',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('affiliate_link_clicks', [
            'partner_user_uuid' => $this->partner->uuid,
            'ref_code' => 'TESTCODE',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRecordsPathAndIpAndUserAgent()
    {
        $this->postJson('/api/v1/public/affiliate/track-click', [
            'ref' => 'TESTCODE',
            'path' => '/events/my-event',
        ]);

        $click = AffiliateLinkClick::first();
        $this->assertNotNull($click);
        $this->assertEquals('/events/my-event', $click->path);
        $this->assertNotNull($click->ip_address);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itIgnoresClickForNonExistentRefCode()
    {
        $response = $this->postJson('/api/v1/public/affiliate/track-click', [
            'ref' => 'INVALID',
            'path' => '/browse',
        ]);

        $response->assertStatus(200);
        $this->assertEquals(0, AffiliateLinkClick::count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itIgnoresClickForNonApprovedPartner()
    {
        $this->partner->userAffiliate->update(['affiliate_status' => GeneralConstants::AFFILIATE_STATUSES['NONE']]);

        $response = $this->postJson('/api/v1/public/affiliate/track-click', [
            'ref' => 'TESTCODE',
            'path' => '/browse',
        ]);

        $response->assertStatus(200);
        $this->assertEquals(0, AffiliateLinkClick::count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesRefFieldIsRequired()
    {
        $response = $this->postJson('/api/v1/public/affiliate/track-click', [
            'path' => '/browse',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['ref']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itAcceptsOptionalPath()
    {
        $response = $this->postJson('/api/v1/public/affiliate/track-click', [
            'ref' => 'TESTCODE',
        ]);

        $response->assertStatus(200);
        $this->assertEquals(1, AffiliateLinkClick::count());
    }
}
