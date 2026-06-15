<?php

namespace App\Console\Commands;

use App\Models\Place;
use App\Models\Venue;
use Illuminate\Console\Command;

class CreatePlace extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:create-place';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Interactively create a new Place record';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🗺️ Let’s create a new Place and its Venue!');
        $this->line('------------------------------------');

        // === Step 1: Ask for PLACE details ===
        $name = $this->askRequired('Enter place name');
        $code = $this->askRequired('Enter place code');
        $address = $this->askRequired('Enter place address');

        $this->table(['Field', 'Value'], [
            ['Name', $name],
            ['Code', $code],
            ['Address', $address],
        ]);

        if (! $this->confirm('Do you want to save this Place?', true)) {
            $this->warn('Operation cancelled.');
            return self::SUCCESS;
        }

        $place = Place::create([
            'name' => $name,
            'code' => $code,
            'address' => $address,
        ]);

        $this->info("✅ Place '{$place->name}' created successfully with ID: {$place->id}");
        $this->line('');

        // === Step 2: Create a Venue ===
        $this->info('🎭 Let’s create a Venue for this Place!');
        $this->line('------------------------------------');

        // Ask if user wants to use existing place or the one just created
        if ($this->confirm('Use the newly created Place?', true)) {
            $placeUuid = $place->uuid ?? $place->id;
        } else {
            $placeUuid = $this->chooseExistingPlace();
        }

        $venueName = $this->askRequired('Enter venue name');
        $venueCode = $this->askRequired('Enter venue code');

        // Simple validation for type
        $types = ['musical', 'theater', 'amusement', 'others'];
        $venueType = $this->choice('Select venue type', $types, 0);

        $this->table(['Field', 'Value'], [
            ['Place UUID', $placeUuid],
            ['Venue Name', $venueName],
            ['Venue Code', $venueCode],
            ['Venue Type', $venueType],
        ]);

        if (! $this->confirm('Do you want to save this Venue?', true)) {
            $this->warn('Operation cancelled.');
            return self::SUCCESS;
        }

        $venue = Venue::create([
            'place_uuid' => $placeUuid,
            'name' => $venueName,
            'code' => $venueCode,
            'type' => $venueType,
        ]);

        $this->info("✅ Venue '{$venue->name}' created successfully under Place '{$place->name}'!");

        return self::SUCCESS;
    }

    /**
     * Ask for a required field until it’s not empty.
     */
    private function askRequired(string $question): string
    {
        $value = trim($this->ask($question));

        while (empty($value)) {
            $this->error('This field is required.');
            $value = trim($this->ask($question));
        }

        return $value;
    }

    /**
     * Let the user pick an existing place.
     */
    private function chooseExistingPlace(): string
    {
        $places = Place::all(['uuid', 'name']);

        if ($places->isEmpty()) {
            $this->warn('No existing places found. Please create a new one first.');
            exit;
        }

        $choices = $places->mapWithKeys(fn ($p) => [$p->uuid => $p->name])->toArray();
        $choice = $this->choice('Select a Place', $choices);

        // Find the selected UUID by name
        $uuid = array_search($choice, $choices);

        return $uuid;
    }
}
