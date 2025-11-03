const BASE_PATH = window.APP_BASE_PATH || '';
const PLACEHOLDER_PHOTO = window.APP_PLACEHOLDER_PHOTO
    || (BASE_PATH ? `${BASE_PATH}/assets/placeholder-profile.svg` : '/assets/placeholder-profile.svg');

function withBase(path) {
    if (!path) {
        return path;
    }
    if (typeof path !== 'string') {
        return path;
    }
    const lower = path.toLowerCase();
    if (lower.startsWith('http://') || lower.startsWith('https://') || lower.startsWith('//') || lower.startsWith('data:')) {
        return path;
    }
    if (BASE_PATH && path.startsWith(BASE_PATH)) {
        return path;
    }
    if (BASE_PATH) {
        const trimmed = path.startsWith('/') ? path.slice(1) : path;
        const prefix = BASE_PATH.endsWith('/') ? BASE_PATH.slice(0, -1) : BASE_PATH;
        return `${prefix}/${trimmed}`;
    }
    return path.startsWith('/') ? path : `/${path}`;
}

const signageState = {
    refreshTimer: null,
    refreshSeconds: 5,
    timeFormat: '24h',
    dataTimestamp: null,
    clockTimer: null,
    countdownTimers: [],
    mediaTimer: null,
};

function isSameDay(dateA, dateB) {
    return dateA.getFullYear() === dateB.getFullYear()
        && dateA.getMonth() === dateB.getMonth()
        && dateA.getDate() === dateB.getDate();
}

function formatMeetingTime(meeting, referenceDate) {
    const startIso = meeting?.scheduledStartIso || meeting?.scheduledStart;
    if (!startIso) {
        return meeting?.scheduledStart || '';
    }
    const startDate = new Date(startIso);
    if (Number.isNaN(startDate.getTime())) {
        return meeting?.scheduledStart || '';
    }
    const timeString = startDate.toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit' });
    if (meeting?.status === 'in_progress') {
        return `Şimdi · ${timeString}`;
    }
    const isToday = meeting?.isToday;
    const sameDay = typeof isToday === 'boolean' ? isToday : isSameDay(startDate, referenceDate);
    if (sameDay) {
        return `Bugün · ${timeString}`;
    }
    const dateString = startDate.toLocaleDateString('tr-TR', {
        weekday: 'long',
        day: '2-digit',
        month: 'long',
    });
    return `${dateString} · ${timeString}`;
}

async function loadSignageState() {
    try {
        const response = await fetch(withBase('/public/api/signage.php'), { cache: 'no-store' });
        if (!response.ok) {
            throw new Error('Veri alınamadı');
        }
        const data = await response.json();
        signageState.dataTimestamp = data.timestamp;
        signageState.refreshSeconds = data.settings.refreshSeconds || 5;
        signageState.timeFormat = data.settings.timeFormat || '24h';
        renderSignage(data);
        scheduleRefresh();
    } catch (error) {
        console.error('Signage güncellenirken hata oluştu:', error);
        scheduleRefresh(10);
    }
}

function scheduleRefresh(overrideSeconds) {
    const seconds = overrideSeconds || signageState.refreshSeconds || 5;
    if (signageState.refreshTimer) {
        clearTimeout(signageState.refreshTimer);
    }
    signageState.refreshTimer = setTimeout(loadSignageState, seconds * 1000);
}

function renderSignage(data) {
    applyTheme(data.settings);
    renderClock(data.timestamp, data.settings.timeFormat);
    renderManagers(data.managers);
    renderAnnouncements(data.announcements);
    renderTicker(data.ticker, data.settings);
    renderAlerts(data.alerts);
    renderMedia(data.activeMedia);
    setupOrganigram(data.settings.organigramUrl);
}

function applyTheme(settings) {
    const root = document.documentElement;
    root.style.setProperty('--gap-primary', settings.primaryColor);
    root.style.setProperty('--gap-secondary', settings.secondaryColor);
    const logo = document.querySelector('.branding img');
    if (settings.logoUrl && logo) {
        logo.src = withBase(settings.logoUrl);
        logo.style.display = 'block';
    } else if (logo) {
        logo.style.display = 'none';
    }
    const company = document.querySelector('.branding h1');
    if (company) {
        company.textContent = settings.companyName || 'Gapgross';
    }
}

function renderClock(timestamp, format) {
    const targetTime = timestamp ? new Date(timestamp) : new Date();
    updateClockDisplay(targetTime, format);
    if (signageState.clockTimer) {
        clearInterval(signageState.clockTimer);
    }
    signageState.clockTimer = setInterval(() => updateClockDisplay(new Date(), format), 1000);
}

function updateClockDisplay(dateObj, format) {
    const timeEl = document.querySelector('.clock .time');
    const dateEl = document.querySelector('.clock .date');
    if (!timeEl || !dateEl) {
        return;
    }
    const options = { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' };
    dateEl.textContent = dateObj.toLocaleDateString('tr-TR', options);
    let hours = dateObj.getHours();
    let minutes = dateObj.getMinutes().toString().padStart(2, '0');
    let suffix = '';
    if (format === '12h') {
        suffix = hours >= 12 ? ' PM' : ' AM';
        hours = hours % 12 || 12;
    }
    timeEl.textContent = `${hours.toString().padStart(2, '0')}:${minutes}${suffix}`;
}

function renderManagers(managers) {
    const container = document.querySelector('.status-board');
    container.innerHTML = '';
    signageState.countdownTimers.forEach((timer) => clearInterval(timer));
    signageState.countdownTimers = [];
    const referenceDate = signageState.dataTimestamp ? new Date(signageState.dataTimestamp) : new Date();
    if (!managers || managers.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'manager-card';
        empty.textContent = 'Tanımlı satınalma müdürü bulunamadı.';
        container.appendChild(empty);
        return;
    }

    managers.forEach((manager) => {
        const card = document.createElement('div');
        card.className = `manager-card ${manager.status}`;
        card.dataset.statusLabel = manager.statusLabel;

        const header = document.createElement('div');
        header.className = 'manager-header';
        const photo = document.createElement('img');
        photo.className = 'manager-photo';
        const photoUrl = manager.photoUrl ? withBase(manager.photoUrl) : PLACEHOLDER_PHOTO;
        photo.src = photoUrl;
        photo.alt = manager.name;
        header.appendChild(photo);

        const info = document.createElement('div');
        info.className = 'manager-info';
        const name = document.createElement('h2');
        name.textContent = manager.name;
        const dept = document.createElement('span');
        dept.textContent = manager.department || '';
        info.appendChild(name);
        info.appendChild(dept);
        header.appendChild(info);
        card.appendChild(header);

        if (manager.note) {
            const note = document.createElement('div');
            note.className = 'status-note';
            note.textContent = manager.note;
            card.appendChild(note);
        }

        if (manager.nextMeeting) {
            const meeting = manager.nextMeeting;
            const meetingBox = document.createElement('div');
            const statusClass = meeting.status ? meeting.status.replace(/_/g, '-') : '';
            meetingBox.className = `next-meeting next-meeting--ticker${statusClass ? ` ${statusClass}` : ''}`;

            const label = document.createElement('div');
            label.className = 'next-meeting__label';
            label.textContent = meeting.statusLabel || 'Planlı Görüşme';
            meetingBox.appendChild(label);

            const ticker = document.createElement('div');
            ticker.className = 'next-meeting__ticker';
            const track = document.createElement('div');
            track.className = 'next-meeting__track';
            const text = buildMeetingTickerText(meeting, referenceDate) || 'Planlı görüşme bilgisi bekleniyor.';
            const span = document.createElement('span');
            span.className = 'next-meeting__text';
            span.textContent = text;
            track.appendChild(span);
            track.appendChild(span.cloneNode(true));
            ticker.appendChild(track);
            meetingBox.appendChild(ticker);

            card.appendChild(meetingBox);
        }

        if (manager.remainingSeconds !== null) {
            const countdown = document.createElement('div');
            countdown.className = 'countdown';
            const target = Date.now() + manager.remainingSeconds * 1000;
            updateCountdown(countdown, target);
            const timerId = setInterval(() => updateCountdown(countdown, target), 1000);
            signageState.countdownTimers.push(timerId);
            card.appendChild(countdown);
        }

        container.appendChild(card);
    });
}

function buildMeetingTickerText(meeting, referenceDate) {
    const parts = [];
    const whoParts = [];
    if (meeting.visitorName) {
        whoParts.push(meeting.visitorName);
    }
    if (meeting.visitorCompany) {
        whoParts.push(meeting.visitorCompany);
    }
    if (whoParts.length > 0) {
        parts.push(whoParts.join(' · '));
    }

    const timeText = formatMeetingTime(meeting, referenceDate);
    if (timeText) {
        parts.push(timeText);
    }

    if (meeting.purpose) {
        parts.push(meeting.purpose);
    }

    if (meeting.notes) {
        parts.push(meeting.notes);
    }

    return parts.join(' · ');
}

function updateCountdown(el, target) {
    const diff = Math.max(0, Math.floor((target - Date.now()) / 1000));
    const minutes = Math.floor(diff / 60).toString().padStart(2, '0');
    const seconds = (diff % 60).toString().padStart(2, '0');
    el.textContent = `${minutes}:${seconds}`;
}

function renderAnnouncements(announcements) {
    const list = document.querySelector('.announcements-list');
    list.innerHTML = '';
    if (!announcements || announcements.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'announcement-item';
        empty.textContent = 'Aktif duyuru bulunmuyor.';
        list.appendChild(empty);
        return;
    }
    announcements.forEach((announcement) => {
        const item = document.createElement('div');
        item.className = 'announcement-item';
        const title = document.createElement('strong');
        title.textContent = announcement.title;
        const body = document.createElement('p');
        body.textContent = announcement.body || '';
        item.appendChild(title);
        if (announcement.body) {
            item.appendChild(body);
        }
        list.appendChild(item);
    });
}

function renderTicker(items, settings) {
    const track = document.querySelector('.ticker-track');
    track.innerHTML = '';
    const speed = settings.tickerSpeed ? `${settings.tickerSpeed}s` : '35s';
    track.style.setProperty('animation-duration', speed);
    if (!items || items.length === 0) {
        const placeholder = document.createElement('div');
        placeholder.className = 'ticker-item';
        placeholder.textContent = 'Gapgross satınalma departmanına hoş geldiniz.';
        track.appendChild(placeholder);
        track.appendChild(placeholder.cloneNode(true));
        return;
    }
    const doubled = [...items, ...items];
    doubled.forEach((text) => {
        const item = document.createElement('div');
        item.className = 'ticker-item';
        item.textContent = text;
        track.appendChild(item);
    });
}

function renderAlerts(alerts) {
    const container = document.querySelector('.alerts');
    container.innerHTML = '';
    if (!alerts || alerts.length === 0) {
        return;
    }
    alerts.forEach((alert) => {
        const el = document.createElement('div');
        el.className = 'alert-banner';
        el.textContent = alert.message;
        container.appendChild(el);
    });
}

function renderMedia(media) {
    const overlay = document.querySelector('.fullscreen-media');
    if (!overlay) return;
    overlay.innerHTML = '';
    if (signageState.mediaTimer) {
        clearTimeout(signageState.mediaTimer);
        signageState.mediaTimer = null;
    }
    if (!media) {
        overlay.classList.remove('active');
        return;
    }
    let node;
    if (media.type === 'video') {
        node = document.createElement('video');
        node.src = withBase(media.url);
        node.autoplay = true;
        node.loop = false;
        node.controls = false;
    } else {
        node = document.createElement('img');
        node.src = withBase(media.url);
        node.alt = media.title;
    }
    overlay.appendChild(node);
    overlay.classList.add('active');
    if (media.durationSeconds) {
        signageState.mediaTimer = setTimeout(() => overlay.classList.remove('active'), media.durationSeconds * 1000);
    }
}

function setupOrganigram(url) {
    const button = document.querySelector('.organigram-button');
    const modal = document.getElementById('organigram-modal');
    if (!button || !modal) {
        return;
    }
    if (!url) {
        button.style.display = 'none';
        return;
    }
    button.style.display = 'inline-flex';
    const image = modal.querySelector('img');
    image.src = withBase(url);
    button.onclick = () => modal.classList.add('active');
    modal.querySelector('button').onclick = () => modal.classList.remove('active');
    if (!modal.dataset.bound) {
        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                modal.classList.remove('active');
            }
        });
        modal.dataset.bound = 'true';
    }
}

document.addEventListener('DOMContentLoaded', () => {
    loadSignageState();
});
