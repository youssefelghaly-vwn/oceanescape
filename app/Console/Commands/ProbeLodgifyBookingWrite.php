<?php

namespace App\Console\Commands;

use App\Services\Lodgify\LodgifyClient;
use App\Services\Lodgify\LodgifyRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Discover the real create-booking contract against a live Lodgify account.
 *
 * This exists because the request-body field names for POST /v1/reservation/booking are
 * not documented anywhere we can verify, and every other Lodgify shape in this codebase
 * was established the same way — see probeRatesCalendar(), probeBookings(), and the
 * `rates_param_style` config note explaining that Lodgify's rate endpoints use PascalCase
 * while the rest of v2 does not.
 *
 * ⚠ A SUCCESSFUL PROBE CREATES A REAL RESERVATION on a real property. It is labelled
 * "API PROBE — DELETE ME" in the guest name, the run stops at the first shape that works,
 * and the command prints the id plus the exact command to remove it. It still requires
 * --confirm, and it refuses to run in production.
 */
class ProbeLodgifyBookingWrite extends Command
{
    protected $signature = 'lodgify:probe-booking-write
        {--property= : Lodgify property id to test against}
        {--room= : Room type id. Discovered from the property when omitted}
        {--days=120 : How far ahead to place the test stay}
        {--confirm : Actually send the requests. Without this the command only shows what it would do}';

    protected $description = 'Discover the working request shape for creating a Lodgify booking';

    public function handle(LodgifyClient $client, LodgifyRepository $repository): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run in production. This creates real reservations.');

            return self::FAILURE;
        }

        $propertyId = (int) ($this->option('property') ?: 0);

        if ($propertyId === 0) {
            $this->line('No --property given; listing what this account exposes:');

            foreach ($repository->allCottages() as $cottage) {
                $this->line(sprintf(
                    '  %-10s %-34s rooms: %s',
                    $cottage->id,
                    Str::limit($cottage->name, 32),
                    implode(',', array_column($cottage->rooms, 'id')) ?: '—'
                ));
            }

            $this->newLine();
            $this->warn('Re-run with --property=<id> --confirm');

            return self::INVALID;
        }

        $cottage = $repository->cottage($propertyId);

        if (! $cottage) {
            $this->error("Property {$propertyId} not found on this account.");

            return self::FAILURE;
        }

        $roomTypeId = (int) ($this->option('room') ?: $cottage->primaryRoomId() ?: 0);

        if ($roomTypeId === 0) {
            $this->error('Could not determine a room type id. Pass --room=<id> explicitly.');

            return self::FAILURE;
        }

        /*
         * Placed far in the future and on a Tuesday-to-Thursday span to reduce the chance
         * of colliding with a real booking or tripping a minimum-stay rule.
         */
        $arrival = now()->addDays((int) $this->option('days'))->next('Tuesday');
        $departure = $arrival->copy()->addDays(2);

        $base = [
            'property_id' => $propertyId,
            'room_type_id' => $roomTypeId,
            'arrival' => $arrival->toDateString(),
            'departure' => $departure->toDateString(),
        ];

        $this->newLine();
        $this->line("Property   : {$propertyId} ({$cottage->name})");
        $this->line("Room type  : {$roomTypeId}");
        $this->line("Test stay  : {$base['arrival']} → {$base['departure']}");
        $this->line('Endpoint   : '.config('lodgify.write.create_booking_path'));

        if (! $this->option('confirm')) {
            $this->newLine();
            $this->warn('Dry run. Nothing sent. Add --confirm to probe for real.');
            $this->line('A successful probe CREATES A REAL RESERVATION that you must then delete.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Probing candidate payload shapes…');

        $results = $client->probeBookingWrite($base);

        $this->newLine();

        $winner = null;

        foreach ($results as $result) {
            $mark = $result['ok'] ? '<info>  OK  </info>' : '<comment>FAILED</comment>';
            $this->line(sprintf('%s  %-28s HTTP %s', $mark, $result['attempt'], $result['status']));

            if (! $result['ok'] && filled($result['body_excerpt'])) {
                $this->line('        '.str_replace("\n", ' ', (string) $result['body_excerpt']));
            }

            if ($result['ok']) {
                $winner = $result;
            }
        }

        $this->newLine();

        if ($winner === null) {
            $this->error('No candidate shape was accepted.');
            $this->line('Next step: check the raw error bodies above, then consult');
            $this->line('https://docs.lodgify.com/reference/post_v1-reservation-booking-1');
            $this->line('and add the correct names to config/lodgify.php → write.field_map.');

            return self::FAILURE;
        }

        $this->info("Accepted shape: {$winner['attempt']}");
        $this->newLine();
        $this->line('Payload that worked:');
        $this->line(json_encode($winner['sent'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->newLine();
        $this->line('Response:');
        $this->line(json_encode($winner['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (filled($winner['created_id'])) {
            $this->newLine();
            $this->warn("A REAL RESERVATION WAS CREATED: id {$winner['created_id']}");
            $this->line('Delete it now, either in the Lodgify dashboard or with:');
            $this->line("  php artisan tinker --execute=\"app(App\\\\Services\\\\Lodgify\\\\LodgifyClient::class)->deleteBooking('{$winner['created_id']}')\"");
        }

        $this->newLine();
        $this->line('Now transfer the accepted field names into config/lodgify.php → write.field_map');
        $this->line('(or the matching LODGIFY_FIELD_* env vars) so the flow uses them.');

        return self::SUCCESS;
    }
}
