(function () {
    'use strict';

    const sliderSelectors = {
        media: '.media-slide',
        schedule: '.schedule-slide',
        countdown: '.countdown-card',
        news: '.news-card'
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
                    const isActive = idx === index;
                    slide.classList.toggle('active', isActive);

                    slide.querySelectorAll('video').forEach(video => {
                        if (isActive) {
                            const tryPlay = () => {
                                video.play().catch(() => {
                                    /* autoplay might be blocked */
                                });
                            };

                            if (video.readyState >= 2) {
                                tryPlay();
                            } else {
                                video.addEventListener('canplay', tryPlay, { once: true });
                            }
                        } else {
                            video.pause();
                            video.currentTime = 0;
                        }
                    });
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
        const section = document.querySelector('.next-period');
        if (!section) {
            return;
        }

        const signageData = window.__SIGNAGE__ || {};
        const periods = Array.isArray(signageData.periods) ? signageData.periods : [];

        const iconEl = section.querySelector('[data-role="next-period-icon"]');
        const labelEl = section.querySelector('[data-role="next-period-label"]');
        const countdownEl = section.querySelector('[data-role="next-period-countdown"]');
        const minutesEl = section.querySelector('[data-role="next-period-minutes"]');
        const secondsEl = section.querySelector('[data-role="next-period-seconds"]');
        const messageEl = section.querySelector('[data-role="next-period-message"]');
        const metaEl = section.querySelector('[data-role="next-period-meta"]');
        const metaLabelEl = section.querySelector('[data-role="next-period-meta-label"]');
        const metaTimeEl = section.querySelector('[data-role="next-period-meta-time"]');

        const stateIcons = {
            lesson: '📚',
            break: '☕',
            'before-school': '🌅',
            'after-school': '🏠',
            unconfigured: '⚙️'
        };

        const setHidden = (element, hidden) => {
            if (!element) {
                return;
            }
            if (hidden) {
                element.setAttribute('hidden', '');
            } else {
                element.removeAttribute('hidden');
            }
        };

        const parsePeriodTime = (value, referenceDate) => {
            if (!value) {
                return null;
            }
            const [hourStr, minuteStr] = value.split(':');
            const hour = Number.parseInt(hourStr, 10);
            const minute = Number.parseInt(minuteStr, 10);
            if (Number.isNaN(hour) || Number.isNaN(minute)) {
                return null;
            }
            const result = new Date(referenceDate);
            result.setHours(hour, minute, 0, 0);
            return result;
        };

        const buildTimeline = (referenceDate) => periods
            .map(period => {
                const start = parsePeriodTime(period.start_time, referenceDate);
                const end = parsePeriodTime(period.end_time, referenceDate);
                if (!start || !end) {
                    return null;
                }
                return {
                    label: period.label || '',
                    type: period.type || 'lesson',
                    start,
                    end
                };
            })
            .filter(Boolean)
            .sort((a, b) => a.start - b.start);

        const diffParts = (future, now) => {
            const diffMs = Math.max(0, future.getTime() - now.getTime());
            const totalSeconds = Math.floor(diffMs / 1000);
            return {
                minutes: Math.floor(totalSeconds / 60),
                seconds: totalSeconds % 60
            };
        };

        const formatLabel = slot => slot.label || (slot.type === 'break' ? 'Teneffüs' : 'Ders');

        const formatTime = date => date.toLocaleTimeString('tr-TR', {
            hour: '2-digit',
            minute: '2-digit',
            hour12: false
        });

        const formatNext = slot => ({
            label: formatLabel(slot),
            starts_at: formatTime(slot.start),
            type: slot.type
        });

        const computeState = (now) => {
            const weekday = now.getDay();
            if (weekday === 0 || weekday === 6) {
                return {
                    state: 'after-school',
                    label: 'Hafta Sonu',
                    message: 'Okulumuz tatilde',
                    timeLeft: null,
                    nextChangeAt: null,
                    nextPeriod: null
                };
            }

            const timeline = buildTimeline(now);
            if (!timeline.length) {
                return {
                    state: 'unconfigured',
                    label: 'Ders saatleri tanımlanmadı',
                    message: '',
                    timeLeft: null,
                    nextChangeAt: null,
                    nextPeriod: null
                };
            }

            const first = timeline[0];
            const last = timeline[timeline.length - 1];

            if (now < first.start) {
                return {
                    state: 'before-school',
                    label: 'Okulumuz Açılıyor',
                    message: '',
                    timeLeft: diffParts(first.start, now),
                    nextChangeAt: first.start,
                    nextPeriod: formatNext(first)
                };
            }

            if (now >= last.end) {
                return {
                    state: 'after-school',
                    label: 'Okulumuz Kapandı',
                    message: 'Yarın görüşmek üzere',
                    timeLeft: null,
                    nextChangeAt: null,
                    nextPeriod: null
                };
            }

            for (let i = 0; i < timeline.length; i += 1) {
                const slot = timeline[i];
                const nextSlot = timeline[i + 1] || null;

                if (now >= slot.start && now < slot.end) {
                    const state = slot.type === 'break' ? 'break' : 'lesson';
                    return {
                        state,
                        label: formatLabel(slot),
                        message: '',
                        timeLeft: diffParts(slot.end, now),
                        nextChangeAt: slot.end,
                        nextPeriod: nextSlot ? formatNext(nextSlot) : null
                    };
                }

                if (now < slot.start) {
                    return {
                        state: 'break',
                        label: 'Teneffüs',
                        message: '',
                        timeLeft: diffParts(slot.start, now),
                        nextChangeAt: slot.start,
                        nextPeriod: formatNext(slot)
                    };
                }
            }

            return {
                state: 'after-school',
                label: 'Okulumuz Kapandı',
                message: 'Yarın görüşmek üzere',
                timeLeft: null,
                nextChangeAt: null,
                nextPeriod: null
            };
        };

        const parseServerState = (raw) => {
            if (!raw || typeof raw !== 'object') {
                return null;
            }
            const nextChangeAt = raw.next_change_at ? parseDate(raw.next_change_at) : null;
            const rawTime = raw.timeLeft || raw.time_left;
            const timeLeft = rawTime && typeof rawTime === 'object'
                ? {
                    minutes: Number.parseInt(rawTime.minutes ?? 0, 10) || 0,
                    seconds: Number.parseInt(rawTime.seconds ?? 0, 10) || 0
                }
                : null;
            const nextPeriod = raw.next_period && typeof raw.next_period === 'object'
                ? {
                    label: raw.next_period.label || '',
                    starts_at: raw.next_period.starts_at || '',
                    type: raw.next_period.type || 'lesson'
                }
                : null;

            return {
                state: raw.state || 'unconfigured',
                label: raw.label || '',
                message: raw.message || '',
                timeLeft,
                nextChangeAt,
                nextPeriod
            };
        };

        const render = (state) => {
            if (!state) {
                return;
            }

            section.dataset.state = state.state;
            if (state.nextChangeAt instanceof Date && !Number.isNaN(state.nextChangeAt.getTime())) {
                section.setAttribute('data-next-change', state.nextChangeAt.toISOString());
            } else {
                section.removeAttribute('data-next-change');
            }

            if (iconEl) {
                iconEl.textContent = stateIcons[state.state] || 'ℹ️';
            }

            if (labelEl) {
                labelEl.textContent = state.label || '';
            }

            if (messageEl) {
                messageEl.textContent = state.message || '';
            }
            setHidden(messageEl, !state.message);

            if (minutesEl && secondsEl && state.timeLeft) {
                minutesEl.textContent = String(state.timeLeft.minutes);
                secondsEl.textContent = String(state.timeLeft.seconds).padStart(2, '0');
            }
            setHidden(countdownEl, !state.timeLeft);

            if (state.nextPeriod && metaLabelEl && metaTimeEl) {
                metaLabelEl.textContent = state.nextPeriod.label || '';
                metaTimeEl.textContent = state.nextPeriod.starts_at || '';
            }
            setHidden(metaEl, !state.nextPeriod);
        };

        const serverState = parseServerState(signageData.nextPeriod);
        if (serverState) {
            render(serverState);
        }

        if (!periods.length) {
            return;
        }

        const refresh = () => {
            const state = computeState(new Date());
            render(state);
        };

        refresh();
        setInterval(refresh, 1000);
    }

    document.addEventListener('DOMContentLoaded', () => {
        initSliders();
        initCountdowns();
        initNextPeriodTimer();
    });
})();
