<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\GeneralHelper;
use App\Models\Event;

class GenerateSlugEventName extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:generate-slug-event-name';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate slug for event name';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $events = Event::whereNull('slug')->get();
        foreach ($events as $event) {
            $event->slug = GeneralHelper::generateSlug($event->event_name);
            $event->save();
        }
    }
}
