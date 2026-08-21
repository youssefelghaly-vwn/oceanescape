<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * Validation for a direct booking.
 *
 * WHAT IS DELIBERATELY ABSENT: any money field.
 *
 * The request carries dates, party size and guest details. It does NOT carry a total, a
 * deposit, or a currency, and there is no rule here for one — because a price that a
 * request can influence is a price a guest can choose. Everything monetary is re-derived
 * server-side from a live Lodgify quote by QuoteReader and DepositPolicy.
 *
 * (Contrast the pre-existing BookingRedirectController, which accepts a `total` — harmless
 * there because it was only recorded for later comparison and Lodgify did the charging.)
 */
class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // public booking form
    }

    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:190'],
            'arrival' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'departure' => ['required', 'date_format:Y-m-d', 'after:arrival'],

            'adults' => ['required', 'integer', 'min:1', 'max:20'],
            'children' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'infants' => ['sometimes', 'integer', 'min:0', 'max:10'],
            'pets' => ['sometimes', 'integer', 'min:0', 'max:10'],

            'guest_name' => ['required', 'string', 'min:2', 'max:120'],
            /*
             * `rfc` only, deliberately NOT `rfc,dns`.
             *
             * A DNS check would catch a typo'd domain — which matters here, because a wrong
             * address means the guest never receives their payment link. But it puts a
             * blocking DNS lookup in the booking request path, where a slow or unreachable
             * resolver turns into a failed booking. The rest of this codebase
             * (StoreContactMessageRequest, StoreBusinessStayRequest, RegisterController)
             * settled on `rfc` for the same reason.
             *
             * The mitigation is downstream instead: the confirmation page shows the address
             * we sent the link to, so a typo is visible immediately rather than silent.
             */
            'guest_email' => ['required', 'email:rfc', 'max:180'],
            'guest_phone' => ['required', 'string', 'min:6', 'max:40'],
            // ISO 3166-1 alpha-2. Sent to Lodgify as `country_code`.
            'guest_country' => ['sometimes', 'nullable', 'string', 'size:2', 'alpha'],
            'guest_notes' => ['sometimes', 'nullable', 'string', 'max:1000'],

            // Guest must actively accept before we create a reservation in their name.
            'terms_accepted' => ['accepted'],

            'utm_source' => ['sometimes', 'nullable', 'string', 'max:120'],
            'utm_medium' => ['sometimes', 'nullable', 'string', 'max:120'],
            'utm_campaign' => ['sometimes', 'nullable', 'string', 'max:120'],

            // Honeypot, matching the other public forms in this codebase.
            'website_url' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'guest_name.required' => 'Please give us a name for the booking.',
            'guest_email.required' => 'We need an email address to send your payment link to.',
            'guest_email.email' => 'That email address does not look right — the payment link goes there.',
            'guest_phone.required' => 'A phone number, in case we need to reach you about your stay.',
            'departure.after' => 'Check-out has to be after check-in.',
            'arrival.after_or_equal' => 'That arrival date is in the past.',
            'terms_accepted.accepted' => 'Please accept the booking terms to continue.',
            'website_url.prohibited' => 'Something went wrong. Please try again.',
        ];
    }

    /**
     * Send validation failures back to the details form, not to "/".
     *
     * FormRequest failures are redirected by Laravel itself, BEFORE the controller runs —
     * so the controller's own error handling never sees them. The default is back(), which
     * depends on the Referer header; without one it bounces to the site root and the errors
     * are silently swallowed. Building the URL from the submitted stay makes it
     * deterministic.
     */
    protected function getRedirectUrl(): string
    {
        if (filled($this->input('slug')) && filled($this->input('arrival'))) {
            return $this->redirector->getUrlGenerator()->route('booking.details', array_filter([
                'slug' => $this->input('slug'),
                'arrival' => $this->input('arrival'),
                'departure' => $this->input('departure'),
                'adults' => $this->input('adults'),
                'children' => $this->input('children'),
                'pets' => $this->input('pets'),
            ], fn ($v) => $v !== null && $v !== ''));
        }

        return parent::getRedirectUrl();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'guest_email' => strtolower(trim((string) $this->input('guest_email'))),
            'guest_country' => $this->filled('guest_country')
                ? strtoupper((string) $this->input('guest_country'))
                : null,
        ]);
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                /*
                 * Cap the stay length. Not a business rule so much as a guard: Lodgify
                 * enforces its own max_stay, but an absurd range makes us walk a huge
                 * date span in the availability and rate calendars before Lodgify ever
                 * sees it.
                 */
                if ($this->filled(['arrival', 'departure'])) {
                    try {
                        $nights = Carbon::parse($this->input('arrival'))
                            ->diffInDays(Carbon::parse($this->input('departure')));

                        if ($nights > 90) {
                            $validator->errors()->add(
                                'departure',
                                'That is a stay of over 90 nights — please contact us directly to arrange it.'
                            );
                        }
                    } catch (\Throwable) {
                        // date_format rules have already reported anything unparseable
                    }
                }

                // Someone must be staying.
                if ((int) $this->input('adults', 0) < 1) {
                    $validator->errors()->add('adults', 'At least one adult has to be on the booking.');
                }
            },
        ];
    }
}
