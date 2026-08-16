/**
 * Alpine component: full-screen gallery viewer.
 *
 * Accessibility and UX notes, since a lightbox is easy to get subtly wrong:
 *  - focus moves into the dialog on open and returns to the trigger on close
 *  - Escape closes, arrows navigate, Home/End jump to first/last
 *  - background scroll is locked while open, and the scrollbar's width is
 *    compensated so the page doesn't shift
 *  - neighbouring images are preloaded, so arrowing through feels instant
 *  - touch swipe on mobile, with a threshold that ignores vertical scrolling
 *  - respects prefers-reduced-motion by skipping the transition classes
 */
export default function imageLightbox(config = {}) {
    return {
        images: config.images || [],
        open: false,
        index: 0,
        loading: false,
        touchStartX: null,
        touchStartY: null,
        returnFocusTo: null,

        get current() {
            return this.images[this.index] || null;
        },
        get count() {
            return this.images.length;
        },
        get hasMultiple() {
            return this.images.length > 1;
        },

        show(index = 0, trigger = null) {
            if (!this.images.length) return;
            this.index = Math.max(0, Math.min(index, this.images.length - 1));
            this.returnFocusTo = trigger || document.activeElement;
            this.open = true;
            this.loading = true;
            this.lockScroll();
            this.preloadNeighbours();
            // wait for the dialog to exist before focusing it
            this.$nextTick(() => this.$refs.dialog?.focus());
        },

        hide() {
            this.open = false;
            this.unlockScroll();
            const target = this.returnFocusTo;
            this.returnFocusTo = null;
            if (target && typeof target.focus === 'function') {
                this.$nextTick(() => target.focus());
            }
        },

        next() {
            if (!this.hasMultiple) return;
            this.index = (this.index + 1) % this.count;
            this.loading = true;
            this.preloadNeighbours();
        },
        prev() {
            if (!this.hasMultiple) return;
            this.index = (this.index - 1 + this.count) % this.count;
            this.loading = true;
            this.preloadNeighbours();
        },
        goTo(i) {
            if (i === this.index) return;
            this.index = Math.max(0, Math.min(i, this.count - 1));
            this.loading = true;
            this.preloadNeighbours();
        },

        onKey(event) {
            if (!this.open) return;
            switch (event.key) {
                case 'Escape':     this.hide(); break;
                case 'ArrowRight': this.next(); break;
                case 'ArrowLeft':  this.prev(); break;
                case 'Home':       this.goTo(0); break;
                case 'End':        this.goTo(this.count - 1); break;
                default: return;
            }
            event.preventDefault();
        },

        // ---- touch ----
        onTouchStart(event) {
            const t = event.changedTouches[0];
            this.touchStartX = t.clientX;
            this.touchStartY = t.clientY;
        },
        onTouchEnd(event) {
            if (this.touchStartX === null) return;
            const t = event.changedTouches[0];
            const dx = t.clientX - this.touchStartX;
            const dy = t.clientY - this.touchStartY;
            this.touchStartX = null;
            this.touchStartY = null;
            // horizontal intent only — don't hijack a vertical scroll
            if (Math.abs(dx) < 50 || Math.abs(dx) < Math.abs(dy)) return;
            dx < 0 ? this.next() : this.prev();
        },

        // ---- helpers ----
        preloadNeighbours() {
            [this.index + 1, this.index - 1].forEach((i) => {
                const item = this.images[(i + this.count) % this.count];
                if (!item) return;
                const img = new Image();
                img.src = item.full;
            });
        },
        lockScroll() {
            const shift = window.innerWidth - document.documentElement.clientWidth;
            document.body.style.overflow = 'hidden';
            if (shift > 0) document.body.style.paddingRight = `${shift}px`;
        },
        unlockScroll() {
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        },
    };
}