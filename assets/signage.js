(function () {
    'use strict';

    const sliderSelectors = {
        media: '.media-slide',
        schedule: '.schedule-slide',
        countdown: '.countdown-card'
    };

    function initSliders() {
        document.querySelectorAll('[data-slider]').forEach(section => {
            const type = section.getAttribute('data-slider');
            const slideSelector = sliderSelectors[type];
            if (!slideSelector) {
                return;
            }

            const slides = Array.from(section.querySelectorAll(slideSelector));
            if (slides.length <= 1) {
                return;
            }

            let activeIndex = 0;
            let timerId = null;

            const defaultDuration = parseInt(section.getAttribute('data-default-duration') || '8', 10) * 1000;

            const activate = index => {
                slides.forEach((slide, idx) => {
                    slide.classList.toggle('active', idx === index);
                });
            };

            const next = () => {
                activeIndex = (activeIndex + 1) % slides.length;
                activate(activeIndex);
                schedule();
            };

            const schedule = () => {
                clearTimeout(timerId);
                const current = slides[activeIndex];
                const duration = parseInt(current.getAttribute('data-duration') || '0', 10);
                const timeout = (duration > 0 ? duration * 1000 : defaultDuration);
                timerId = setTimeout(next, timeout);
            };

            activate(activeIndex);
            schedule();
        });
    }

    function parseDate(value) {
        if (!value) {
            return null;
        }
        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? null : date;
    }

    function initCountdowns() {
        const cards = document.querySelectorAll('.countdown-card[data-target]');
        if (!cards.length) {
            return;
        }

        const update = () => {
            const now = new Date();
            cards.forEach(card => {
                const target = parseDate(card.getAttribute('data-target'));
                if (!target) {
                    return;
                }

                const diffMs = target.getTime() - now.getTime();
                const roles = {
                    days: card.querySelector('[data-role="countdown-days"]'),
                    hours: card.querySelector('[data-role="countdown-hours"]'),
                    minutes: card.querySelector('[data-role="countdown-minutes"]'),
                };

                if (diffMs <= 0) {
                    if (roles.days) roles.days.textContent = '0';
                    if (roles.hours) roles.hours.textContent = '00';
                    if (roles.minutes) roles.minutes.textContent = '00';
                    return;
                }

                const totalMinutes = Math.floor(diffMs / 60000);
                const days = Math.floor(totalMinutes / (60 * 24));
                const hours = Math.floor((totalMinutes % (60 * 24)) / 60);
                const minutes = totalMinutes % 60;

                if (roles.days) roles.days.textContent = String(days);
                if (roles.hours) roles.hours.textContent = String(hours).padStart(2, '0');
                if (roles.minutes) roles.minutes.textContent = String(minutes).padStart(2, '0');
            });
        };

        update();
        setInterval(update, 30000);
    }

    function initNextPeriodTimer() {
        const section = document.querySelector('.next-period[data-next-change]');
        if (!section) {
            return;
        }

        const targetValue = section.getAttribute('data-next-change');
        const minutesEl = section.querySelector('[data-role="next-period-minutes"]');
        const secondsEl = section.querySelector('[data-role="next-period-seconds"]');
        if (!targetValue || !minutesEl || !secondsEl) {
            return;
        }

        const targetDate = parseDate(targetValue);
        if (!targetDate) {
            return;
        }

        const update = () => {
            const diffMs = targetDate.getTime() - Date.now();
            if (diffMs <= 0) {
                minutesEl.textContent = '0';
                secondsEl.textContent = '00';
                return;
            }

            const totalSeconds = Math.floor(diffMs / 1000);
            const minutes = Math.floor(totalSeconds / 60);
            const seconds = totalSeconds % 60;
            minutesEl.textContent = String(minutes);
            secondsEl.textContent = String(seconds).padStart(2, '0');
        };

        update();
        setInterval(update, 1000);
    }

    document.addEventListener('DOMContentLoaded', () => {
        initSliders();
        initCountdowns();
        initNextPeriodTimer();
    });
})();
