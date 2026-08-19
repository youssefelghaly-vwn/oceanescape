<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per redirect into Lodgify's checkout.
     *
     * WHY THIS EXISTS, given that Lodgify holds the reservation
     *
     * The moment a guest is redirected, our site loses sight of them. Lodgify
     * will know whether a booking happened; it will not know that the guest came
     * from here, what we quoted them, or which add-ons they picked on our page.
     *
     * This table answers three questions Lodgify cannot:
     *   ATTRIBUTION  how many bookings originated on the new site, versus
     *                Airbnb, Booking.com or the phone
     *   ABANDONMENT  who reached checkout and did not finish — a follow-up list
     *                and a conversion number
     *   WHAT WE SHOWED  the total on our summary, in case it ever diverges from
     *                what Lodgify charges
     *
     * Deliberately NOT a booking record. Lodgify owns the booking.
     */
    public function up(): void
    {
        Schema::create('checkout_intents', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 16)->unique();

            $table->unsignedBigInteger('cottage_id');
            $table->string('cottage_name');
            $table->date('arrival');
            $table->date('departure');
            $table->unsignedSmallInteger('nights');
            $table->unsignedSmallInteger('adults')->default(2);
            $table->unsignedSmallInteger('children')->default(0);
            $table->unsignedSmallInteger('pets')->default(0);

            // what we quoted, for comparison against Lodgify later
            $table->decimal('quoted_total', 10, 2)->nullable();
            $table->char('currency', 3)->default('CAD');
            $table->json('addons')->nullable();

            // the exact URL we sent them to — invaluable when debugging a
            // checkout that behaved unexpectedly
            $table->text('redirect_url');

            /*
             * Conversion is inferred, not known, unless a webhook tells us.
             *   redirected -> guest sent to Lodgify
             *   converted  -> a matching Lodgify booking was found
             *   abandoned  -> no match after the grace period
             */
            $table->string('status')->default('redirected')->index();
            $table->string('lodgify_booking_id')->nullable()->index();
            $table->timestamp('converted_at')->nullable();

            // provenance
            $table->string('referrer', 512)->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('session_id', 100)->nullable()->index();

            $table->timestamps();

            $table->index(['cottage_id', 'arrival']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_intents');
    }
};