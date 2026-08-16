/**
 * resources/js/components/booking-search.js
 *
 * The site-wide date/guest search widget.
 *
 * NOTE: this now accepts a config object. The previous version took no
 * arguments and hardcoded `arrival: null`, so re-rendering the widget on the
 * results page always showed "Add date" even though a search was active.
 */
export default function bookingSearch(config = {}) {
    return {
        // ---- state ----
        open: false,
        guestsOpen: false,
        activeField: 'arrival',

        arrival: config.arrival ?? null,
        departure: config.departure ?? null,
        adults: config.adults ?? 2,
        children: config.children ?? 0,
        pets: config.pets ?? 0,

        loading: false,
        cache: {},
        cursor: startOfMonth(new Date()),

        searchUrl: config.searchUrl || '/availability',
        availabilityUrl: config.availabilityUrl || '/api/availability/month',

        init() {
            // Open on the month being searched, not always today.
            if (this.arrival) {
                this.cursor = startOfMonth(new Date(this.arrival + 'T00:00:00'));
            }
            this.ensureMonths(this.cursor);
        },

        // ---- data ----

        /**
         * Load BOTH visible months, each checked independently — bailing out
         * when only the first is cached leaves the second month blank.
         */
        async ensureMonths(monthDate) {
            const wanted = [monthKey(monthDate), monthKey(addMonths(monthDate, 1))];
            const missing = wanted.filter((k) => !this.cache[k]);
            if (!missing.length) return;

            missing.forEach((k) => { this.cache[k] = 'loading'; });
            this.loading = true;

            try {
                await Promise.all(missing.map(async (key) => {
                    const res = await fetch(`${this.availabilityUrl}?start=${key}-01`, {
                        headers: { Accept: 'application/json' },
                    });
                    if (!res.ok) throw new Error(`HTTP ${res.status}`);
                    const data = await res.json();

                    const buckets = {};
                    Object.entries(data.days || {}).forEach(([date, info]) => {
                        (buckets[date.slice(0, 7)] ||= {})[date] = info;
                    });
                    Object.entries(buckets).forEach(([mk, days]) => { this.cache[mk] = days; });
                }));
            } catch (e) {
                console.warn('[bookingSearch] availability fetch failed', e);
            } finally {
                wanted.forEach((k) => { if (this.cache[k] === 'loading') this.cache[k] = {}; });
                this.loading = false;
            }
        },

        /** Back-compat alias. */
        fetchMonth(monthDate) { return this.ensureMonths(monthDate); },

        monthLoading(offset = 0) {
            return this.cache[monthKey(addMonths(this.cursor, offset))] === 'loading';
        },

        // ---- navigation ----
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

        // ---- grid ----
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

            const isBooked = !!info && info.is_booked;
            const isLimited = !!info && info.is_limited;
            const disabled = isPast || isBooked || (!info && !isPast);

            return {
                isPast, isBooked, isLimited, disabled,
                isArrival: this.arrival === date,
                isDeparture: this.departure === date,
                inRange: !!this.arrival && !!this.departure && date > this.arrival && date < this.departure,
            };
        },

        // ---- selection ----
        select(cell) {
            if (!cell || cell.blank || cell.disabled) return;
            const date = cell.date;

            if (!this.arrival || (this.arrival && this.departure)) {
                this.arrival = date;
                this.departure = null;
                this.activeField = 'departure';
                return;
            }
            if (date <= this.arrival) {
                this.arrival = date;
                this.departure = null;
                return;
            }
            this.departure = date;
            this.activeField = 'arrival';
            setTimeout(() => { this.open = false; }, 250);
        },
        clear() {
            this.arrival = null;
            this.departure = null;
        },

        // ---- display ----
        fmt(date) {
            if (!date) return null;
            const [y, m, d] = date.split('-').map(Number);
            return new Date(y, m - 1, d).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        },
        get nights() {
            if (!this.arrival || !this.departure) return 0;
            return Math.round(
                (new Date(this.departure) - new Date(this.arrival)) / 86400000
            );
        },
        get canSearch() { return !!this.arrival && !!this.departure; },

        // ---- guests ----
        incGuest(field, max) { if (this[field] < max) this[field]++; },
        decGuest(field, min = 0) { if (this[field] > min) this[field]--; },
        get guestSummary() {
            let s = `${this.adults} Adult${this.adults > 1 ? 's' : ''}`;
            if (this.children) s += `, ${this.children} Child${this.children > 1 ? 'ren' : ''}`;
            if (this.pets) s += `, ${this.pets} Pet${this.pets > 1 ? 's' : ''}`;
            return s;
        },

        // ---- submit ----
        search() {
            const params = new URLSearchParams({
                adults: this.adults,
                children: this.children,
                pets: this.pets,
            });
            // Dates are optional: without them the results page lists every cottage.
            if (this.arrival)   params.set('arrival', this.arrival);
            if (this.departure) params.set('departure', this.departure);

            window.location.href = `${this.searchUrl}?${params.toString()}`;
        },
    };
}

function startOfMonth(d) { return new Date(d.getFullYear(), d.getMonth(), 1); }
function addMonths(d, n) { return new Date(d.getFullYear(), d.getMonth() + n, 1); }
function monthKey(d) { return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`; }
function todayStr() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}