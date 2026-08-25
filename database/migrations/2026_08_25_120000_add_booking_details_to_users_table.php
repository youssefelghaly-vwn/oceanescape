<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two details a signed-in guest would otherwise retype on every booking.
 *
 * WHY THESE LIVE ON THE USER AND NOT ONLY ON THE BOOKING
 *
 * `bookings` already stores guest_phone and guest_country — that is the record of what was
 * given for THAT stay, and it must not change when a guest later updates their profile.
 * These columns are the prefill source for the NEXT booking: the direct-booking flow exists
 * to get a returning, signed-in guest from "Book now" to paid without re-entering details
 * we already hold, and phone was the one required field the form could not fill.
 *
 * Deliberately NOT added: any address, and above all nothing to do with payment. A card is
 * never stored — see App\Services\Payments\StripeGateway — so there is no card, token or
 * Stripe customer column here, and there should never be one. Prefilling DETAILS is the
 * whole convenience; a stored card is a different, larger promise to the guest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Free text, not a normalised format: Lodgify takes what we send, and a guest
            // typing "(902) 398-1020" should not fail validation on their own account page.
            $table->string('phone', 40)->nullable()->after('email');

            // ISO 3166-1 alpha-2, sent to Lodgify as `country_code`.
            $table->char('country', 2)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'country']);
        });
    }
};
