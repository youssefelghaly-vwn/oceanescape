/**
 * resources/js/cottage-calendar.js
 *
 * The cottage detail page: month-by-month rates calendar, guest steppers,
 * live quote, add-on selection, and the handoff to Lodgify's checkout.
 */
export default function cottageCalendar(config = {}) {
    return {
        // ---------- config ----------
        slug: config.slug,
        ratesUrl: config.ratesUrl,
        quoteUrl: config.quoteUrl,
        addonsUrl: config.addonsUrl || null,
        bookUrl: config.bookUrl || null,
        currency: config.currency || 'CAD',
        maxGuests: config.maxGuests ?? null,

        // ---------- state ----------
        cursor: startOfMonth(new Date()),
        cache: {},
        loading: false,

        arrival: config.arrival || null,
        departure: config.departure || null,
        adults: config.adults ?? 2,
        children: config.children ?? 0,
        pets: config.pets ?? 0,

        hoverDate: null,
        quote: null,
        quoteLoading: false,
        quoteError: null,
        quoteReason: null,

        rules: { pets_allowed: config.petsAllowed ?? false, max_guests: config.maxGuests ?? null },

        init() {
            if (this.arrival) {
                this.cursor = startOfMonth(new Date(this.arrival + 'T00:00:00'));
            }
            this.ensureMonths(this.cursor);
            this.fetchAddons();
            if (this.arrival && this.departure) this.fetchQuote();
        },

        // =====================================================================
        // Rates + availability
        // =====================================================================

        /**
         * Ensure BOTH visible months are loaded.
         *
         * Each month is checked independently. An earlier version bailed out when
         * the first month was cached, so paging forward never fetched the newly
         * revealed second month and everything past the initial window looked
         * fully booked.
         */
        async ensureMonths(monthDate) {
            const wanted = [monthKey(monthDate), monthKey(addMonths(monthDate, 1))];
            const missing = wanted.filter((k) => !this.cache[k]);
            if (!missing.length) return;

            missing.forEach((k) => { this.cache[k] = 'loading'; });

            const contiguous = missing.length === 2;
            const jobs = contiguous
                ? [this.fetchRange(missing[0], 2)]
                : missing.map((k) => this.fetchRange(k, 1));

            await Promise.all(jobs);

            // Anything still 'loading' returned nothing: record it as empty so
            // the spinner clears rather than hanging forever.
            wanted.forEach((k) => { if (this.cache[k] === 'loading') this.cache[k] = {}; });
        },

        async fetchRange(startMonthKey, months) {
            this.loading = true;
            try {
                const res = await fetch(`${this.ratesUrl}?start=${startMonthKey}-01&months=${months}`, {
                    headers: { Accept: 'application/json' },
                });
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                const data = await res.json();

                if (data.cottage?.currency) this.currency = data.cottage.currency;
                if (data.rules) this.rules = { ...this.rules, ...data.rules };

                const buckets = {};
                Object.entries(data.days || {}).forEach(([date, info]) => {
                    (buckets[date.slice(0, 7)] ||= {})[date] = info;
                });
                Object.entries(buckets).forEach(([mk, days]) => { this.cache[mk] = days; });
            } catch (e) {
                console.warn('[cottageCalendar] rates fetch failed', startMonthKey, e);
            } finally {
                this.loading = this.anyMonthLoading;
            }
        },

        get anyMonthLoading() {
            return Object.values(this.cache).some((v) => v === 'loading');
        },

        /** Is the month at this offset still being fetched? */
        monthLoading(offset = 0) {
            return this.cache[monthKey(addMonths(this.cursor, offset))] === 'loading';
        },

        /** Look a date up across all loaded months. */
        dayInfo(date) {
            const bucket = this.cache[date.slice(0, 7)];
            return (bucket && bucket !== 'loading') ? (bucket[date] || null) : null;
        },

        // =====================================================================
        // Navigation
        // =====================================================================

        get atFloor() { return monthKey(this.cursor) === monthKey(new Date()); },

        prevMonth() {
            const target = addMonths(this.cursor, -1);
            if (target < startOfMonth(new Date())) return;
            this.cursor = target;
            this.ensureMonths(target);
        },
        nextMonth() {
            this.cursor = addMonths(this.cursor, 1);
            this.ensureMonths(this.cursor);
        },

        monthLabel(offset = 0) {
            return addMonths(this.cursor, offset)
                .toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
        },

        // =====================================================================
        // Grid
        // =====================================================================

        grid(offset = 0) {
            const monthDate = addMonths(this.cursor, offset);
            const key = monthKey(monthDate);
            const startWeekday = new Date(monthDate.getFullYear(), monthDate.getMonth(), 1).getDay();
            const daysInMonth = new Date(monthDate.getFullYear(), monthDate.getMonth() + 1, 0).getDate();

            const cells = [];
            for (let i = 0; i < startWeekday; i++) cells.push({ blank: true, key: `b-${offset}-${i}` });
            for (let d = 1; d <= daysInMonth; d++) {
                const date = `${key}-${String(d).padStart(2, '0')}`;
                cells.push({ blank: false, key: date, date, day: d, ...this.dayMeta(date) });
            }
            return cells;
        },

        dayMeta(date) {
            const info = this.dayInfo(date);
            const isPast = date < todayStr();
            const available = !!info && !!info.available;

            const canDepart = this.pickingDeparture ? this.canBeDeparture(date) : false;

            // Free, but no valid stay can start here (min-stay unreachable).
            const blockedStart = !this.pickingDeparture && available && !isPast
                                 && !this.canCheckIn(date);

            const disabled = this.pickingDeparture
                ? !canDepart
                : (isPast || !available || blockedStart);

            return {
                isPast,
                isBooked: !!info && !info.available && !isPast,
                available,
                canDepart,
                blockedStart,
                disabled,
                price: info?.price ?? null,
                minStay: info?.min_stay ?? 1,
                season: info?.season ?? null,
                isArrival: this.arrival === date,
                isDeparture: this.departure === date,
                inRange: !!this.arrival && !!this.departure && date > this.arrival && date < this.departure,
                inHover: this.isInHover(date),
            };
        },

        /**
         * Can a stay legally BEGIN on this date?
         *
         * A night can be free yet unusable: if a cottage needs 2 nights and only
         * one is open, no valid stay starts there. Those dates are struck out
         * rather than left clickable, which would only dead-end when the guest
         * tries to pick a check-out. Unknown days count as blocking.
         */
        canCheckIn(date) {
            const info = this.dayInfo(date);
            if (!info || !info.available) return false;

            const need = info.min_stay ?? 1;
            if (need <= 1) return true;

            const cursor = new Date(date + 'T00:00:00');
            for (let n = 0; n < need; n++) {
                const day = this.dayInfo(isoOf(cursor));
                if (!day || !day.available) return false;
                cursor.setDate(cursor.getDate() + 1);
            }
            return true;
        },

        /** Are all nights in [from, to) available? */
        nightsFree(from, to) {
            const cursor = new Date(from + 'T00:00:00');
            const end = new Date(to + 'T00:00:00');
            while (cursor < end) {
                const day = this.dayInfo(isoOf(cursor));
                if (!day || !day.available) return false;
                cursor.setDate(cursor.getDate() + 1);
            }
            return true;
        },

        canBeDeparture(date) {
            if (!this.arrival || date <= this.arrival) return false;
            const nights = nightsBetween(this.arrival, date);
            const minStay = this.dayInfo(this.arrival)?.min_stay ?? 1;
            const maxStay = this.dayInfo(this.arrival)?.max_stay ?? null;
            if (nights < minStay) return false;
            if (maxStay && nights > maxStay) return false;
            return this.nightsFree(this.arrival, date);
        },

        get pickingDeparture() { return !!this.arrival && !this.departure; },

        dayTitle(cell) {
            if (cell.isPast) return 'Past date';
            if (cell.isBooked) return 'Booked';
            if (cell.blockedStart) {
                return `Minimum stay is ${cell.minStay} nights — not enough consecutive nights free from this date`;
            }
            if (this.pickingDeparture && !cell.canDepart) return 'Not available for the whole stay';
            if (cell.price !== null) return `${this.money(cell.price)} per night`;
            return '';
        },

        // =====================================================================
        // Selection
        // =====================================================================

        select(cell) {
            if (!cell || cell.blank || cell.disabled) return;
            const date = cell.date;

            if (!this.arrival || (this.arrival && this.departure)) {
                this.arrival = date;
                this.departure = null;
                this.quote = null;
                return;
            }
            if (date <= this.arrival) {
                this.arrival = date;
                this.departure = null;
                return;
            }
            this.departure = date;
            this.fetchQuote();
        },

        hover(cell) {
            this.hoverDate = (this.pickingDeparture && cell && !cell.blank) ? cell.date : null;
        },
        isInHover(date) {
            if (!this.pickingDeparture || !this.hoverDate) return false;
            return date > this.arrival && date <= this.hoverDate;
        },

        pickWindow(start, end) {
            this.arrival = start;
            this.departure = end;
            this.cursor = startOfMonth(new Date(start + 'T00:00:00'));
            this.ensureMonths(this.cursor);
            this.fetchQuote();
        },

        clear() {
            this.arrival = null;
            this.departure = null;
            this.hoverDate = null;
            this.quote = null;
            this.quoteError = null;
            this.quoteReason = null;
        },

        get nights() {
            return (this.arrival && this.departure) ? nightsBetween(this.arrival, this.departure) : 0;
        },

        get selectionHint() {
            if (!this.arrival) return 'Select your check-in date';
            if (!this.departure) {
                const min = this.dayInfo(this.arrival)?.min_stay ?? 1;
                return min > 1
                    ? `Now pick check-out — minimum ${min} nights from this date`
                    : 'Now pick your check-out date';
            }
            return `${this.nights} ${this.nights === 1 ? 'night' : 'nights'} selected`;
        },

        // =====================================================================
        // Guests
        // =====================================================================

        get guestTotal() { return this.adults + this.children; },

        get occupancyFull() {
            return !!this.maxGuests && this.guestTotal >= this.maxGuests;
        },

        canInc(field) {
            if (field === 'pets') return this.pets < 5;
            return !this.maxGuests || this.guestTotal < this.maxGuests;
        },
        incGuest(field) {
            if (this.canInc(field)) { this[field]++; this.requoteSoon(); }
        },
        decGuest(field, min = 0) {
            if (this[field] > min) { this[field]--; this.requoteSoon(); }
        },

        get guestSummary() {
            let s = `${this.adults} ${this.adults === 1 ? 'adult' : 'adults'}`;
            if (this.children) s += `, ${this.children} ${this.children === 1 ? 'child' : 'children'}`;
            if (this.pets) s += `, ${this.pets} ${this.pets === 1 ? 'pet' : 'pets'}`;
            return s;
        },

        // =====================================================================
        // Quote
        // =====================================================================

        async fetchQuote() {
            if (!this.arrival || !this.departure) return;

            this.quoteLoading = true;
            this.quoteError = null;
            this.quoteReason = null;

            try {
                const params = new URLSearchParams({
                    arrival: this.arrival,
                    departure: this.departure,
                    adults: this.adults,
                    children: this.children,
                    pets: this.pets,
                });

                // Ids only — the quote endpoint prices add-ons itself.
                const chosen = this.selectedAddonList.map((a) => a.id).join(',');
                if (chosen) params.set('addons', chosen);

                const res = await fetch(`${this.quoteUrl}?${params}`, { headers: { Accept: 'application/json' } });
                const data = await res.json();

                if (data.ok) {
                    this.quote = data;
                    if (data.currency) this.currency = data.currency;
                } else {
                    this.quote = null;
                    /*
                     * `rejected` carries Lodgify's own wording — a minimum-stay
                     * rule, for instance — and is actionable. `error` means OUR
                     * request failed and must not be blamed on the cottage.
                     */
                    this.quoteReason = data.reason || 'error';
                    this.quoteError = data.message || 'We couldn\u2019t price those dates just now.';
                }
            } catch (e) {
                console.warn('[cottageCalendar] quote failed', e);
                this.quote = null;
                this.quoteReason = 'error';
                this.quoteError = 'We couldn\u2019t price those dates just now.';
            } finally {
                this.quoteLoading = false;
            }
        },

        get quoteRejected() { return this.quoteReason === 'rejected' || this.quoteReason === 'occupancy'; },
        get quoteFailed() { return this.quoteReason === 'error'; },

        /** Per-rate-period segments, so a stay crossing a season itemises. */
        get segments() { return this.quote?.segments || []; },
        get hasMultipleRates() { return this.segments.length > 1; },
        segmentLabel(seg) {
            const from = new Date(seg.start + 'T00:00:00')
                .toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            const to = new Date(seg.end + 'T00:00:00')
                .toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            return seg.nights === 1 ? from : `${from}–${to}`;
        },

        // =====================================================================
        // Add-ons
        // =====================================================================

        addons: [],
        addonsLoading: false,
        addonState: {},
        addonsOpen: false,
        _addonsTrigger: null,
        _requoteTimer: null,

        async fetchAddons() {
            if (!this.addonsUrl) return;
            this.addonsLoading = true;
            try {
                const res = await fetch(this.addonsUrl, { headers: { Accept: 'application/json' } });
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                const data = await res.json();
                this.addons = data.addons || [];
                this.addons.forEach((a) => {
                    this.addonState[a.id] = this.defaultAddonState(a);
                    if (a.required) this.addonState[a.id].selected = true;
                });
            } catch (e) {
                console.warn('[cottageCalendar] addons fetch failed', e);
                this.addons = [];
            } finally {
                this.addonsLoading = false;
            }
        },

        /**
         * ONE quantity dimension, deliberately.
         *
         * Lodgify's checkout applies an add-on's frequency itself: sending
         * `155689-3` for a PerNight add-on on a 2-night stay was observed
         * billing 3 × 2 nights. A nights stepper here would be double counted at
         * checkout. Their own UI matches this — a flat per-stay extra gets a
         * plain Add button, a per-quantity extra gets a single stepper.
         */
        defaultAddonState(addon) {
            return {
                selected: false,
                quantity: addon.per_guest
                    ? Math.min(Math.max(1, this.guestTotal), addon.max_quantity || 10)
                    : 1,
                touched: false,
            };
        },

        state(addon) {
            if (!this.addonState[addon.id]) {
                this.addonState[addon.id] = this.defaultAddonState(addon);
            }
            return this.addonState[addon.id];
        },

        hasStepper(addon) { return !!addon.per_guest; },
        appliesPerNight(addon) { return !!addon.per_night; },
        isSelected(addon) { return !!this.state(addon).selected; },
        addonMax(addon) { return addon.max_quantity || 10; },

        toggleAddon(addon) {
            if (addon.required) return;
            const st = this.state(addon);
            st.selected = !st.selected;
            if (st.selected && !st.touched && addon.per_guest) {
                st.quantity = Math.min(Math.max(1, this.guestTotal), this.addonMax(addon));
            }
            this.requoteSoon();
        },
        incAddon(addon) {
            const st = this.state(addon);
            if (st.quantity < this.addonMax(addon)) {
                st.quantity++; st.touched = true; this.requoteSoon();
            }
        },
        decAddon(addon) {
            const st = this.state(addon);
            if (st.quantity > 1) { st.quantity--; st.touched = true; this.requoteSoon(); }
        },
        canIncAddon(addon) { return this.state(addon).quantity < this.addonMax(addon); },

        /**
         * Display cost. Nights are applied HERE for the guest's benefit, but the
         * quantity SENT to Lodgify never includes them.
         */
        addonCost(addon) {
            const st = this.state(addon);
            if (!st.selected) return 0;
            let cost = addon.price * Math.max(1, st.quantity);
            if (addon.per_night) cost *= Math.max(1, this.nights);
            return round2(cost);
        },

        addonUnitLabel(addon) {
            if (addon.per_guest && addon.per_night) return 'per quantity, per night';
            if (addon.per_night) return 'per night';
            if (addon.per_guest) return 'per quantity';
            return 'per stay';
        },

        addonQtyLabel(addon) {
            const st = this.state(addon);
            const parts = [];
            if (st.quantity > 1) parts.push(`× ${st.quantity}`);
            if (addon.per_night) parts.push(`× ${this.nights} ${this.nights === 1 ? 'night' : 'nights'}`);
            return parts.join(' ');
        },

        get selectedAddonList() { return this.addons.filter((a) => this.isSelected(a)); },
        get selectedAddonCount() { return this.selectedAddonList.length; },
        get hasAddons() { return this.addons.length > 0; },
        get addonsTotal() {
            return round2(this.addons.reduce((sum, a) => sum + this.addonCost(a), 0));
        },
        get grandTotal() {
            if (!this.quote) return null;
            return round2((this.quote.total || 0) + this.addonsTotal);
        },
        get addonsSummary() {
            const n = this.selectedAddonCount;
            return n ? `${n} extra${n > 1 ? 's' : ''} added` : 'Add extras to your stay';
        },
        clearAddons() {
            this.addons.forEach((a) => { if (!a.required) this.state(a).selected = false; });
            this.requoteSoon();
        },

        /** "155688-1,155689-3" — the format Lodgify's checkout expects. */
        get addonParam() {
            return this.selectedAddonList
                .map((a) => `${a.id}-${Math.max(1, this.state(a).quantity)}`)
                .join(',');
        },

        /** Debounced: several stepper taps produce one request, not one each. */
        requoteSoon() {
            clearTimeout(this._requoteTimer);
            this._requoteTimer = setTimeout(() => {
                if (this.arrival && this.departure) this.fetchQuote();
            }, 350);
        },

        // ---------- add-ons modal ----------

        openAddons(trigger = null) {
            this._addonsTrigger = trigger || document.activeElement;
            this.addonsOpen = true;
            this.lockBodyScroll();
            this.$nextTick(() => this.$refs.addonsDialog?.focus());
        },
        closeAddons() {
            this.addonsOpen = false;
            this.unlockBodyScroll();
            const t = this._addonsTrigger;
            this._addonsTrigger = null;
            if (t?.focus) this.$nextTick(() => t.focus());
        },
        onAddonsKey(event) {
            if (this.addonsOpen && event.key === 'Escape') {
                event.preventDefault();
                this.closeAddons();
            }
        },
        lockBodyScroll() {
            const shift = window.innerWidth - document.documentElement.clientWidth;
            document.body.style.overflow = 'hidden';
            if (shift > 0) document.body.style.paddingRight = `${shift}px`;
        },
        unlockBodyScroll() {
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        },

        // =====================================================================
        // Booking handoff
        // =====================================================================

        book() {
            if (!this.arrival || !this.departure || !this.bookUrl) return;

            const params = new URLSearchParams({
                arrival: this.arrival,
                departure: this.departure,
                adults: this.adults,
                children: this.children,
                pets: this.pets,
            });

            if (this.addonParam) params.set('addons', this.addonParam);

            // Pass what we displayed, so the intent record can later be compared
            // against whatever Lodgify actually charges.
            if (this.grandTotal != null) params.set('total', this.grandTotal);

            /*
             * Our own route, not Lodgify's directly: it re-checks availability,
             * records the intent for attribution, then 302s to the hosted
             * checkout. Building the Lodgify URL server-side also keeps the
             * account slug out of the page source.
             */
            window.location.href = `${this.bookUrl}?${params.toString()}`;
        },

        // =====================================================================
        // Formatting
        // =====================================================================

        money(value) {
            if (value === null || value === undefined) return '—';
            const symbol = this.currency === 'CAD' ? 'CA$' : (this.currency === 'USD' ? '$' : '');
            const formatted = Number(value).toLocaleString('en-CA', {
                minimumFractionDigits: 2, maximumFractionDigits: 2,
            });
            return symbol ? `${symbol}${formatted}` : `${this.currency} ${formatted}`;
        },

        priceLabel(value) {
            if (value === null || value === undefined) return '';
            const symbol = this.currency === 'CAD' ? 'CA$' : (this.currency === 'USD' ? '$' : '');
            return `${symbol}${Math.round(value)}`;
        },

        fmt(date) {
            if (!date) return null;
            const [y, m, d] = date.split('-').map(Number);
            return new Date(y, m - 1, d).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        },
    };
}

// ---------------------------------------------------------------- helpers

function startOfMonth(d) { return new Date(d.getFullYear(), d.getMonth(), 1); }
function addMonths(d, n) { return new Date(d.getFullYear(), d.getMonth() + n, 1); }
function monthKey(d) { return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`; }
function isoOf(d) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
function todayStr() { return isoOf(new Date()); }
function nightsBetween(from, to) {
    return Math.round((new Date(to + 'T00:00:00') - new Date(from + 'T00:00:00')) / 86400000);
}
function round2(n) { return Math.round(n * 100) / 100; }