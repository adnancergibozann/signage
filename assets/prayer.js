(function () {
    const state = {
        endpoint: (window.PRAYER_KIOSK && window.PRAYER_KIOSK.endpoint) || '/public/api/prayer_times.php',
        timezone: (window.PRAYER_KIOSK && window.PRAYER_KIOSK.initialTimezone) || 'UTC',
        serverOffset: 0,
        data: null,
        events: [],
        tableRows: {},
        audioElements: {},
        played: new Set(),
    };

    const elements = {
        time: document.getElementById('current-time'),
        date: document.getElementById('current-date'),
        tableBody: document.getElementById('prayer-rows'),
        upcomingList: document.getElementById('upcoming-events'),
        nextLabel: document.querySelector('[data-role="next-label"]'),
        nextTime: document.querySelector('[data-role="next-time"]'),
        countdown: document.querySelector('[data-role="countdown"]'),
        timezone: document.querySelector('[data-role="timezone"]'),
        audioContainer: document.getElementById('audio-container'),
    };

    function getNow() {
        return new Date(Date.now() - state.serverOffset);
    }

    function formatTime(date, withSeconds = false) {
        const options = { hour: '2-digit', minute: '2-digit' };
        if (withSeconds) {
            options.second = '2-digit';
        }
        return date.toLocaleTimeString('tr-TR', options);
    }

    function fetchData() {
        if (state.loading) {
            return;
        }
        state.loading = true;

        fetch(state.endpoint, { cache: 'no-store' })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('Sunucudan veri alınamadı.');
                }
                return response.json();
            })
            .then((json) => {
                if (json.error) {
                    throw new Error(json.message || 'Bilinmeyen hata.');
                }
                state.data = json;
                const reference = json.location_now || json.server_now;
                if (reference) {
                    state.serverOffset = Date.now() - Date.parse(reference);
                }
                if (json.settings && json.settings.timezone) {
                    state.timezone = json.settings.timezone;
                    if (elements.timezone) {
                        elements.timezone.textContent = json.settings.timezone;
                    }
                }
                buildTable(json.today_timings || []);
                buildEvents(json.events || []);
                buildUpcoming(json.upcoming || []);
                buildAudio(json.audio_profiles || {});
                updateAll();
            })
            .catch((error) => {
                console.error(error);
            })
            .finally(() => {
                state.loading = false;
            });
    }

    function buildTable(timings) {
        state.tableRows = {};
        if (!elements.tableBody) {
            return;
        }
        elements.tableBody.innerHTML = '';

        if (!timings.length) {
            const row = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = 3;
            cell.className = 'placeholder';
            cell.textContent = 'Namaz vakti bulunamadı. Ayarları kontrol edin.';
            row.appendChild(cell);
            elements.tableBody.appendChild(row);
            return;
        }

        timings.forEach((item) => {
            const row = document.createElement('tr');
            row.dataset.key = item.key;
            row.dataset.datetime = item.datetime;

            const labelCell = document.createElement('td');
            labelCell.textContent = item.label;
            row.appendChild(labelCell);

            const timeCell = document.createElement('td');
            timeCell.textContent = item.time;
            row.appendChild(timeCell);

            const statusCell = document.createElement('td');
            const status = document.createElement('span');
            status.className = 'status upcoming';
            status.textContent = 'Bekleniyor';
            statusCell.appendChild(status);
            row.appendChild(statusCell);

            elements.tableBody.appendChild(row);

            state.tableRows[item.key] = {
                row,
                status,
                datetime: new Date(item.datetime),
                label: item.label,
            };
        });
    }

    function buildEvents(events) {
        const played = state.played;
        state.events = (events || []).map((event) => {
            const date = new Date(event.datetime);
            return {
                ...event,
                date,
                played: played.has(event.datetime),
            };
        }).sort((a, b) => a.date - b.date);
    }

    function buildUpcoming(upcoming) {
        if (!elements.upcomingList) {
            return;
        }
        elements.upcomingList.innerHTML = '';
        if (!upcoming.length) {
            const item = document.createElement('li');
            item.className = 'placeholder';
            item.textContent = 'Yaklaşan vakit bulunamadı.';
            elements.upcomingList.appendChild(item);
            return;
        }

        upcoming.forEach((event) => {
            const li = document.createElement('li');
            const left = document.createElement('div');
            const right = document.createElement('div');

            left.innerHTML = `<strong>${event.label}</strong><div class="event-type">${event.type === 'special' ? 'Özel çağrı' : 'Namaz'}</div>`;
            right.textContent = formatTime(new Date(event.datetime));

            li.appendChild(left);
            li.appendChild(right);
            elements.upcomingList.appendChild(li);
        });
    }

    function buildAudio(profiles) {
        if (!elements.audioContainer) {
            return;
        }
        elements.audioContainer.innerHTML = '';
        state.audioElements = {};
        Object.keys(profiles).forEach((key) => {
            const profile = profiles[key];
            if (!profile || !profile.file_url) {
                return;
            }
            const audio = document.createElement('audio');
            audio.src = profile.file_url;
            audio.preload = 'auto';
            audio.dataset.key = key;
            elements.audioContainer.appendChild(audio);
            state.audioElements[key] = audio;
        });
    }

    function updateClock(now) {
        if (!elements.time) {
            return;
        }
        elements.time.textContent = formatTime(now, true);
        if (elements.date && state.data && state.data.current_date) {
            elements.date.textContent = state.data.current_date.label;
        }
    }

    function updateStatuses(now) {
        let nextRow = null;
        let nextDiff = Infinity;

        Object.values(state.tableRows).forEach((entry) => {
            const diff = entry.datetime - now;
            entry.row.classList.remove('next');
            entry.status.classList.remove('past', 'current', 'upcoming');

            if (Math.abs(diff) < 60000) {
                entry.status.textContent = 'Şimdi';
                entry.status.classList.add('current');
                entry.row.classList.add('active');
            } else if (diff < 0) {
                entry.status.textContent = 'Geçti';
                entry.status.classList.add('past');
                entry.row.classList.remove('active');
            } else {
                entry.status.textContent = diff <= 5 * 60 * 1000 ? 'Az kaldı' : 'Bekleniyor';
                entry.status.classList.add('upcoming');
                entry.row.classList.remove('active');
                if (diff < nextDiff) {
                    nextDiff = diff;
                    nextRow = entry;
                }
            }
        });

        if (nextRow) {
            nextRow.row.classList.add('next');
        }
    }

    function findNextEvent(now) {
        return state.events.find((event) => event.date >= now) || null;
    }

    function updateNextEvent(now) {
        const event = findNextEvent(now);
        if (!elements.nextLabel || !elements.nextTime || !elements.countdown) {
            return;
        }

        if (!event) {
            elements.nextLabel.textContent = 'Tüm vakitler tamamlandı';
            elements.nextTime.textContent = '--:--';
            elements.countdown.textContent = '--:--:--';
            return;
        }

        elements.nextLabel.textContent = event.label;
        elements.nextTime.textContent = formatTime(event.date);
        elements.countdown.textContent = formatCountdown(event.date - now);
    }

    function formatCountdown(diffMs) {
        if (diffMs <= 0) {
            return '00:00:00';
        }
        const totalSeconds = Math.floor(diffMs / 1000);
        const hours = Math.floor(totalSeconds / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const seconds = totalSeconds % 60;
        return [hours, minutes, seconds]
            .map((value) => String(value).padStart(2, '0'))
            .join(':');
    }

    function checkAudio(now) {
        state.events.forEach((event) => {
            if (event.played) {
                return;
            }
            if (now >= event.date && now - event.date < 10 * 60 * 1000) {
                const audio = state.audioElements[event.key];
                if (audio) {
                    audio.currentTime = 0;
                    audio.play().catch((error) => {
                        console.warn('Ses çalınamadı:', error);
                    });
                }
                event.played = true;
                state.played.add(event.datetime);
            }
        });
    }

    function updateAll() {
        const now = getNow();
        updateClock(now);
        updateStatuses(now);
        updateNextEvent(now);
    }

    function tick() {
        const now = getNow();
        updateClock(now);
        if (!state.data) {
            return;
        }
        updateStatuses(now);
        updateNextEvent(now);
        checkAudio(now);
    }

    fetchData();
    setInterval(tick, 1000);
    setInterval(fetchData, 5 * 60 * 1000);

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            fetchData();
        }
    });
})();
