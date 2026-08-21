<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    protected $model = Booking::class;

    public function definition(): array
    {
        $arrival = now()->addDays(60)->startOfDay();
        $nights = $this->faker->numberBetween(2, 7);

        // Money kept internally consistent: deposit + balance == total, in cents.
        $total = $this->faker->numberBetween(40000, 300000);
        $deposit = (int) round($total * 0.25);

        return [
            'reference' => Booking::generateReference(),
            'status' => BookingStatus::PendingLodgify,
            'cottage_id' => $this->faker->numberBetween(700000, 900000),
            'cottage_name' => 'Cottage '.$this->faker->numberBetween(1, 6),
            'room_type_id' => $this->faker->numberBetween(800000, 900000),
            'arrival' => $arrival,
            'departure' => $arrival->copy()->addDays($nights),
            'nights' => $nights,
            'adults' => 2,
            'children' => 0,
            'infants' => 0,
            'pets' => 0,
            'guest_name' => $this->faker->name(),
            'guest_email' => $this->faker->unique()->safeEmail(),
            'guest_phone' => '+19025551234',
            'guest_country' => 'CA',
            'currency' => 'CAD',
            'total_cents' => $total,
            'deposit_cents' => $deposit,
            'balance_cents' => $total - $deposit,
            'requires_full_payment' => false,
            'idempotency_key' => hash('sha256', $this->faker->unique()->uuid()),
        ];
    }

    /** Reservation exists in Lodgify as Open, deposit link out, nothing paid. */
    public function awaitingDeposit(): static
    {
        return $this->state(fn () => [
            'status' => BookingStatus::AwaitingDeposit,
            'lodgify_booking_id' => (string) $this->faker->numberBetween(10000000, 99999999),
            'lodgify_status' => 'Open',
            'lodgify_created_at' => now(),
        ]);
    }

    /** Deposit paid, reservation Booked, balance still owing. */
    public function depositPaid(): static
    {
        return $this->state(fn () => [
            'status' => BookingStatus::DepositPaid,
            'lodgify_booking_id' => (string) $this->faker->numberBetween(10000000, 99999999),
            'lodgify_status' => 'Booked',
            'lodgify_created_at' => now()->subDay(),
            'booked_at' => now()->subDay(),
        ]);
    }

    public function arrivingIn(int $days): static
    {
        return $this->state(function () use ($days) {
            $arrival = now()->addDays($days)->startOfDay();

            return [
                'arrival' => $arrival,
                'departure' => $arrival->copy()->addDays(3),
                'nights' => 3,
            ];
        });
    }
}
