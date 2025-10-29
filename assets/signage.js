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
        const currentEl = section.querySelector('[data-role="next-period-current"]');
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

        const formatTime = date => date.toLocaleTimeString('tr-TR', {
            hour: '2-digit',
            minute: '2-digit',
            hour12: false
        });

        const buildTimeline = (referenceDate) => periods
            .map(period => {
                const start = parsePeriodTime(period.start_time, referenceDate);
                const end = parsePeriodTime(period.end_time, referenceDate);
                if (!start || !end || end <= start) {
                    return null;
                }
                return {
                    label: period.label || '',
                    type: period.type || 'lesson',
                    start,
                    end,
                    startLabel: period.start_time || formatTime(start),
                    endLabel: period.end_time || formatTime(end)
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

        const labelForSlot = slot => slot.label || (slot.type === 'break' ? 'Teneffüs' : 'Ders');

        const formatCurrent = slot => ({
            label: labelForSlot(slot),
            type: slot.type,
            start_time: slot.startLabel || formatTime(slot.start),
            end_time: slot.endLabel || formatTime(slot.end)
        });

        const formatNext = slot => ({
            label: labelForSlot(slot),
            starts_at: slot.startLabel || formatTime(slot.start),
            type: slot.type
        });

        const findNextOfType = (timeline, fromIndex, type) => {
            for (let i = fromIndex + 1; i < timeline.length; i += 1) {
                const candidate = timeline[i];
                if ((candidate.type || 'lesson') === type) {
                    return candidate;
                }
            }
            return null;
        };

        const headlineForState = (state) => {
            switch (state) {
                case 'lesson':
                    return 'Teneffüse Kalan Süre';
                case 'break':
                    return 'Derse Kalan Süre';
                case 'before-school':
                    return 'İlk Derse Kalan Süre';
                case 'after-school':
                    return 'Okulumuz Kapandı';
                case 'unconfigured':
                    return 'Ders saatleri tanımlanmadı';
                default:
                    return 'Ders Durumu';
            }
        };

        const computeState = (now) => {
            const timeline = buildTimeline(now);
            if (!timeline.length) {
                return {
                    state: 'unconfigured',
                    label: headlineForState('unconfigured'),
                    message: '',
                    timeLeft: null,
                    nextChangeAt: null,
                    currentPeriod: null,
                    nextPeriod: null
                };
            }

            const first = timeline[0];
            const last = timeline[timeline.length - 1];

            if (now < first.start) {
                return {
                    state: 'before-school',
                    label: headlineForState('before-school'),
                    message: '',
                    timeLeft: diffParts(first.start, now),
                    nextChangeAt: first.start,
                    currentPeriod: null,
                    nextPeriod: formatNext(first)
                };
            }

            if (now >= last.end) {
                return {
                    state: 'after-school',
                    label: headlineForState('after-school'),
                    message: 'Yarın görüşmek üzere',
                    timeLeft: null,
                    nextChangeAt: null,
                    currentPeriod: null,
                    nextPeriod: null
                };
            }

            for (let i = 0; i < timeline.length; i += 1) {
                const slot = timeline[i];
                const nextSlot = timeline[i + 1] || null;

                if (now >= slot.start && now < slot.end) {
                    const state = (slot.type || 'lesson') === 'break' ? 'break' : 'lesson';
                    const targetType = state === 'lesson' ? 'break' : 'lesson';
                    const targetSlot = findNextOfType(timeline, i, targetType) || nextSlot;
                    const targetMoment = targetSlot ? targetSlot.start : slot.end;

                    return {
                        state,
                        label: headlineForState(state),
                        message: '',
                        timeLeft: diffParts(targetMoment, now),
                        nextChangeAt: targetMoment,
                        currentPeriod: formatCurrent(slot),
                        nextPeriod: targetSlot ? formatNext(targetSlot) : null
                    };
                }

                if (now < slot.start) {
                    const prevSlot = timeline[i - 1] || null;
                    const gapStartLabel = prevSlot ? (prevSlot.endLabel || formatTime(prevSlot.end)) : formatTime(now);
                    const gapSlot = {
                        label: 'Teneffüs',
                        type: 'break',
                        start: prevSlot ? prevSlot.end : now,
                        end: slot.start,
                        startLabel: gapStartLabel,
                        endLabel: slot.startLabel || formatTime(slot.start)
                    };

                    return {
                        state: 'break',
                        label: headlineForState('break'),
                        message: '',
                        timeLeft: diffParts(slot.start, now),
                        nextChangeAt: slot.start,
                        currentPeriod: formatCurrent(gapSlot),
                        nextPeriod: formatNext(slot)
                    };
                }
            }

            return {
                state: 'after-school',
                label: headlineForState('after-school'),
                message: 'Yarın görüşmek üzere',
                timeLeft: null,
                nextChangeAt: null,
                currentPeriod: null,
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
            const currentPeriod = raw.current_period && typeof raw.current_period === 'object'
                ? {
                    label: raw.current_period.label || '',
                    type: raw.current_period.type || 'lesson',
                    start_time: raw.current_period.start_time || '',
                    end_time: raw.current_period.end_time || ''
                }
                : null;

            return {
                state: raw.state || 'unconfigured',
                label: raw.label || '',
                message: raw.message || '',
                timeLeft,
                nextChangeAt,
                nextPeriod,
                currentPeriod
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

            if (currentEl) {
                if (state.currentPeriod) {
                    const label = (state.currentPeriod.label || '').trim();
                    const startTime = (state.currentPeriod.start_time || '').trim();
                    const endTime = (state.currentPeriod.end_time || '').trim();
                    const parts = [];
                    if (label) {
                        parts.push(label);
                    }
                    let range = '';
                    if (startTime && endTime) {
                        range = `${startTime} - ${endTime}`;
                    } else if (startTime) {
                        range = startTime;
                    } else if (endTime) {
                        range = endTime;
                    }
                    if (range) {
                        parts.push(`(${range})`);
                    }
                    const summary = parts.length ? `Şu an: ${parts.join(' ')}` : '';
                    currentEl.textContent = summary;
                    setHidden(currentEl, summary === '');
                } else {
                    currentEl.textContent = '';
                    setHidden(currentEl, true);
                }
            }

            if (messageEl) {
                messageEl.textContent = state.message || '';
            }
            setHidden(messageEl, !state.message);

            if (minutesEl && secondsEl && state.timeLeft) {
                minutesEl.textContent = String(state.timeLeft.minutes);
                secondsEl.textContent = String(state.timeLeft.seconds).padStart(2, '0');
            } else if (minutesEl && secondsEl) {
                minutesEl.textContent = '0';
                secondsEl.textContent = '00';
            }
            setHidden(countdownEl, !state.timeLeft);

            if (state.nextPeriod && metaLabelEl && metaTimeEl) {
                metaLabelEl.textContent = state.nextPeriod.label || '';
                metaTimeEl.textContent = state.nextPeriod.starts_at || '';
            } else {
                if (metaLabelEl) {
                    metaLabelEl.textContent = '';
                }
                if (metaTimeEl) {
                    metaTimeEl.textContent = '';
                }
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

    function initWeatherAutoRefresh() {
        const section = document.querySelector('.weather-module');
        if (!section) {
            return;
        }

        const signageData = window.__SIGNAGE__ || {};
        const endpoint = typeof signageData.weatherEndpoint === 'string' ? signageData.weatherEndpoint : '';
        const refreshMinutes = Number.parseInt(signageData.weatherRefreshMinutes ?? 0, 10);

        if (!endpoint || !Number.isFinite(refreshMinutes) || refreshMinutes <= 0) {
            return;
        }

        const placeholder = section.querySelector('[data-role="weather-placeholder"]');
        const content = section.querySelector('[data-role="weather-content"]');
        const cityEl = section.querySelector('[data-role="weather-city"]');
        const tempEl = section.querySelector('[data-role="weather-temp"]');
        const iconEl = section.querySelector('[data-role="weather-icon"]');
        const conditionEl = section.querySelector('[data-role="weather-condition"]');
        const feelsEl = section.querySelector('[data-role="weather-feels"]');
        const humidityEl = section.querySelector('[data-role="weather-humidity"]');
        const windEl = section.querySelector('[data-role="weather-wind"]');
        const updatedEl = section.querySelector('[data-role="weather-updated"]');
        const updatedTimeEl = section.querySelector('[data-role="weather-updated-time"]');

        const setHidden = (el, hidden) => {
            if (!el) {
                return;
            }
            if (hidden) {
                el.setAttribute('hidden', '');
            } else {
                el.removeAttribute('hidden');
            }
        };

        const formatNumber = (value, fractionDigits = 1) => {
            const numeric = Number.parseFloat(value);
            return Number.isFinite(numeric) ? numeric.toFixed(fractionDigits) : null;
        };

        const formatTimeLabel = (value) => {
            const date = parseDate(value);
            if (!date) {
                return null;
            }
            return date.toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit', hour12: false });
        };

        const render = (data) => {
            if (!data) {
                setHidden(content, true);
                setHidden(placeholder, false);
                return;
            }

            setHidden(content, false);
            setHidden(placeholder, true);

            if (cityEl) {
                cityEl.textContent = data.city || '';
            }

            if (tempEl) {
                const formattedTemp = formatNumber(data.temperature, 1);
                tempEl.textContent = formattedTemp !== null ? `${formattedTemp}°C` : '—';
            }

            if (iconEl) {
                if (data.icon) {
                    iconEl.textContent = data.icon;
                    setHidden(iconEl, false);
                } else {
                    iconEl.textContent = '';
                    setHidden(iconEl, true);
                }
            }

            if (conditionEl) {
                conditionEl.textContent = data.condition || '';
            }

            if (feelsEl) {
                const formatted = formatNumber(data.feels_like, 1);
                if (formatted !== null) {
                    feelsEl.textContent = `Hissedilen: ${formatted}°C`;
                    setHidden(feelsEl, false);
                } else {
                    feelsEl.textContent = '';
                    setHidden(feelsEl, true);
                }
            }

            if (humidityEl) {
                const humidity = Number.parseInt(data.humidity ?? '', 10);
                if (Number.isFinite(humidity)) {
                    humidityEl.textContent = `Nem: %${humidity}`;
                    setHidden(humidityEl, false);
                } else {
                    humidityEl.textContent = '';
                    setHidden(humidityEl, true);
                }
            }

            if (windEl) {
                const wind = formatNumber(data.wind_speed, 1);
                if (wind !== null) {
                    windEl.textContent = `Rüzgar: ${wind} km/sa`;
                    setHidden(windEl, false);
                } else {
                    windEl.textContent = '';
                    setHidden(windEl, true);
                }
            }

            if (updatedEl && updatedTimeEl) {
                const timeLabel = data.fetched_at ? formatTimeLabel(data.fetched_at) : null;
                if (timeLabel) {
                    updatedTimeEl.textContent = timeLabel;
                    setHidden(updatedEl, false);
                } else {
                    updatedTimeEl.textContent = '';
                    setHidden(updatedEl, true);
                }
            }
        };

        let timerId = null;

        const clearTimer = () => {
            if (timerId) {
                window.clearTimeout(timerId);
                timerId = null;
            }
        };

        const scheduleNext = () => {
            clearTimer();
            timerId = window.setTimeout(fetchWeather, Math.max(1, refreshMinutes) * 60 * 1000);
        };

        const fetchWeather = () => {
            fetch(`${endpoint}?_=${Date.now()}`, { cache: 'no-store' })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error('HTTP error');
                    }
                    return response.json();
                })
                .then((payload) => {
                    if (payload && payload.success && payload.data) {
                        render(payload.data);
                    }
                })
                .catch(() => {
                    /* sessiz */
                })
                .finally(() => {
                    scheduleNext();
                });
        };

        scheduleNext();
    }

    function initScheduleAutoScroll() {
        const slides = document.querySelectorAll('.schedule-slide');
        if (!slides.length) {
            return;
        }

        slides.forEach((slide) => {
            const container = slide.querySelector('[data-role="schedule-scroll"]');
            if (!container) {
                return;
            }

            let frameId = null;
            let pauseId = null;
            let direction = 1;

            const clearTimers = () => {
                if (frameId) {
                    window.clearTimeout(frameId);
                    frameId = null;
                }
                if (pauseId) {
                    window.clearTimeout(pauseId);
                    pauseId = null;
                }
            };

            const scheduleStep = (delay = 60) => {
                frameId = window.setTimeout(() => {
                    frameId = null;
                    window.requestAnimationFrame(step);
                }, delay);
            };

            const step = () => {
                if (!slide.classList.contains('active')) {
                    return;
                }

                const maxScroll = container.scrollHeight - container.clientHeight;
                if (maxScroll <= 0) {
                    return;
                }

                container.scrollTop += direction;

                if (direction > 0 && container.scrollTop >= maxScroll) {
                    container.scrollTop = maxScroll;
                    clearTimers();
                    pauseId = window.setTimeout(() => {
                        direction = -1;
                        scheduleStep();
                    }, 2000);
                    return;
                }

                if (direction < 0 && container.scrollTop <= 0) {
                    container.scrollTop = 0;
                    clearTimers();
                    pauseId = window.setTimeout(() => {
                        direction = 1;
                        scheduleStep();
                    }, 2000);
                    return;
                }

                scheduleStep();
            };

            const start = () => {
                clearTimers();
                container.scrollTop = 0;
                direction = 1;

                if (container.scrollHeight <= container.clientHeight + 1) {
                    return;
                }

                scheduleStep();
            };

            const stop = () => {
                clearTimers();
            };

            const observer = new MutationObserver(() => {
                if (slide.classList.contains('active')) {
                    start();
                } else {
                    stop();
                }
            });

            observer.observe(slide, { attributes: true, attributeFilter: ['class'] });

            if (slide.classList.contains('active')) {
                start();
            }

            window.addEventListener('resize', () => {
                if (slide.classList.contains('active')) {
                    start();
                }
            });
        });
    }

    function initSignageAutoRefresh() {
        const meta = window.__SIGNAGE_META__ || {};
        const endpoint = typeof meta.endpoint === 'string' ? meta.endpoint : '';
        let interval = Number.parseInt(meta.pollInterval ?? 0, 10);
        let currentVersion = typeof meta.version === 'string' ? meta.version : '';

        if (!endpoint) {
            return;
        }

        if (!Number.isFinite(interval) || interval <= 0) {
            interval = 30;
        }

        const minInterval = 5;
        let timerId = null;
        let pending = false;

        const clearTimer = () => {
            if (timerId) {
                window.clearTimeout(timerId);
                timerId = null;
            }
        };

        const scheduleNext = () => {
            clearTimer();
            timerId = window.setTimeout(checkForUpdates, Math.max(minInterval, interval) * 1000);
        };

        const checkForUpdates = () => {
            if (pending) {
                return;
            }

            pending = true;

            const url = `${endpoint}?_=${Date.now()}`;

            fetch(url, { cache: 'no-store' })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error('HTTP error');
                    }
                    return response.json();
                })
                .then((payload) => {
                    if (!payload || payload.success !== true) {
                        return;
                    }

                    const nextVersion = typeof payload.version === 'string' ? payload.version : '';
                    if (nextVersion && nextVersion !== currentVersion) {
                        window.location.reload();
                        return;
                    }

                    if (nextVersion) {
                        currentVersion = nextVersion;
                    }
                })
                .catch(() => {
                    /* sessizce yoksay */
                })
                .finally(() => {
                    pending = false;
                    scheduleNext();
                });
        };

        scheduleNext();
    }

    document.addEventListener('DOMContentLoaded', () => {
        initSliders();
        initCountdowns();
        initNextPeriodTimer();
        initWeatherAutoRefresh();
        initScheduleAutoScroll();
        initSignageAutoRefresh();
    });
})();
