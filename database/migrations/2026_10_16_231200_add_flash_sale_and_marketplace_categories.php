<?php

use App\Models\Category;
use App\Models\Event;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var array<int, array{name: string, code: string, type: string}> */
    private array $categories = [
        ['name' => 'SALE', 'code' => 'sale', 'type' => 'fun'],
        ['name' => 'Events', 'code' => 'events', 'type' => 'fun'],
        ['name' => 'Cultural and Heritage', 'code' => 'cultural_heritage', 'type' => 'fun'],
        ['name' => 'Team Building', 'code' => 'team_building', 'type' => 'fun'],
        ['name' => 'Science', 'code' => 'science', 'type' => 'fun'],
        ['name' => 'Outdoor activities', 'code' => 'outdoor_activities', 'type' => 'fun'],
        ['name' => 'Art and craft', 'code' => 'art_and_craft', 'type' => 'fun'],
        ['name' => 'Food', 'code' => 'food', 'type' => 'fun'],
        ['name' => 'Celebration', 'code' => 'celebration', 'type' => 'fun'],
        ['name' => 'Annual pass', 'code' => 'annual_pass', 'type' => 'fun'],
    ];

    /** @var array<string, string> old code => new code */
    private array $remap = [
        'cultural_heritage' => 'cultural_heritage',
        'food_booths' => 'food',
        'food' => 'food',
        'games' => 'events',
        'attractions' => 'events',
        'concerts_performances' => 'events',
        'sports' => 'events',
        'outdoor_adventure' => 'outdoor_activities',
        'nature_eco_attraction' => 'outdoor_activities',
        'museum_educational_attraction' => 'science',
        'arts' => 'art_and_craft',
        'workshops' => 'art_and_craft',
        'weddings_private_celebrations' => 'celebration',
        'corporate_internal' => 'team_building',
        'trainings_masterclasses' => 'team_building',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('event_tickets', 'feature_on_flash_sale')) {
            Schema::table('event_tickets', function (Blueprint $table) {
                $table->boolean('feature_on_flash_sale')
                    ->default(false)
                    ->after('available_to');
            });
        }

        if (! Schema::hasColumn('event_tickets', 'flash_sale_from')) {
            Schema::table('event_tickets', function (Blueprint $table) {
                $table->timestamp('flash_sale_from')->nullable()->after('feature_on_flash_sale');
                $table->timestamp('flash_sale_to')->nullable()->after('flash_sale_from');
            });

            // Existing flash tickets used available_from/to as the sale window.
            DB::statement(
                'UPDATE event_tickets
                 SET flash_sale_from = available_from,
                     flash_sale_to = available_to,
                     available_from = NULL,
                     available_to = NULL
                 WHERE feature_on_flash_sale = 1
                   AND deleted_at IS NULL
                   AND flash_sale_from IS NULL
                   AND flash_sale_to IS NULL'
            );
        }

        $this->replaceMarketplaceCategories();
    }

    public function down(): void
    {
        if (Schema::hasColumn('event_tickets', 'flash_sale_from')) {
            DB::statement(
                'UPDATE event_tickets
                 SET available_from = COALESCE(available_from, flash_sale_from),
                     available_to = COALESCE(available_to, flash_sale_to)
                 WHERE feature_on_flash_sale = 1
                   AND deleted_at IS NULL'
            );

            Schema::table('event_tickets', function (Blueprint $table) {
                $table->dropColumn(['flash_sale_from', 'flash_sale_to']);
            });
        }

        if (Schema::hasColumn('event_tickets', 'feature_on_flash_sale')) {
            Schema::table('event_tickets', function (Blueprint $table) {
                $table->dropColumn('feature_on_flash_sale');
            });
        }

        // Category replacement is not reversed — restore via CategorySeeder / CSV if needed.
    }

    private function replaceMarketplaceCategories(): void
    {
        DB::transaction(function () {
            $keepCodes = array_column($this->categories, 'code');
            $newByCode = [];

            foreach ($this->categories as $row) {
                $category = Category::withTrashed()->updateOrCreate(
                    ['code' => $row['code']],
                    [
                        'name' => $row['name'],
                        'type' => $row['type'],
                        'status' => 'active',
                        'deleted_at' => null,
                    ],
                );
                $newByCode[$row['code']] = $category->uuid;
            }

            $legacy = Category::withTrashed()
                ->whereNotIn('code', $keepCodes)
                ->get();

            foreach ($legacy as $old) {
                $targetCode = $this->remap[$old->code]
                    ?? $this->guessRemap($old->name)
                    ?? 'events';
                $targetUuid = $newByCode[$targetCode] ?? $newByCode['events'];

                Event::withTrashed()
                    ->where('category_uuid', $old->uuid)
                    ->update(['category_uuid' => $targetUuid]);

                if (! $old->trashed()) {
                    $old->delete();
                }
            }
        });
    }

    private function guessRemap(string $name): ?string
    {
        $lower = Str::lower($name);
        if (str_contains($lower, 'food')) {
            return 'food';
        }
        if (str_contains($lower, 'cultur') || str_contains($lower, 'heritage')) {
            return 'cultural_heritage';
        }
        if (str_contains($lower, 'outdoor') || str_contains($lower, 'nature') || str_contains($lower, 'adventure')) {
            return 'outdoor_activities';
        }
        if (str_contains($lower, 'science') || str_contains($lower, 'museum')) {
            return 'science';
        }
        if (str_contains($lower, 'art') || str_contains($lower, 'craft')) {
            return 'art_and_craft';
        }
        if (str_contains($lower, 'celebrat') || str_contains($lower, 'wedding')) {
            return 'celebration';
        }
        if (str_contains($lower, 'team') || str_contains($lower, 'corporate') || str_contains($lower, 'training')) {
            return 'team_building';
        }
        if (str_contains($lower, 'sale')) {
            return 'sale';
        }
        if (str_contains($lower, 'pass')) {
            return 'annual_pass';
        }

        return null;
    }
};
