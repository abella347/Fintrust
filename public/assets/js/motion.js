(function () {
    'use strict';

    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    window.FTMotion = { ok: function () { return !reduce; } };

    // Groups of elements that reveal together, each one slightly after the previous.
    var GROUPS = [
        '.strip__grid > div', '.section-head', '.about > div', '.values li', '.feature', '.steps__item',
        '.security__item', '.security__note', '.faq details', '.cta', '.contact__card', '.site-footer__grid > div'
    ];

    function initReveal() {
        if (reduce || !('IntersectionObserver' in window)) return;
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('is-visible');
                io.unobserve(entry.target);
            });
        }, { rootMargin: '0px 0px -8% 0px', threshold: 0.12 });

        GROUPS.forEach(function (selector) {
            var seen = new Map(); // Parent -> count, for the stagger
            document.querySelectorAll(selector).forEach(function (node) {
                if (node.classList.contains('reveal')) return;
                var n = seen.get(node.parentNode) || 0;
                seen.set(node.parentNode, n + 1);
                node.classList.add('reveal');
                node.style.transitionDelay = Math.min(n, 5) * 90 + 'ms';
                io.observe(node);
            });
        });
    }

    function initHeaderShadow() {
        var header = document.querySelector('.site-header');
        if (!header) return;
        var update = function () { header.classList.toggle('is-scrolled', window.scrollY > 8); };
        window.addEventListener('scroll', update, { passive: true });
        update();
    }

    // Home hero: background scrolls slower than the page (parallax) and drifts a little
    // with the mouse. Only CSS custom properties are set, through the CSSOM.
    function initHeroParallax() {
        var bg = document.querySelector('[data-hero-bg]');
        if (!bg || reduce) return;
        var hero = bg.parentNode, ticking = false, mx = 0, my = 0, tx = 0, ty = 0;
        function onScroll() {
            if (ticking) return;
            ticking = true;
            window.requestAnimationFrame(function () {
                var y = Math.max(0, window.scrollY);
                if (y < hero.offsetHeight * 1.2) bg.style.setProperty('--hero-sy', (y * 0.35).toFixed(1) + 'px');
                ticking = false;
            });
        }
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();

        if (!window.matchMedia('(pointer: fine)').matches) return; // No mouse drift on touch screens
        var running = false;
        function ease() {                                    // Smooth follow; stops once caught up
            mx += (tx - mx) * 0.06; my += (ty - my) * 0.06;
            bg.style.setProperty('--hero-mx', mx.toFixed(2) + 'px');
            bg.style.setProperty('--hero-my', my.toFixed(2) + 'px');
            running = Math.abs(tx - mx) > 0.05 || Math.abs(ty - my) > 0.05;
            if (running) window.requestAnimationFrame(ease);
        }
        function aim(x, y) { tx = x; ty = y; if (!running) { running = true; window.requestAnimationFrame(ease); } }
        hero.addEventListener('pointermove', function (e) {
            var r = hero.getBoundingClientRect();
            aim(((e.clientX - r.left) / r.width - 0.5) * -24, ((e.clientY - r.top) / r.height - 0.5) * -16);
        });
        hero.addEventListener('pointerleave', function () { aim(0, 0); });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initReveal();
        initHeaderShadow();
        initHeroParallax();
    });
})();
