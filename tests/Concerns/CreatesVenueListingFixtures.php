<?php

namespace Tests\Concerns;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Upload;
use App\Models\VenueListing;

trait CreatesVenueListingFixtures
{
    protected AdminUser $venueAdminUser;
    protected Role $venueAdminRole;
    protected string $venueAdminToken;

    protected function setUpVenueListingAdmin(): void
    {
        $this->venueAdminRole = Role::create([
            'name' => 'Admin',
            'code' => GeneralConstants::ROLES['ADMIN']['name'],
            'is_admin' => true,
        ]);

        $permission = Permission::create([
            'name' => 'Venue Listing',
            'code' => 'venue-listings',
            'available_access' => ['view', 'create', 'update', 'delete', 'export'],
        ]);

        foreach (['view', 'create', 'update', 'delete', 'export'] as $access) {
            RolePermission::create([
                'role_uuid' => $this->venueAdminRole->uuid,
                'permission_uuid' => $permission->uuid,
                'access' => 'venue-listings-' . $access,
            ]);
        }

        $this->venueAdminUser = AdminUser::create([
            'role_uuid' => $this->venueAdminRole->uuid,
            'email' => 'venue-admin@test.com',
            'password' => 'password123',
            'first_name' => 'Venue',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $this->venueAdminToken = auth('admin')->login($this->venueAdminUser);
    }

    protected function withVenueAdminHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->venueAdminToken];
    }

    /**
     * @return array{0: array<string, string>, 1: Organization}
     */
    protected function createVenueListingMerchantAuth(): array
    {
        $organization = Organization::factory()->create();

        $merchantRole = Role::create([
            'organization_uuid' => $organization->uuid,
            'name' => 'Organizer',
            'code' => GeneralConstants::ROLES['ORGANIZER']['name'],
            'is_admin' => false,
        ]);

        $permission = Permission::firstOrCreate(
            ['code' => 'venue-listings'],
            [
                'name' => 'Venue Listing',
                'available_access' => ['view', 'create', 'update', 'delete', 'export'],
            ],
        );

        foreach (['view', 'create', 'update', 'delete', 'export'] as $access) {
            RolePermission::firstOrCreate([
                'role_uuid' => $merchantRole->uuid,
                'permission_uuid' => $permission->uuid,
                'access' => 'venue-listings-' . $access,
            ]);
        }

        $merchantUser = AdminUser::create([
            'role_uuid' => $merchantRole->uuid,
            'organization_uuid' => $organization->uuid,
            'email' => 'venue-merchant@test.com',
            'password' => 'password123',
            'first_name' => 'Venue',
            'last_name' => 'Merchant',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $token = auth('admin')->login($merchantUser);

        return [
            ['Authorization' => 'Bearer ' . $token],
            $organization,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function createVenueListing(array $overrides = []): VenueListing
    {
        return VenueListing::factory()->create($overrides);
    }

    protected function attachListingImages(VenueListing $listing, ?string $slug = null): void
    {
        $slug ??= $listing->slug;

        Upload::create([
            'uploadable_type' => VenueListing::class,
            'uploadable_uuid' => $listing->uuid,
            'collection'      => 'featured',
            'type'            => 'image',
            'mime_type'       => 'image/jpeg',
            'extension'       => 'jpg',
            'disk'            => 'public',
            'path'            => "https://example.com/{$slug}-featured.jpg",
            'dominant_color'  => $listing->image_color ?? '#1e3a5f',
            'order_number'    => 0,
        ]);

        foreach (['#1e3a5f', '#446085', '#052146'] as $index => $color) {
            Upload::create([
                'uploadable_type' => VenueListing::class,
                'uploadable_uuid' => $listing->uuid,
                'collection'      => 'gallery',
                'type'            => 'image',
                'mime_type'       => 'image/jpeg',
                'extension'       => 'jpg',
                'disk'            => 'public',
                'path'            => "https://example.com/{$slug}-gallery-{$index}.jpg",
                'dominant_color'  => $color,
                'order_number'    => $index,
            ]);
        }
    }
}
