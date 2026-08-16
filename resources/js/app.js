import Alpine from 'alpinejs';
import bookingSearch from './components/booking-search';
import cottageCalendar from './cottage-calendar';
import imageLightbox from './image-lightbox';

window.Alpine = Alpine;

/**
 * Register EVERY component before Alpine.start().
 *
 * Alpine walks the DOM and evaluates all x-data expressions inside start().
 * Anything registered after that point is invisible to elements already on the
 * page — the expression throws "<name> is not defined" and no data object is
 * created, which is why every child expression (loading, grid, quote, ...)
 * then reports as undefined too.
 */
Alpine.data('bookingSearch', bookingSearch);
Alpine.data('cottageCalendar', cottageCalendar);
Alpine.data('imageLightbox', imageLightbox);

/**
 * Scroll-reveal: any element with [data-reveal] fades/slides up once
 * it enters the viewport. Cheap, dependency-free, respects
 * prefers-reduced-motion via the CSS media query in app.css.
 */
document.addEventListener('DOMContentLoaded', () => {
    const revealEls = document.querySelectorAll('[data-reveal]');

    if (!revealEls.length) return;

    const io = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('animate-fade-up');
                entry.target.style.opacity = 1;
                io.unobserve(entry.target);
            });
        },
        { threshold: 0.15 }
    );

    revealEls.forEach((el, i) => {
        el.style.opacity = 0;
        el.style.animationDelay = `${i * 70}ms`;
        io.observe(el);
    });
});

Alpine.start();