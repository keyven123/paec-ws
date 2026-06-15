<?php

namespace Database\Seeders;

use App\Constants\GeneralConstants;
use App\Models\Organization;
use App\Models\Upload;
use App\Models\VenueListing;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VenueListingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::beginTransaction();

        $organizations = [
            'Grand Events Co.' => $this->upsertOrganization('Grand Events Co.'),
            'Skyline Hospitality' => $this->upsertOrganization('Skyline Hospitality'),
            'Metro Venues Inc.' => $this->upsertOrganization('Metro Venues Inc.'),
            'Peninsula Events' => $this->upsertOrganization('Peninsula Events'),
            'Loft 42 Studio' => $this->upsertOrganization('Loft 42 Studio'),
            'Garden Terrace PH' => $this->upsertOrganization('Garden Terrace PH'),
        ];

        $baseSetups = [
            ['id' => 'theater', 'label' => 'Theater', 'capacity' => 500],
            ['id' => 'banquet', 'label' => 'Banquet', 'capacity' => 400],
            ['id' => 'classroom', 'label' => 'Classroom', 'capacity' => 280],
            ['id' => 'ballroom', 'label' => 'Ballroom', 'capacity' => 450],
            ['id' => 'cocktail', 'label' => 'Cocktail', 'capacity' => 500],
            ['id' => 'ushape', 'label' => 'U-shape', 'capacity' => 80],
            ['id' => 'boardroom', 'label' => 'Boardroom', 'capacity' => 40],
            ['id' => 'banquet-stage', 'label' => 'Banquet + stage', 'capacity' => 350],
        ];

        $baseAmenities = [
            'High-speed WiFi',
            'Central air-conditioning',
            'CCTV security',
            'Restrooms (6)',
            'LED projector + screen',
            'In-house catering',
            'Stage lighting rig',
            'Holding / VIP room',
            'PA / sound system',
            'PWD accessible',
            'Tables & chairs',
            'Backup generator',
        ];

        $baseBestFor = [
            'Corporate events',
            'Product launches',
            'Gala dinners',
            'Weddings',
            'Conferences',
            'Concerts / shows',
            'Seminars',
        ];

        $baseReviews = [
            [
                'id' => 'r1',
                'initials' => 'ML',
                'name' => 'Maria L.',
                'category' => 'Corporate',
                'date' => 'May 2026',
                'event' => 'Annual General Meeting',
                'rating' => 5,
                'text' => 'Seamless from inquiry to event day. The venue was exactly as described, staff were attentive, and the AV setup was professional.',
            ],
            [
                'id' => 'r2',
                'initials' => 'RC',
                'name' => 'Ramon C.',
                'category' => 'Celebration',
                'date' => 'Apr 2026',
                'event' => 'Product launch cocktail',
                'rating' => 4,
                'text' => 'Great space for 300 pax in a cocktail setup. Ingress was smooth with 3 entry points. Would rebook.',
            ],
        ];

        $venues = [
            [
                'slug' => 'the-grand-function-hall',
                'name' => 'The Grand Function Hall',
                'organization_name' => 'Grand Events Co.',
                'location' => 'Ayala Ave',
                'city' => 'Makati City',
                'area' => '800 sqm',
                'capacity_label' => '50–500 pax',
                'capacity_min' => 50,
                'capacity_max' => 500,
                'venue_type' => 'Function hall',
                'category' => 'function-halls',
                'price_per_event' => 45000,
                'status' => 'published',
                'is_featured' => true,
                'badge' => 'Featured',
                'rating' => 4.8,
                'review_count' => 42,
                'inquiries_count' => 18,
                'bookings_count' => 6,
                'image_color' => '#1e3a5f',
                'updated_at' => '2026-06-01 10:00:00',
                'description' => 'A versatile function hall in Makati City with 800 sqm of flexible floor space, floor-to-ceiling windows, and a modular layout that adapts from intimate boardroom setups to full banquet configurations.',
            ],
            [
                'slug' => 'skyline-rooftop-pavilion',
                'name' => 'Skyline Rooftop Pavilion',
                'organization_name' => 'Skyline Hospitality',
                'location' => '26th St',
                'city' => 'BGC, Taguig',
                'area' => '420 sqm',
                'capacity_label' => '80–200 pax',
                'capacity_min' => 80,
                'capacity_max' => 200,
                'venue_type' => 'Outdoor / garden',
                'category' => 'outdoor',
                'price_per_event' => 62000,
                'status' => 'approved',
                'is_featured' => true,
                'badge' => 'Top rated',
                'rating' => 4.9,
                'review_count' => 28,
                'inquiries_count' => 11,
                'bookings_count' => 3,
                'image_color' => '#14532d',
                'updated_at' => '2026-05-28 14:30:00',
                'description' => 'An open-air rooftop pavilion in BGC with skyline views, retractable canopy options, and a layout suited for cocktail receptions, launches, and sunset celebrations.',
                'setups' => [
                    ['id' => 'cocktail', 'label' => 'Cocktail', 'capacity' => 200],
                    ['id' => 'banquet', 'label' => 'Banquet', 'capacity' => 160],
                    ['id' => 'theater', 'label' => 'Theater', 'capacity' => 180],
                    ['id' => 'classroom', 'label' => 'Classroom', 'capacity' => 120],
                    ['id' => 'ballroom', 'label' => 'Ballroom', 'capacity' => 150],
                    ['id' => 'ushape', 'label' => 'U-shape', 'capacity' => 60],
                    ['id' => 'boardroom', 'label' => 'Boardroom', 'capacity' => 30],
                    ['id' => 'banquet-stage', 'label' => 'Banquet + stage', 'capacity' => 140],
                ],
                'specs' => [
                    ['label' => 'Floor area', 'value' => '420 sqm'],
                    ['label' => 'Ceiling height', 'value' => 'Open air · 4 m canopy'],
                    ['label' => 'Parking slots', 'value' => 'Valet + nearby BGC parking'],
                    ['label' => 'Power supply', 'value' => '80 kVA · generator backup'],
                    ['label' => 'Weather backup', 'value' => 'Retractable canopy + indoor lounge'],
                    ['label' => 'Curfew', 'value' => '12:00 AM (extensions on request)'],
                ],
                'min_capacity_note' => 'guests · cocktail & lounge setups',
                'max_capacity_note' => 'guests · standing reception',
            ],
            [
                'slug' => 'metro-conference-center',
                'name' => 'Metro Conference Center',
                'organization_name' => 'Metro Venues Inc.',
                'location' => 'Ortigas Ave',
                'city' => 'Pasig City',
                'area' => '650 sqm',
                'capacity_label' => '30–350 pax',
                'capacity_min' => 30,
                'capacity_max' => 350,
                'venue_type' => 'Conference',
                'category' => 'conference',
                'price_per_event' => 38000,
                'status' => 'pending',
                'is_featured' => false,
                'badge' => 'New',
                'rating' => 4.7,
                'review_count' => 56,
                'inquiries_count' => 7,
                'bookings_count' => 0,
                'image_color' => '#3f2e1e',
                'updated_at' => '2026-05-25 09:15:00',
                'description' => 'A purpose-built conference center along Ortigas Ave with divisible halls, built-in projection walls, and breakout rooms.',
                'max_capacity_note' => 'guests · theater / classroom',
            ],
            [
                'slug' => 'crystal-ballroom-peninsula',
                'name' => 'Crystal Ballroom at The Peninsula',
                'organization_name' => 'Peninsula Events',
                'location' => 'Ayala Ave',
                'city' => 'Makati City',
                'area' => '720 sqm',
                'capacity_label' => '100–400 pax',
                'capacity_min' => 100,
                'capacity_max' => 400,
                'venue_type' => 'Ballroom',
                'category' => 'ballrooms',
                'price_per_event' => 95000,
                'status' => 'published',
                'is_featured' => true,
                'badge' => 'Featured',
                'rating' => 4.9,
                'review_count' => 31,
                'inquiries_count' => 24,
                'bookings_count' => 9,
                'image_color' => '#312e81',
                'updated_at' => '2026-05-30 16:45:00',
                'description' => 'A grand ballroom with crystal chandeliers, marble floors, and a dedicated bridal suite. Premium in-house catering and white-glove coordination.',
                'packages' => [
                    ['id' => 'full-day', 'label' => 'Full-day (10 hrs)', 'priceFrom' => 95000, 'note' => 'Full-day package · final rate varies by date & setup'],
                    ['id' => 'premium', 'label' => 'Premium wedding package', 'priceFrom' => 128000, 'note' => 'Premium package · final rate varies by date & setup'],
                ],
            ],
            [
                'slug' => 'loft-42-creative-studio',
                'name' => 'Loft 42 Creative Studio',
                'organization_name' => 'Loft 42 Studio',
                'location' => 'Poblacion',
                'city' => 'Makati City',
                'area' => '180 sqm',
                'capacity_label' => '20–80 pax',
                'capacity_min' => 20,
                'capacity_max' => 80,
                'venue_type' => 'Loft / studio',
                'category' => 'loft',
                'price_per_event' => 22000,
                'status' => 'draft',
                'is_featured' => false,
                'badge' => null,
                'rating' => 4.6,
                'review_count' => 19,
                'inquiries_count' => 2,
                'bookings_count' => 0,
                'image_color' => '#1c1917',
                'updated_at' => '2026-05-20 11:00:00',
                'description' => 'An industrial loft studio in Poblacion with exposed brick, natural light, and a compact footprint for creative shoots and brand activations.',
            ],
            [
                'slug' => 'garden-terrace-events',
                'name' => 'Garden Terrace Events',
                'organization_name' => 'Garden Terrace PH',
                'location' => 'McKinley Rd',
                'city' => 'Taguig City',
                'area' => '500 sqm',
                'capacity_label' => '60–250 pax',
                'capacity_min' => 60,
                'capacity_max' => 250,
                'venue_type' => 'Outdoor / garden',
                'category' => 'outdoor',
                'price_per_event' => 48000,
                'status' => 'published',
                'is_featured' => false,
                'badge' => 'Top rated',
                'rating' => 4.8,
                'review_count' => 37,
                'inquiries_count' => 14,
                'bookings_count' => 4,
                'image_color' => '#134e4a',
                'updated_at' => '2026-06-02 08:20:00',
                'description' => 'A garden terrace venue with landscaped lawns, covered pavilion, and sunset views over McKinley.',
            ],
        ];

        foreach ($venues as $venue) {
            $price = $venue['price_per_event'];

            $listing = VenueListing::updateOrCreate(
                ['slug' => $venue['slug']],
                [
                    'organization_uuid' => $organizations[$venue['organization_name']]->uuid,
                    'name' => $venue['name'],
                    'description' => $venue['description'],
                    'address' => $venue['location'] . ', ' . $venue['city'],
                    'location' => $venue['location'],
                    'city' => $venue['city'],
                    'region' => 'Metro Manila',
                    'area' => $venue['area'],
                    'capacity_label' => $venue['capacity_label'],
                    'capacity_min' => $venue['capacity_min'],
                    'capacity_max' => $venue['capacity_max'],
                    'venue_type' => $venue['venue_type'],
                    'category' => $venue['category'],
                    'price_per_event' => $price,
                    'currency' => 'PHP',
                    'status' => $venue['status'],
                    'is_featured' => $venue['is_featured'],
                    'badge' => $venue['badge'],
                    'rating' => $venue['rating'],
                    'review_count' => $venue['review_count'],
                    'inquiries_count' => $venue['inquiries_count'],
                    'bookings_count' => $venue['bookings_count'],
                    'image_color' => $venue['image_color'],
                    'verified' => true,
                    'responds_in' => '24 hrs',
                    'packages' => $venue['packages'] ?? [
                        ['id' => 'full-day', 'label' => 'Full-day (8 hrs)', 'priceFrom' => $price, 'note' => 'Full-day package · final rate varies by date & setup'],
                        ['id' => 'premium', 'label' => 'Premium package', 'priceFrom' => (int) round($price * 1.35), 'note' => 'Premium package · final rate varies by date & setup'],
                    ],
                    'default_package_id' => 'full-day',
                    'min_capacity_note' => $venue['min_capacity_note'] ?? 'guests · intimate setups & meetings',
                    'max_capacity_note' => $venue['max_capacity_note'] ?? 'guests · full banquet / standing',
                    'setups' => $venue['setups'] ?? $baseSetups,
                    'specs' => $venue['specs'] ?? [
                        ['label' => 'Floor area', 'value' => $venue['area']],
                        ['label' => 'Ceiling height', 'value' => '6.5 m'],
                        ['label' => 'Parking slots', 'value' => '120 (basement + valet)'],
                        ['label' => 'Power supply', 'value' => '200 kVA · 3-phase'],
                        ['label' => 'Load-in access', 'value' => '2 freight elevators · ramp'],
                        ['label' => 'Curfew', 'value' => '2:00 AM (extensions available)'],
                    ],
                    'best_for' => $baseBestFor,
                    'amenities' => $baseAmenities,
                    'reviews' => $baseReviews,
                    'updated_at' => $venue['updated_at'],
                ]
            );

            $this->seedListingImages($listing, $venue);
        }

        DB::commit();
    }

    private function seedListingImages(VenueListing $listing, array $venue): void
    {
        $listing->uploads()->delete();

        $slug = $venue['slug'];
        $imageColor = $venue['image_color'];

        Upload::create([
            'uploadable_type' => VenueListing::class,
            'uploadable_uuid' => $listing->uuid,
            'collection'      => 'featured',
            'type'            => 'image',
            'mime_type'       => 'image/jpeg',
            'extension'       => 'jpg',
            'disk'            => 'public',
            'path'            => "https://picsum.photos/seed/{$slug}-featured/1200/800",
            'dominant_color'  => $imageColor,
            'order_number'    => 0,
            'name'            => "{$venue['name']} featured",
        ]);

        foreach ($this->buildGalleryColors($imageColor) as $index => $color) {
            Upload::create([
                'uploadable_type' => VenueListing::class,
                'uploadable_uuid' => $listing->uuid,
                'collection'      => 'gallery',
                'type'            => 'image',
                'mime_type'       => 'image/jpeg',
                'extension'       => 'jpg',
                'disk'            => 'public',
                'path'            => "https://picsum.photos/seed/{$slug}-gallery-{$index}/1200/800",
                'dominant_color'  => $color,
                'order_number'    => $index,
                'name'            => "{$venue['name']} gallery " . ($index + 1),
            ]);
        }
    }

    private function upsertOrganization(string $name): Organization
    {
        $slug = Str::slug($name);

        return Organization::firstOrCreate(
            ['name' => $name],
            [
                'business_type' => Organization::BUSINESS_TYPE_CORPORATION,
                'representative_first_name' => Str::before($name, ' '),
                'representative_last_name' => 'Admin',
                'email' => $slug . '@venue-seed.test',
                'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
            ]
        );
    }

    /**
     * @return list<string>
     */
    private function buildGalleryColors(string $hex): array
    {
        return [
            $hex,
            $this->shadeColor($hex, 0.15),
            $this->shadeColor($hex, -0.1),
            $this->shadeColor($hex, 0.25),
            $this->shadeColor($hex, -0.15),
        ];
    }

    private function shadeColor(string $hex, float $amount): string
    {
        $normalized = ltrim($hex, '#');
        if (strlen($normalized) !== 6) {
            return $hex;
        }

        $channels = [
            hexdec(substr($normalized, 0, 2)),
            hexdec(substr($normalized, 2, 2)),
            hexdec(substr($normalized, 4, 2)),
        ];

        $shaded = array_map(function (int $channel) use ($amount) {
            return max(0, min(255, (int) round($channel + $amount * 255)));
        }, $channels);

        return sprintf('#%02x%02x%02x', $shaded[0], $shaded[1], $shaded[2]);
    }
}
