<?php

namespace Database\Seeders;

use App\Actions\CreateShowtimeSeats;
use App\Actions\GenerateSeatGrid;
use App\Enums\EventCategory;
use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Showtime;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Database\Seeder;

/**
 * Local demo data: three published events with venues, seat layouts and showtimes,
 * so the frontend has something to show. Safe to run more than once.
 */
class DemoEventsSeeder extends Seeder
{
    public function run(GenerateSeatGrid $grid, CreateShowtimeSeats $createSeats): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('DemoEventsSeeder skipped: demo data only exists in local and testing.');

            return;
        }

        $organizer = User::where('email', 'organizer@example.com')->first();
        $admin = User::where('email', 'admin@example.com')->first();

        if (! $organizer || ! $admin) {
            $this->command?->error('Run DemoUserSeeder first (php artisan db:seed).');

            return;
        }

        $catalog = [
            [
                'title' => 'Neon Nights: Midnight Cinema',
                'slug' => 'neon-nights-midnight-cinema',
                'category' => EventCategory::Film,
                'description' => 'A late-night screening of a neon-soaked sci-fi thriller, with a live Q&A from the director after the credits.',
                'venue' => ['name' => 'Cinema Hall 1', 'city' => 'Jakarta', 'address' => 'Jl. Sudirman No. 10'],
                'sections' => [
                    ['name' => 'Premium', 'rows' => 2, 'seats_per_row' => 8, 'price' => 90000],
                    ['name' => 'Regular', 'rows' => 4, 'seats_per_row' => 8, 'price' => 50000],
                ],
                'days' => [5, 6],
            ],
            [
                'title' => 'Jakarta Rock Fest',
                'slug' => 'jakarta-rock-fest',
                'category' => EventCategory::Concert,
                'description' => 'One evening, five bands, and a very loud sound system. Seated tickets with the best view in the front rows.',
                'venue' => ['name' => 'Gelora Arena', 'city' => 'Jakarta', 'address' => 'Jl. Gatot Subroto No. 1'],
                'sections' => [
                    ['name' => 'VIP', 'rows' => 2, 'seats_per_row' => 10, 'price' => 750000],
                    ['name' => 'Gold', 'rows' => 3, 'seats_per_row' => 10, 'price' => 450000],
                    ['name' => 'Regular', 'rows' => 4, 'seats_per_row' => 10, 'price' => 250000],
                ],
                'days' => [14],
            ],
            [
                'title' => 'Bandung Symphony Evening',
                'slug' => 'bandung-symphony-evening',
                'category' => EventCategory::Orchestra,
                'description' => 'The city orchestra performs Beethoven and Tchaikovsky in the historic concert hall.',
                'venue' => ['name' => 'Grand Concert Hall', 'city' => 'Bandung', 'address' => 'Jl. Asia Afrika No. 65'],
                'sections' => [
                    ['name' => 'Orchestra', 'rows' => 3, 'seats_per_row' => 12, 'price' => 400000],
                    ['name' => 'Balcony', 'rows' => 2, 'seats_per_row' => 12, 'price' => 200000],
                ],
                'days' => [9, 10],
            ],
        ];

        foreach ($catalog as $item) {
            $existing = Event::where('slug', $item['slug'])->first();

            if ($existing) {
                $this->fixEveningTimes($existing);

                continue;
            }

            $venue = Venue::create([...$item['venue'], 'organizer_id' => $organizer->id]);
            $grid->handle($venue, array_map(
                fn (array $s) => ['name' => $s['name'], 'rows' => $s['rows'], 'seats_per_row' => $s['seats_per_row']],
                $item['sections'],
            ));
            $venue->load('sections');

            $prices = [];
            foreach ($venue->sections as $section) {
                $prices[$section->id] = collect($item['sections'])->firstWhere('name', $section->name)['price'];
            }

            $event = Event::create([
                'organizer_id' => $organizer->id,
                'title' => $item['title'],
                'slug' => $item['slug'],
                'category' => $item['category'],
                'description' => $item['description'],
            ]);

            // Status and review fields are not mass assignable, so they are set explicitly.
            $event->status = EventStatus::Published;
            $event->reviewed_by = $admin->id;
            $event->reviewed_at = now();
            $event->save();

            foreach ($item['days'] as $day) {
                $showtime = Showtime::create([
                    'event_id' => $event->id,
                    'venue_id' => $venue->id,
                    // 19:30 Jakarta time. The column stores UTC, so convert before saving.
                    'starts_at' => now('Asia/Jakarta')->addDays($day)->setTime(19, 30)->setTimezone('UTC'),
                ]);

                $createSeats->handle($showtime, $prices);
            }
        }
    }

    /**
     * An earlier version of this seeder stored 19:30 as UTC, which shows as 02:30 in Jakarta.
     * Moves those showtimes to 19:30 Jakarta (12:30 UTC). Running it again changes nothing.
     */
    private function fixEveningTimes(Event $event): void
    {
        foreach ($event->showtimes as $showtime) {
            $startsAt = $showtime->starts_at->copy()->setTimezone('UTC');

            if ($startsAt->format('H:i:s') === '19:30:00') {
                $showtime->update(['starts_at' => $startsAt->subHours(7)]);
            }
        }
    }
}
