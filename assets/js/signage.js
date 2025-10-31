const signageState = {
    refreshTimer: null,
    refreshSeconds: 5,
    timeFormat: '24h',
    dataTimestamp: null,
    clockTimer: null,
    countdownTimers: [],
    mediaTimer: null,
};

async function loadSignageState() {
    try {
        const response = await fetch('/public/api/signage.php', { cache: 'no-store' });
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
        logo.src = settings.logoUrl;
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
        photo.src = manager.photoUrl || '/assets/placeholder-profile.svg';
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

        if (manager.status === 'meeting' && manager.remainingSeconds !== null) {
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
        node.src = media.url;
        node.autoplay = true;
        node.loop = false;
        node.controls = false;
    } else {
        node = document.createElement('img');
        node.src = media.url;
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
    image.src = url;
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
