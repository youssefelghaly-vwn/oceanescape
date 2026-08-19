{{-- resources/views/pages/privacy-and-policy.blade.php --}}
<x-website-layout title="Rental Policies & Privacy | Ocean Escape Cottages">

    @php
        /*
         * Kept in the view rather than the database: these are legal terms that
         * change rarely and need to be reviewed deliberately, in version
         * control, not edited through an admin form.
         */
        $sections = [
            [
                'id' => 'booking-payments',
                'title' => 'Booking & Payments',
                'items' => [
                    'A 25% deposit is required at the time of booking to confirm the reservation.',
                    'The remaining balance is due on the day of arrival.',
                    'Accepted payment methods include credit card, cash, and approved electronic transfer.',
                    'Prices are subject to applicable taxes and fees.',
                ],
            ],
            [
                'id' => 'cancellation',
                'title' => 'Cancellation Policy',
                'items' => [
                    'More than 30 days prior to arrival: Full refund of the deposit.',
                    '30 days or less prior to arrival: Deposit is non-refundable.',
                    '14 days or less prior to arrival: 100% of the reservation total is non-refundable.',
                    'Early departures or unused nights are non-refundable.',
                    'Weather conditions, including storms, wind, rain, or power outages, do not qualify for refunds.',
                ],
            ],
            [
                'id' => 'check-in-out',
                'title' => 'Check-In & Check-Out',
                'items' => [
                    'Check-in: 3:00 PM or later',
                    'Check-out: 11:00 AM',
                    'Early check-in or late check-out may be available only by prior approval and may be subject to an additional fee.',
                    'Guests must vacate the property on time to allow for cleaning and preparation for incoming guests.',
                ],
            ],
            [
                'id' => 'occupancy',
                'title' => 'Occupancy Limits',
                'items' => [
                    'Oceanfront cottages have a maximum occupancy of 6 guests, and the chalet has a maximum occupancy of 8 guests.',
                    'Exceeding the maximum number of guests is not permitted.',
                    'Day visitors are not allowed without prior written approval.',
                    'Parties, events, or gatherings are strictly prohibited.',
                ],
            ],
            [
                'id' => 'pets',
                'title' => 'Pet Policy',
                'items' => [
                    'Only designated cottages are pet-friendly.',
                    'Pets are not permitted in non-pet-friendly units under any circumstances.',
                    'Approved pets must be disclosed at the time of booking.',
                    'Guests bringing pets are fully responsible for their pets’ behavior and for any actions, damages, or additional cleaning required. Pets must be leashed outdoors and are not permitted on furniture or beds.',
                    'Guests are responsible for cleaning up after their pets.',
                    'Any damage or excessive cleaning related to pets will result in additional charges.',
                ],
            ],
            [
                'id' => 'smoking',
                'title' => 'Smoking & Cannabis Policy',
                'items' => [
                    'All cottages are 100% smoke-free.',
                    'Smoking or vaping of any kind is prohibited indoors.',
                    'Smoking is permitted outdoors only, a minimum of 10 metres from buildings.',
                    'Improper disposal of smoking materials may result in additional cleaning or damage fees.',
                ],
            ],
            [
                'id' => 'hot-tub',
                'title' => 'Hot Tub Policy',
                'items' => [
                    'Use of hot tubs is at the guest’s own risk.',
                    'Children must be supervised at all times.',
                    'No glass, food, or alcohol is permitted in hot tubs.',
                    'Guests must follow all posted usage and safety instructions.',
                    'Hot tubs may be temporarily unavailable due to weather, maintenance, or safety concerns. No refunds will be issued for temporary unavailability.',
                ],
            ],
            [
                'id' => 'damage',
                'title' => 'Damage, Cleaning & Security',
                'items' => [
                    'Guests are responsible for the condition of the property during their stay.',
                    'A departure checklist is provided in each cottage and must be completed prior to check-out.',
                    'Any damage, missing items, or excessive cleaning will be charged to the guest.',
                    'Normal wear and tear is expected; however, misuse or negligence is not.',
                    'A security deposit or damage hold may be applied if required.',
                ],
            ],
            [
                'id' => 'housekeeping',
                'title' => 'Housekeeping',
                'items' => [
                    'Cottages are professionally cleaned prior to arrival.',
                    'Daily housekeeping is not provided.',
                    'For long-term stays, additional cleaning may be scheduled upon request for an additional fee.',
                ],
            ],
            [
                'id' => 'waste',
                'title' => 'Garbage & Recycling',
                'items' => [
                    'Guests must dispose of garbage and recycling in designated bins.',
                    'Failure to follow waste disposal guidelines may result in additional charges.',
                ],
            ],
            [
                'id' => 'parking',
                'title' => 'Parking',
                'items' => [
                    'Parking is limited to designated spaces only.',
                    'RVs, trailers, or boats are not permitted without prior approval.',
                ],
            ],
            [
                'id' => 'safety',
                'title' => 'Oceanfront & Safety Disclaimer',
                'items' => [
                    'Ocean Escape Cottages are located in a coastal environment.',
                    'Guests acknowledge risks associated with oceanfront properties, including uneven terrain, tides, strong winds, and changing weather conditions.',
                    'Children must be supervised at all times outdoors.',
                    'Guests assume full responsibility for their personal safety during their stay.',
                ],
            ],
            [
                'id' => 'maintenance',
                'title' => 'Maintenance & Emergencies',
                'items' => [
                    'Guests must report any issues or damage immediately.',
                    'Entry may be required for emergency repairs or safety reasons.',
                    'No refunds will be issued for minor maintenance issues or inconveniences.',
                ],
            ],
            [
                'id' => 'refusal',
                'title' => 'Right to Refuse Service',
                'items' => [
                    'Ocean Escape Cottages reserves the right to refuse service, terminate a stay, or cancel a reservation if policies are violated.',
                    'No refunds will be issued for terminations resulting from policy violations.',
                ],
            ],
            [
                'id' => 'agreement',
                'title' => 'Agreement to Policies',
                'items' => [
                    'By booking, guests acknowledge that they have read, understood, and agree to all rental policies.',
                ],
            ],
        ];
    @endphp

    {{-- ---------------------------------------------------------- HEADER --}}
    <section class="border-b border-fog-200 bg-fog-50 pb-10 pt-32">
        <div class="mx-auto max-w-5xl px-6 lg:px-8">
            <p class="font-mono text-[11px] uppercase tracking-[0.2em] text-brand-600">The fine print</p>
            <h1 class="mt-3 font-display text-3xl font-medium leading-tight text-ink-900 sm:text-4xl">
                Rental Policies &amp; Privacy
            </h1>
            <p class="mt-4 max-w-2xl text-base leading-relaxed text-tide-700">
                Everything that applies to your stay, in plain terms. If anything here is unclear,
                <a href="{{ route('contact') }}" class="font-medium text-brand-700 hover:underline">ask us</a>
                before you book &mdash; we&rsquo;d rather answer than surprise you.
            </p>
            <p class="mt-4 font-mono text-[10px] uppercase tracking-wide text-tide-500">
                Last updated {{ \Illuminate\Support\Carbon::parse('2026-08-16')->format('F j, Y') }}
            </p>
        </div>
    </section>

    <section class="bg-white py-12">
        <div class="mx-auto grid max-w-5xl gap-10 px-6 lg:grid-cols-[220px_1fr] lg:gap-14 lg:px-8">

            {{-- contents: sticky on desktop so the reader keeps their place --}}
            <nav aria-label="Policy sections" class="lg:sticky lg:top-28 lg:self-start">
                <p class="font-mono text-[10px] uppercase tracking-wide text-tide-500">Contents</p>
                <ul class="mt-3 space-y-1.5 border-l border-fog-200 pl-4 text-sm">
                    @foreach ($sections as $section)
                        <li>
                            <a href="#{{ $section['id'] }}" class="block py-0.5 text-tide-600 transition hover:text-brand-700">
                                {{ $section['title'] }}
                            </a>
                        </li>
                    @endforeach
                    <li>
                        <a href="#legal" class="block py-0.5 text-tide-600 transition hover:text-brand-700">
                            Legal &amp; Governing Law
                        </a>
                    </li>
                </ul>
            </nav>

            <div class="min-w-0">
                @foreach ($sections as $section)
                    <section id="{{ $section['id'] }}" class="scroll-mt-28 border-b border-fog-200 py-8 first:pt-0">
                        <h2 class="font-display text-xl font-medium text-ink-900">{{ $section['title'] }}</h2>
                        <ul class="mt-4 space-y-3">
                            @foreach ($section['items'] as $item)
                                <li class="flex gap-3 text-sm leading-relaxed text-tide-700">
                                    <span class="mt-2 h-1 w-1 shrink-0 rounded-full bg-brand-400"></span>
                                    <span>{{ $item }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach

                <section id="legal" class="scroll-mt-28 py-8">
                    <h2 class="font-display text-xl font-medium text-ink-900">Legal Disclaimer &amp; Governing Law</h2>
                    <div class="mt-4 space-y-4 text-sm leading-relaxed text-tide-700">
                        <p>
                            Ocean Escape Cottages is located in Nova Scotia, Canada. By booking, guests agree
                            that this agreement shall be governed by and interpreted in accordance with the
                            laws of the Province of Nova Scotia.
                        </p>
                        <p>
                            Guests acknowledge the inherent risks associated with coastal properties, including
                            ocean conditions, weather changes, uneven terrain, and wildlife. Ocean Escape
                            Cottages, its owners, and operators shall not be held liable for injury, loss, or
                            damage to persons or property arising from use of the premises, except where
                            required by law.
                        </p>
                    </div>
                </section>

                <div class="mt-4 rounded-3xl bg-fog-50 p-6 ring-1 ring-fog-200">
                    <h2 class="font-display text-lg text-ink-900">Questions before you book?</h2>
                    <p class="mt-2 text-sm leading-relaxed text-tide-700">
                        Call <a href="tel:9023981020" class="font-medium text-brand-700 hover:underline">902-398-1020</a>
                        or email <a href="mailto:info@oceanescapecottages.ca" class="font-medium text-brand-700 hover:underline">info@oceanescapecottages.ca</a>.
                    </p>
                </div>
            </div>
        </div>
    </section>
</x-website-layout>
