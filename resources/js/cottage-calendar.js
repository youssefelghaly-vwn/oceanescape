/**
 * Alpine component: per-cottage calendar with prices AND rule enforcement.
 *
 * Every constraint comes from Lodgify via /api/cottage/{slug}/rates:
 *   - day.price      nightly rate for that date
 *   - day.min_stay   minimum nights when checking IN on that date
 *   - day.max_stay   maximum nights
 *   - day.available  bookable or not
 *   - rules.max_guests  occupancy cap
 *
 * Nothing here is hardcoded. If a rule changes in the Lodgify dashboard, the
 * UI follows on the next cache expiry.
 *
 * Enforcement happens BEFORE selection, not after: while picking a check-out
 * date, any date that would violate min stay, exceed max stay, or span a booked
 * night is disabled — so a guest can never build an invalid stay and then be
 * told "not bookable".
 */
export default function cottageCalendar(config = {}) {
    return {
        slug: config.slug,
        ratesUrl: config.ratesUrl,
        quoteUrl: config.quoteUrl,

        arrival: config.arrival ?? null,
        departure: config.departure ?? null,
        adults: config.adults ?? 2,
        children: config.children ?? 0,
        pets: config.pets ?? 0,

        cursor: startOfMonth(new Date()),
        cache: {},
        loading: false,
        currency: config.currency || 'USD',

        // populated from the API; the fallbacks only apply before first load
        rules: {
            max_guests: config.maxGuests ?? null,
            pets_allowed: config.petsAllowed ?? true,
            check_in_hour: null,
            check_out_hour: null,
        },

        quote: null,
        quoteLoading: false,
        quoteError: null,
        quoteReason: null,
        hoverDate: null,
        addonsUrl: config.addonsUrl || null,

        init() {
            this.ensureMonths(this.cursor);
            this.fetchAddons();
            if (this.arrival && this.departure) this.fetchQuote();
        },

        // ---------- data ----------

        /**
         * Ensure BOTH visible months are loaded.
         *
         * The old version bailed out if the first month was already cached,
         * which meant paging forward never fetched the newly-revealed second
         * month — everything past the initial window looked fully booked.
         * Each month is now checked and requested independently.
         */
        async ensureMonths(monthDate) {
            const wanted = [monthKey(monthDate), monthKey(addMonths(monthDate, 1))];
            const missing = wanted.filter((k) => !this.cache[k]);
            if (!missing.length) return;

            // Mark every missing month as loading so the UI can show it per-month.
            missing.forEach((k) => { this.cache[k] = 'loading'; });

            // One request covers a contiguous pair; otherwise fetch separately.
            const contiguous = missing.length === 2 && missing[1] === monthKey(addMonths(monthDate, 1));
            const jobs = contiguous
                ? [this.fetchRange(missing[0], 2)]
                : missing.map((k) => this.fetchRange(k, 1));

            await Promise.all(jobs);

            // Anything still marked loading got no data — record it as empty so
            // the spinner clears instead of hanging forever.
            wanted.forEach((k) => { if (this.cache[k] === 'loading') this.cache[k] = {}; });
        },

        async fetchRange(startMonthKey, months) {
            this.loading = true;
            try {
                const res = await fetch(
                    `${this.ratesUrl}?start=${startMonthKey}-01&months=${months}`,
                    { headers: { Accept: 'application/json' } }
                );
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

        /** Back-compat alias — some callers still use the old name. */
        fetchMonths(monthDate) {
            return this.ensureMonths(monthDate);
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
            const bucket = this.cache[date?.slice(0, 7)];
            if (!bucket || bucket === 'loading') return null;
            return bucket[date] ?? null;
        },

        async fetchQuote() {
            if (!this.arrival || !this.departure) { this.quote = null; return; }
            this.quoteLoading = true;
            this.quoteError = null;
            try {
                const params = new URLSearchParams({
                    arrival: this.arrival,
                    departure: this.departure,
                    adults: this.adults,
                    children: this.children,
                    pets: this.pets,
                });
                // Lodgify prices add-ons inside the quote, so send the selection.
                const chosen = this.selectedAddonList
                    .map((a) => {
                        const st = this.state(a);
                        const units = (a.per_guest ? st.persons : 1) * (a.per_night ? st.nights : 1);
                        return `${a.id}:${units}`;
                    })
                    .join(',');
                if (chosen) params.set('addons', chosen);

                const res = await fetch(`${this.quoteUrl}?${params}`, { headers: { Accept: 'application/json' } });
                const data = await res.json();

                if (data.ok) {
                    this.quote = data;
                    this.quoteReason = null;
                    if (data.currency) this.currency = data.currency;
                } else {
                    this.quote = null;
                    // `rejected` carries Lodgify's own wording (e.g. a minimum
                    // stay rule) and is genuinely actionable; `error` means our
                    // request failed and must not be blamed on the cottage.
                    this.quoteReason = data.reason || 'error';
                    this.quoteError = data.message
                        || 'We couldn\u2019t price those dates just now.';
                }
            } catch (e) {
                this.quote = null;
                this.quoteError = 'Couldn\u2019t reach the pricing service.';
            } finally {
                this.quoteLoading = false;
            }
        },

        // ---------- rules ----------

        /** Min nights required when checking in on `date`. */
        minStayFor(date) {
            return this.dayInfo(date)?.min_stay ?? 1;
        },
        maxStayFor(date) {
            return this.dayInfo(date)?.max_stay ?? null;
        },

        /**
         * Can a stay legally BEGIN on this date?
         *
         * A night can be free yet unusable: if a cottage needs 2 nights and only
         * one night is open, no valid stay starts there. Those dates are shown
         * struck through with an explanation rather than being left clickable,
         * which would only produce a dead end when the guest tries to pick a
         * check-out.
         *
         * Unknown (not-yet-loaded) days are treated as blocking, so we never
         * invite a selection we cannot validate.
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
        rangeIsFree(from, to) {
            const cursor = new Date(from + 'T00:00:00');
            const end = new Date(to + 'T00:00:00');
            while (cursor < end) {
                const info = this.dayInfo(isoOf(cursor));
                if (!info || !info.available) return false;
                cursor.setDate(cursor.getDate() + 1);
            }
            return true;
        },

        /**
         * Can `date` be the check-out for the current arrival?
         * Checks: after arrival, min stay, max stay, and no booked night between.
         */
        canBeDeparture(date) {
            if (!this.arrival || date <= this.arrival) return false;
            const nights = nightsBetween(this.arrival, date);
            const min = this.minStayFor(this.arrival);
            const max = this.maxStayFor(this.arrival);
            if (nights < min) return false;
            if (max && nights > max) return false;
            return this.rangeIsFree(this.arrival, date);
        },

        /** True while we are waiting for the guest to choose a check-out. */
        get pickingDeparture() {
            return !!this.arrival && !this.departure;
        },

        get maxGuests() {
            return this.rules.max_guests || null;
        },
        get guestTotal() {
            return this.adults + this.children;
        },
        get guestsRemaining() {
            return this.maxGuests ? Math.max(0, this.maxGuests - this.guestTotal) : null;
        },
        get occupancyFull() {
            return this.maxGuests ? this.guestTotal >= this.maxGuests : false;
        },
        /** Stepper caps, derived from Lodgify occupancy — never hardcoded. */
        maxFor(field) {
            if (field === 'pets') return this.rules.pets_allowed ? 3 : 0;
            if (!this.maxGuests) return 20;
            // this field can grow into whatever headroom is left
            const others = field === 'adults' ? this.children : this.adults;
            return Math.max(field === 'adults' ? 1 : 0, this.maxGuests - others);
        },

        /** Human-readable reason a day can't be picked, for the tooltip. */
        dayTitle(cell) {
            if (cell.isPast) return 'Past date';
            if (cell.isBooked) return 'Booked';
            if (cell.blockedStart) {
                return `Minimum stay is ${cell.minStay} nights — not enough consecutive nights free from this date`;
            }
            if (this.pickingDeparture && !cell.canDepart && cell.date > this.arrival) {
                const min = this.minStayFor(this.arrival);
                const nights = nightsBetween(this.arrival, cell.date);
                if (nights < min) return `Minimum stay is ${min} night${min > 1 ? 's' : ''}`;
                const max = this.maxStayFor(this.arrival);
                if (max && nights > max) return `Maximum stay is ${max} nights`;
                return 'Not available for the whole stay';
            }
            const parts = [];
            if (cell.season) parts.push(cell.season);
            if (cell.minStay > 1) parts.push(`min ${cell.minStay} nights`);
            return parts.join(' · ');
        },

        // ---------- navigation ----------
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

        // ---------- grid ----------
        monthLabel(offset = 0) {
            return addMonths(this.cursor, offset)
                .toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
        },
        grid(offset = 0) {
            const monthDate = addMonths(this.cursor, offset);
            const key = monthKey(monthDate);
            const days = this.cache[key];
            const startWeekday = new Date(monthDate.getFullYear(), monthDate.getMonth(), 1).getDay();
            const daysInMonth = new Date(monthDate.getFullYear(), monthDate.getMonth() + 1, 0).getDate();

            const cells = [];
            for (let i = 0; i < startWeekday; i++) cells.push({ blank: true, key: `b-${offset}-${i}` });
            for (let d = 1; d <= daysInMonth; d++) {
                const date = `${key}-${String(d).padStart(2, '0')}`;
                cells.push({ blank: false, key: date, date, day: d, ...this.dayMeta(date, days) });
            }
            return cells;
        },
        dayMeta(date, days) {
            const isPast = date < todayStr();
            const info = (days && days !== 'loading') ? days[date] : null;
            const available = !!info && info.available;

            // While choosing check-out, only valid check-out dates stay enabled.
            const canDepart = this.pickingDeparture ? this.canBeDeparture(date) : false;

            // Free, but no valid stay can start here (min-stay can't be met).
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
                price: info?.price ?? null,
                minStay: info?.min_stay ?? 1,
                maxStay: info?.max_stay ?? null,
                season: info?.season ?? null,
                isDefaultRate: info?.is_default ?? false,
                disabled,
                isArrival: this.arrival === date,
                isDeparture: this.departure === date,
                inRange: !!this.arrival && !!this.departure && date > this.arrival && date < this.departure,
                inHover: this.pickingDeparture && !!this.hoverDate
                         && date > this.arrival && date <= this.hoverDate,
            };
        },

        priceLabel(price) {
            if (price === null || price === undefined) return '';
            const n = Number(price);
            if (!isFinite(n)) return '';
            const sym = symbolFor(this.currency);
            return n >= 1000 ? sym + (Math.round(n / 100) / 10) + 'k' : sym + Math.round(n);
        },

        // ---------- add-ons ----------

        addons: [],
        addonsLoading: false,
        addonsOpen: false,
        _addonsTrigger: null,

        /**
         * Per-add-on state, keyed by id:
         *   { selected: bool, persons: int, nights: int }
         *
         * Two independent quantity dimensions, because Lodgify's pricing has
         * two (see addonChargeScaling on the server). Which steppers appear
         * depends on which dimensions actually apply:
         *
         *   flat            no stepper — just add or remove
         *   per night       one stepper: nights
         *   per guest       one stepper: guests
         *   per guest+night two steppers: guests and nights
         */
        addonState: {},

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

        /** Sensible starting quantities: the whole party, for the whole stay. */
        defaultAddonState(addon) {
            return {
                selected: false,
                persons: Math.min(Math.max(1, this.guestTotal), addon.max_quantity || 10),
                nights: Math.min(Math.max(1, this.nights || 1), addon.max_quantity || 10),
                // once a guest edits a stepper we stop tracking their party/stay
                touchedPersons: false,
                touchedNights: false,
            };
        },

        state(addon) {
            if (!this.addonState[addon.id]) {
                this.addonState[addon.id] = this.defaultAddonState(addon);
            }
            return this.addonState[addon.id];
        },

        // which controls this add-on needs
        needsPersons(addon) { return !!addon.per_guest; },
        needsNights(addon)  { return !!addon.per_night; },
        hasSteppers(addon)  { return this.needsPersons(addon) || this.needsNights(addon); },

        isSelected(addon) { return !!this.state(addon).selected; },

        toggleAddon(addon) {
            if (addon.required) return;
            const st = this.state(addon);
            st.selected = !st.selected;
            if (st.selected) {
                // re-sync to the current party/stay unless the guest set them
                if (!st.touchedPersons) st.persons = Math.min(Math.max(1, this.guestTotal), addon.max_quantity || 10);
                if (!st.touchedNights)  st.nights  = Math.min(Math.max(1, this.nights || 1), addon.max_quantity || 10);
            }
            this.requoteSoon();
        },

        addonMax(addon) { return addon.max_quantity || 10; },

        incPersons(addon) {
            const st = this.state(addon);
            if (st.persons < this.addonMax(addon)) {
                st.persons++; st.touchedPersons = true; this.requoteSoon();
            }
        },
        decPersons(addon) {
            const st = this.state(addon);
            if (st.persons > 1) { st.persons--; st.touchedPersons = true; this.requoteSoon(); }
        },
        incNights(addon) {
            const st = this.state(addon);
            // never more nights than the stay itself
            const ceiling = Math.min(this.addonMax(addon), Math.max(1, this.nights || 1));
            if (st.nights < ceiling) { st.nights++; st.touchedNights = true; this.requoteSoon(); }
        },
        decNights(addon) {
            const st = this.state(addon);
            if (st.nights > 1) { st.nights--; st.touchedNights = true; this.requoteSoon(); }
        },
        canIncNights(addon) {
            const ceiling = Math.min(this.addonMax(addon), Math.max(1, this.nights || 1));
            return this.state(addon).nights < ceiling;
        },
        canIncPersons(addon) {
            return this.state(addon).persons < this.addonMax(addon);
        },

        /**
         * price x guests x nights, each factor applied only when that dimension
         * is part of the add-on's pricing.
         */
        addonCost(addon) {
            const st = this.state(addon);
            if (!st.selected) return 0;
            let cost = addon.price;
            if (addon.per_guest) cost *= Math.max(1, st.persons);
            if (addon.per_night) cost *= Math.max(1, st.nights);
            return round2(cost);
        },

        /** "$30 / guest / night" — how the price is quoted. */
        addonUnitLabel(addon) {
            if (addon.per_guest && addon.per_night) return '/ guest / night';
            if (addon.per_night) return '/ night';
            if (addon.per_guest) return '/ guest';
            return '/ stay';
        },

        /** "2 guests × 6 nights" — how this guest's selection multiplies out. */
        addonQtyLabel(addon) {
            const st = this.state(addon);
            const parts = [];
            if (addon.per_guest) parts.push(`${st.persons} guest${st.persons > 1 ? 's' : ''}`);
            if (addon.per_night) parts.push(`${st.nights} night${st.nights > 1 ? 's' : ''}`);
            return parts.join(' × ');
        },

        get selectedAddonList() {
            return this.addons.filter((a) => this.isSelected(a));
        },
        get addonsTotal() {
            return round2(this.addons.reduce((sum, a) => sum + this.addonCost(a), 0));
        },
        get hasAddons() {
            return this.addons.length > 0;
        },
        get grandTotal() {
            if (!this.quote) return null;
            return round2((this.quote.total || 0) + this.addonsTotal);
        },

        // ---- modal ----

        /**
         * Add-ons live in a dialog rather than inline: with four extras the
         * booking panel grew taller than the viewport and pushed the Book
         * button off-screen with no way to scroll to it.
         */
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
            if (t && typeof t.focus === 'function') this.$nextTick(() => t.focus());
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

        /** "2 extras added" / "Add extras" for the trigger button. */
        get addonsSummary() {
            const n = this.selectedAddonList.length;
            if (!n) return 'Add extras to your stay';
            return `${n} extra${n > 1 ? 's' : ''} added`;
        },
        get selectedAddonCount() {
            return this.selectedAddonList.length;
        },
        clearAddons() {
            this.addons.forEach((a) => {
                if (!a.required) this.state(a).selected = false;
            });
            this.requoteSoon();
        },

        /** Debounced: several stepper taps produce one request, not one each. */
        requoteSoon() {
            clearTimeout(this._requoteTimer);
            this._requoteTimer = setTimeout(() => this.fetchQuote(), 350);
        },

        // ---------- selection ----------        // ---------- selection ----------
        select(cell) {
            if (!cell || cell.blank || cell.disabled) return;
            const date = cell.date;

            // starting fresh, or restarting after a complete range
            if (!this.arrival || this.departure) {
                this.arrival = date;
                this.departure = null;
                this.quote = null;
                this.quoteError = null;
                return;
            }
            if (date <= this.arrival) {
                this.arrival = date;
                this.departure = null;
                this.quote = null;
                return;
            }
            if (!this.canBeDeparture(date)) return;   // belt and braces
            this.departure = date;
            this.hoverDate = null;
            this.fetchQuote();
        },
        hover(cell) {
            if (!this.pickingDeparture || cell.blank) return;
            this.hoverDate = cell.canDepart ? cell.date : null;
        },
        clear() {
            this.arrival = null;
            this.departure = null;
            this.hoverDate = null;
            this.quote = null;
            this.quoteError = null;
            this.quoteReason = null;
        },

        /** A rule we can act on, versus a failure we should apologise for. */
        get quoteRejected() { return this.quoteReason === 'rejected' || this.quoteReason === 'occupancy'; },
        get quoteFailed()   { return this.quoteReason === 'error'; },
        pickWindow(start, end) {
            this.arrival = start;
            this.departure = null;
            this.cursor = startOfMonth(new Date(start + 'T00:00:00'));
            this.fetchMonths(this.cursor).then(() => {
                // respect min stay even when the chip suggests a shorter window
                const min = this.minStayFor(start);
                const proposed = nightsBetween(start, end) >= min
                    ? end
                    : isoOf(addDays(new Date(start + 'T00:00:00'), min));
                if (this.canBeDeparture(proposed)) {
                    this.departure = proposed;
                    this.fetchQuote();
                }
            });
        },

        // ---------- breakdown ----------

        /**
         * Per-rate-period lines. Prefers the server's segments (computed from
         * the same rate calendar Lodgify bills from); falls back to computing
         * locally so the breakdown still itemises if the quote call fails.
         */
        get segments() {
            if (this.quote?.segments?.length) return this.quote.segments;
            if (!this.arrival || !this.departure) return [];

            const out = [];
            const cursor = new Date(this.arrival + 'T00:00:00');
            const end = new Date(this.departure + 'T00:00:00');
            while (cursor < end) {
                const date = isoOf(cursor);
                const info = this.dayInfo(date);
                const price = info?.price ?? null;
                const season = info?.season ?? null;
                const last = out[out.length - 1];
                if (last && last.price === price && last.season === season) {
                    last.nights++;
                    last.end = date;
                    last.subtotal = price === null ? null : round2(price * last.nights);
                } else {
                    out.push({ price, season, start: date, end: date, nights: 1, subtotal: price });
                }
                cursor.setDate(cursor.getDate() + 1);
            }
            return out;
        },
        get segmentsTotal() {
            return this.segments.reduce((sum, s) => sum + (s.subtotal ?? 0), 0);
        },
        /** True when the itemised nights are more than one rate — worth showing. */
        get hasMultipleRates() {
            return this.segments.length > 1;
        },
        segmentLabel(s) {
            const a = new Date(s.start + 'T00:00:00');
            const b = new Date(s.end + 'T00:00:00');
            const fmtOpts = { month: 'short', day: 'numeric' };
            if (s.nights === 1) return a.toLocaleDateString('en-US', fmtOpts);
            return a.toLocaleDateString('en-US', fmtOpts) + ' – ' + b.toLocaleDateString('en-US', fmtOpts);
        },

        // ---------- display ----------
        fmt(date) {
            if (!date) return null;
            const [y, m, d] = date.split('-').map(Number);
            return new Date(y, m - 1, d).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        },
        money(v) {
            if (v === null || v === undefined || !isFinite(Number(v))) return '—';
            return symbolFor(this.currency) + Number(v).toLocaleString(undefined, {
                minimumFractionDigits: 2, maximumFractionDigits: 2,
            });
        },
        get nights() {
            if (!this.arrival || !this.departure) return 0;
            return nightsBetween(this.arrival, this.departure);
        },
        get canBook() { return !!this.quote; },
        get guestSummary() {
            let s = `${this.adults} adult${this.adults > 1 ? 's' : ''}`;
            if (this.children) s += `, ${this.children} child${this.children > 1 ? 'ren' : ''}`;
            if (this.pets) s += `, ${this.pets} pet${this.pets > 1 ? 's' : ''}`;
            return s;
        },
        /** Hint shown under the calendar telling the guest what to do next. */
        get selectionHint() {
            if (!this.arrival) return 'Select your check-in date';
            if (!this.departure) {
                const min = this.minStayFor(this.arrival);
                return min > 1
                    ? `Now pick check-out — minimum ${min} nights from this date`
                    : 'Now pick your check-out date';
            }
            return `${this.nights} night${this.nights > 1 ? 's' : ''} selected`;
        },

        incGuest(field) {
            const max = this.maxFor(field);
            if (this[field] < max) { this[field]++; this.fetchQuote(); }
        },
        decGuest(field, min = 0) {
            if (this[field] > min) { this[field]--; this.fetchQuote(); }
        },
        canInc(field) { return this[field] < this.maxFor(field); },

        book() {
            if (!this.arrival || !this.departure) return;
            const params = new URLSearchParams({
                arrival: this.arrival,
                departure: this.departure,
                adults: this.adults,
                children: this.children,
                pets: this.pets,
            });
            const chosen = this.selectedAddonList
                .map((a) => {
                    const st = this.state(a);
                    const units = (a.per_guest ? st.persons : 1) * (a.per_night ? st.nights : 1);
                    return `${a.id}:${units}`;
                })
                .join(',');
            if (chosen) params.set('addons', chosen);

            window.location.href = `${window.location.pathname}?${params}#book`;
        },
    };
}

function startOfMonth(d) { return new Date(d.getFullYear(), d.getMonth(), 1); }
function addMonths(d, n) { return new Date(d.getFullYear(), d.getMonth() + n, 1); }
function addDays(d, n) { const c = new Date(d); c.setDate(c.getDate() + n); return c; }
function monthKey(d) { return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`; }
function isoOf(d) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
function todayStr() { return isoOf(new Date()); }
function nightsBetween(a, b) {
    return Math.round((new Date(b + 'T00:00:00') - new Date(a + 'T00:00:00')) / 86400000);
}
function round2(n) { return Math.round(n * 100) / 100; }
function symbolFor(c) {
    return { CAD: 'CA$', USD: '$', GBP: '£', EUR: '€', AUD: 'AU$', EGP: 'E£' }[c] || (c ? c + ' ' : '$');
}