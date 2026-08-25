<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Our record of a booking we created in Lodgify.
 *
 * HOW THIS DIFFERS FROM checkout_intents
 * `checkout_intents` records that we sent someone AWAY to Lodgify's checkout and then
 * lost sight of them. This table is the opposite: we now own the transaction, so this
 * is a real booking record with a money lifecycle attached.
 *
 * LODGIFY IS STILL THE SYSTEM OF RECORD FOR THE RESERVATION.
 * This row is not the reservation. `lodgify_booking_id` points at the reservation, and
 * `lodgify_status` is a cache of what Lodgify last told us. Anything that needs the
 * authoritative state must read Lodgify (ReservationRepository), not this table. What
 * this table IS authoritative for is the money: what we quoted, what we asked for, what
 * Stripe actually took, and whether we managed to tell Lodgify about it.
 *
 * MONEY IS STORED IN INTEGER CENTS, never as a float or a decimal string. Lodgify hands
 * back floats; they are converted at the boundary by App\Support\Money and never
 * round-tripped through float arithmetic here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();

            // Human-readable handle for phone and email. Same alphabet rules as
            // BusinessStayRequest::generateReference() so it survives being read aloud.
            $table->string('reference', 16)->unique();

            /*
             * Set once the Lodgify reservation exists. Nullable because the row is
             * written first — if the Lodgify write fails we keep the attempt and its
             * error rather than losing the guest's details.
             *
             * Unique so a retry can never attach a second local booking to one
             * reservation. Both SQLite and MySQL permit multiple NULLs here.
             */
            $table->string('lodgify_booking_id')->nullable()->unique();
            $table->string('lodgify_status')->nullable();     // Open | Booked | Declined | ...

            // Our own lifecycle. See App\Enums\BookingStatus for the transitions.
            $table->string('status')->default('pending_lodgify')->index();

            // --- the stay ---
            $table->unsignedBigInteger('cottage_id');          // Lodgify property id
            $table->string('cottage_name');
            $table->unsignedBigInteger('room_type_id')->nullable();
            $table->date('arrival');
            $table->date('departure');
            $table->unsignedSmallInteger('nights');

            $table->unsignedSmallInteger('adults')->default(2);
            $table->unsignedSmallInteger('children')->default(0);
            $table->unsignedSmallInteger('infants')->default(0);
            $table->unsignedSmallInteger('pets')->default(0);

            // --- the guest ---
            $table->string('guest_name');
            $table->string('guest_email');
            $table->string('guest_phone')->nullable();
            $table->string('guest_country', 2)->nullable();
            $table->text('guest_notes')->nullable();

            /*
             * Set when a signed-in user books. Nullable because booking does not
             * require an account — and note this is NOT how /my-stays matches
             * reservations (that matches on verified email, see ProfileController).
             */
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // --- money, all in minor units ---
            $table->char('currency', 3);
            $table->unsignedInteger('total_cents');
            $table->unsignedInteger('deposit_cents');
            $table->unsignedInteger('balance_cents');

            /*
             * True when the stay is close enough that we asked for the whole amount in
             * one payment instead of deposit-then-balance
             * (config booking.full_payment_within_days).
             */
            $table->boolean('requires_full_payment')->default(false);

            /*
             * The Lodgify quote we priced this booking from, and the payment schedule we
             * read the deposit out of. Kept verbatim so that if a guest ever disputes an
             * amount we can show exactly what Lodgify told us at the time, rather than
             * re-quoting against rates that may since have changed.
             */
            $table->json('quote_snapshot')->nullable();
            $table->json('payment_schedule')->nullable();
            $table->json('addons')->nullable();

            // --- Lodgify write bookkeeping ---
            $table->timestamp('lodgify_created_at')->nullable();
            $table->timestamp('booked_at')->nullable();          // when it flipped to Booked
            $table->unsignedSmallInteger('lodgify_sync_attempts')->default(0);
            $table->text('lodgify_sync_error')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();

            /*
             * Guards double submission. Derived from the stay + guest + session so that
             * a double-clicked confirm button, or a retried request, resolves to the
             * SAME booking instead of creating two reservations for the same nights.
             */
            $table->string('idempotency_key', 64)->unique();

            // Continuity with the pre-existing attribution table.
            $table->foreignId('checkout_intent_id')->nullable()
                ->constrained('checkout_intents')->nullOnDelete();

            // --- provenance ---
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('session_id', 100)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
            $table->index('guest_email');
            // Drives the balance-link scheduler: "unpaid bookings arriving soon".
            $table->index(['arrival', 'status']);
            $table->index(['cottage_id', 'arrival']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
