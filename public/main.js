const state = {
  locations: [],
  settings: null,
  offsets: null,
  voices: []
};

const loginCard = document.getElementById('login-card');
const adminUI = document.getElementById('admin-ui');
const loginForm = document.getElementById('login-form');
const logoutBtn = document.getElementById('logout');

loginForm?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const formData = new FormData(loginForm);
  const body = Object.fromEntries(formData.entries());
  const res = await fetch('/api/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body)
  });
  if (res.ok) {
    loginCard.classList.add('hidden');
    adminUI.classList.remove('hidden');
    initApp();
  } else {
    alert('Giriş başarısız');
  }
});

logoutBtn?.addEventListener('click', async () => {
  await fetch('/api/logout', { method: 'POST' });
  window.location.reload();
});

const tabButtons = document.querySelectorAll('.tab-btn');
const tabPanels = document.querySelectorAll('.tab-panel');

tabButtons.forEach((btn) =>
  btn.addEventListener('click', () => {
    tabButtons.forEach((b) => b.classList.remove('bg-emerald-600', 'text-white'));
    tabButtons.forEach((b) => b.classList.add('bg-white'));
    btn.classList.add('bg-emerald-600', 'text-white');
    btn.classList.remove('bg-white');
    const tab = btn.dataset.tab;
    tabPanels.forEach((panel) => {
      panel.classList.toggle('hidden', panel.dataset.panel !== tab);
    });
  })
);

async function initApp() {
  await loadLocations();
  await loadSettings();
  await loadVoices();
  await loadTimes();
  await loadSchedule();
  await loadLogs();
  setInterval(updateCountdown, 1000);
}

async function loadLocations() {
  const res = await fetch('/api/locations');
  if (res.ok) {
    state.locations = await res.json();
    populateLocations();
  }
}

function populateLocations() {
  const countrySel = document.getElementById('country');
  const citySel = document.getElementById('city');
  const districtSel = document.getElementById('district');
  countrySel.innerHTML = '';
  state.locations.forEach((country) => {
    const option = document.createElement('option');
    option.value = country.country;
    option.textContent = country.country;
    countrySel.appendChild(option);
  });

  countrySel.addEventListener('change', () => updateCities());
  citySel.addEventListener('change', () => updateDistricts());
  updateCities();

  function updateCities() {
    const selected = state.locations.find((c) => c.country === countrySel.value);
    citySel.innerHTML = '';
    selected?.cities.forEach((city) => {
      const opt = document.createElement('option');
      opt.value = city.city;
      opt.textContent = city.city;
      citySel.appendChild(opt);
    });
    updateDistricts();
  }

  function updateDistricts() {
    const country = state.locations.find((c) => c.country === countrySel.value);
    const city = country?.cities.find((c) => c.city === citySel.value);
    districtSel.innerHTML = '';
    city?.districts.forEach((dist) => {
      const opt = document.createElement('option');
      opt.value = dist.name;
      opt.textContent = dist.name;
      districtSel.appendChild(opt);
    });
    const dist = city?.districts.find((d) => d.name === districtSel.value);
    if (dist) {
      document.getElementById('lat').value = dist.lat;
      document.getElementById('lon').value = dist.lon;
    }
  }
}

async function loadSettings() {
  const res = await fetch('/api/settings');
  if (!res.ok) return;
  const data = await res.json();
  state.settings = data.settings;
  state.offsets = data.offsets;
  const form = document.getElementById('location-form');
  if (form) {
    form.country.value = state.settings.country || form.country.value;
    form.city.value = state.settings.city || form.city.value;
    form.district.value = state.settings.district || form.district.value;
    form.lat.value = state.settings.lat || '';
    form.lon.value = state.settings.lon || '';
    form.method.value = state.settings.method || 'MuslimWorldLeague';
    form.madhab.value = state.settings.madhab || 'Shafi';
    form.hlr.value = state.settings.hlr || 'MiddleOfTheNight';
    form.timezone.value = state.settings.timezone || '';
  }
  const settingsForm = document.getElementById('settings-form');
  if (settingsForm) {
    settingsForm.master_volume.value = state.settings.master_volume || 80;
    settingsForm.fade_enabled.checked = state.settings.fade_enabled === 1;
    settingsForm.playback_mode.value = state.settings.playback_mode || 'stop';
    settingsForm.ramadan_offset.value = state.settings.ramadan_offset || 0;
    settingsForm['offsets[fajr]'].value = state.offsets.fajr || 0;
    settingsForm['offsets[dhuhr]'].value = state.offsets.dhuhr || 0;
    settingsForm['offsets[asr]'].value = state.offsets.asr || 0;
    settingsForm['offsets[maghrib]'].value = state.offsets.maghrib || 0;
    settingsForm['offsets[isha]'].value = state.offsets.isha || 0;
  }
}

async function loadTimes() {
  const res = await fetch('/api/times');
  if (!res.ok) return;
  const times = await res.json();
  const today = times[0];
  const list = document.getElementById('today-times');
  list.innerHTML = '';
  const labels = {
    fajr: 'Sabah',
    sunrise: 'Güneş',
    dhuhr: 'Öğle',
    asr: 'İkindi',
    maghrib: 'Akşam',
    isha: 'Yatsı'
  };
  Object.entries(labels).forEach(([key, label]) => {
    const li = document.createElement('li');
    li.textContent = `${label}: ${formatTime(today[key])}`;
    list.appendChild(li);
  });
  state.todayTimes = today;
  updateCountdown();
}

async function loadSchedule() {
  const res = await fetch('/api/schedule');
  if (!res.ok) return;
  const rows = await res.json();
  const body = document.getElementById('schedule-body');
  body.innerHTML = '';
  rows.forEach((row) => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td class="px-4 py-2">${row.date}</td>
      <td class="px-4 py-2">${formatTime(row.fajr)}</td>
      <td class="px-4 py-2">${formatTime(row.dhuhr)}</td>
      <td class="px-4 py-2">${formatTime(row.asr)}</td>
      <td class="px-4 py-2">${formatTime(row.maghrib)}</td>
      <td class="px-4 py-2">${formatTime(row.isha)}</td>`;
    body.appendChild(tr);
  });
}

async function loadLogs() {
  const res = await fetch('/api/logs');
  if (!res.ok) return;
  const rows = await res.json();
  const body = document.getElementById('logs-body');
  body.innerHTML = '';
  rows.forEach((row) => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td class="px-3 py-2">${formatTime(row.ts)}</td>
      <td class="px-3 py-2">${row.prayer}</td>
      <td class="px-3 py-2">${row.voice_set_id || '-'}</td>
      <td class="px-3 py-2">${row.result}</td>
      <td class="px-3 py-2">${row.message || ''}</td>`;
    body.appendChild(tr);
  });
}

function formatTime(ts) {
  const date = new Date(ts);
  return date.toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit' });
}

function updateCountdown() {
  if (!state.todayTimes) return;
  const now = new Date();
  const upcoming = ['fajr', 'dhuhr', 'asr', 'maghrib', 'isha']
    .map((key) => ({ key, time: new Date(state.todayTimes[key]) }))
    .find((item) => item.time > now);
  const el = document.getElementById('next-countdown');
  if (upcoming) {
    const diff = upcoming.time - now;
    const mins = Math.floor(diff / 60000);
    const secs = Math.floor((diff % 60000) / 1000);
    el.textContent = `Sıradaki ${upcoming.key.toUpperCase()} ${mins} dk ${secs} sn sonra`;
  } else {
    el.textContent = 'Bugün için vakit kalmadı';
  }
}

const testButtons = document.querySelectorAll('.test-btn');
testButtons.forEach((btn) =>
  btn.addEventListener('click', async () => {
    const prayer = btn.dataset.prayer;
    await fetch('/api/test-play', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ prayer })
    });
  })
);

document.getElementById('btn-stop')?.addEventListener('click', async () => {
  await fetch('/api/stop', { method: 'POST' });
});

document.getElementById('btn-snooze')?.addEventListener('click', async () => {
  await fetch('/api/snooze', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ minutes: 5 })
  });
});

const locationForm = document.getElementById('location-form');
locationForm?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const formData = new FormData(locationForm);
  const body = Object.fromEntries(formData.entries());
  body.offsets = state.offsets;
  await saveSettings(body);
});

const settingsForm = document.getElementById('settings-form');
settingsForm?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const formData = new FormData(settingsForm);
  const body = Object.fromEntries(formData.entries());
  body.fade_enabled = settingsForm.fade_enabled.checked;
  body.offsets = {
    fajr: Number(formData.get('offsets[fajr]')),
    dhuhr: Number(formData.get('offsets[dhuhr]')),
    asr: Number(formData.get('offsets[asr]')),
    maghrib: Number(formData.get('offsets[maghrib]')),
    isha: Number(formData.get('offsets[isha]'))
  };
  await saveSettings(body);
});

async function saveSettings(body) {
  const res = await fetch('/api/settings', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body)
  });
  if (res.ok) {
    await loadSettings();
    await loadTimes();
    await loadSchedule();
  } else {
    alert('Kaydetme hatası');
  }
}

const voiceForm = document.getElementById('voice-form');
voiceForm?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const formData = new FormData(voiceForm);
  const body = Object.fromEntries(formData.entries());
  const res = await fetch('/api/voices', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body)
  });
  if (res.ok) {
    voiceForm.reset();
    await loadVoices();
  }
});

async function loadVoices() {
  const res = await fetch('/api/voices');
  if (!res.ok) return;
  state.voices = await res.json();
  renderVoices();
  const active = state.voices.find((v) => v.is_active === 1);
  document.getElementById('active-voice').textContent = active ? `${active.name}` : 'Seçilmedi';
}

function renderVoices() {
  const container = document.getElementById('voice-list');
  container.innerHTML = '';
  state.voices.forEach((voice) => {
    const div = document.createElement('div');
    div.className = 'bg-white p-4 rounded shadow';
    div.innerHTML = `
      <div class="flex justify-between items-center mb-3">
        <h3 class="text-lg font-semibold">${voice.name}</h3>
        <div class="space-x-2">
          <button data-action="activate" data-id="${voice.id}" class="text-emerald-600 text-sm">Aktif Yap</button>
          <button data-action="delete" data-id="${voice.id}" class="text-red-600 text-sm">Sil</button>
        </div>
      </div>
      <div class="grid md:grid-cols-5 gap-3">
        ${['fajr', 'dhuhr', 'asr', 'maghrib', 'isha']
          .map(
            (prayer) => `
            <label class="text-sm">
              ${prayer.toUpperCase()}
              <input type="file" data-prayer="${prayer}" data-voice="${voice.id}" class="block mt-1 text-xs" />
            </label>`
          )
          .join('')}
      </div>`;
    container.appendChild(div);
  });

  container.querySelectorAll('input[type="file"]').forEach((input) => {
    input.addEventListener('change', async () => {
      const voiceId = input.dataset.voice;
      const prayer = input.dataset.prayer;
      const formData = new FormData();
      formData.append('file', input.files[0]);
      await fetch(`/api/voices/${voiceId}/upload?prayer=${prayer}`, {
        method: 'POST',
        body: formData
      });
    });
  });

  container.querySelectorAll('button[data-action="activate"]').forEach((btn) =>
    btn.addEventListener('click', async () => {
      await fetch('/api/active-voice', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ voice_set_id: Number(btn.dataset.id) })
      });
      await loadVoices();
    })
  );

  container.querySelectorAll('button[data-action="delete"]').forEach((btn) =>
    btn.addEventListener('click', async () => {
      await fetch(`/api/voices/${btn.dataset.id}`, { method: 'DELETE' });
      await loadVoices();
    })
  );
}
