const STORAGE_KEY = 'dp_data_v1';
const PASSWORD_KEY = 'dp_admin_password';
const SESSION_KEY = 'dp_admin_session';
const STORAGE_LIMIT = 5 * 1024 * 1024; // yaklaşık 5MB
const WEEKDAYS = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];

const encoder = new TextEncoder();

const state = {
  data: loadData(),
  passwordHash: localStorage.getItem(PASSWORD_KEY) || null,
};

const elements = {
  navButtons: Array.from(document.querySelectorAll('.nav-button')),
  sections: Array.from(document.querySelectorAll('.panel-section')),
  classForm: document.getElementById('class-form'),
  classList: document.getElementById('class-list'),
  teacherForm: document.getElementById('teacher-form'),
  teacherList: document.getElementById('teacher-list'),
  lessonForm: document.getElementById('lesson-form'),
  lessonList: document.getElementById('lesson-list'),
  slotForm: document.getElementById('slot-form'),
  slotList: document.getElementById('slot-list'),
  scheduleClass: document.getElementById('schedule-class'),
  scheduleDay: document.getElementById('schedule-day'),
  scheduleList: document.getElementById('schedule-list'),
  exportButton: document.getElementById('export-button'),
  importFile: document.getElementById('import-file'),
  storageStats: document.getElementById('storage-stats'),
  logoutButton: document.getElementById('logout-button'),
  loginOverlay: document.getElementById('login-overlay'),
  loginMessage: document.getElementById('login-message'),
  loginForm: document.getElementById('login-form'),
  resetPassword: document.getElementById('reset-password'),
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}

function init() {
  bindNavigation();
  bindForms();
  bindScheduleControls();
  bindStorageActions();
  setupAuthentication();
  ensureScheduleStructure();
  renderAll();
}

function setupAuthentication() {
  const session = sessionStorage.getItem(SESSION_KEY);
  const hasPassword = Boolean(state.passwordHash);

  if (!session) {
    showLoginOverlay(hasPassword);
  }

  elements.loginForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const formData = new FormData(event.target);
    const password = (formData.get('password') || '').trim();

    if (!password) {
      setLoginMessage('Şifre boş olamaz.', true);
      return;
    }

    if (!state.passwordHash) {
      state.passwordHash = await hashPassword(password);
      localStorage.setItem(PASSWORD_KEY, state.passwordHash);
      sessionStorage.setItem(SESSION_KEY, '1');
      hideLoginOverlay();
      alert('Şifreniz oluşturuldu.');
      return;
    }

    const attemptHash = await hashPassword(password);
    if (attemptHash === state.passwordHash) {
      sessionStorage.setItem(SESSION_KEY, '1');
      hideLoginOverlay();
      event.target.reset();
    } else {
      setLoginMessage('Şifre yanlış. Lütfen tekrar deneyin.', true);
    }
  });

  elements.logoutButton.addEventListener('click', () => {
    lockPanel();
  });

  elements.resetPassword.addEventListener('click', async () => {
    if (!state.passwordHash) {
      setLoginMessage('Önce bir şifre belirleyin.', true);
      return;
    }

    const current = prompt('Mevcut şifreyi girin:');
    if (!current) {
      return;
    }

    const currentHash = await hashPassword(current);
    if (currentHash !== state.passwordHash) {
      alert('Şifre doğrulanamadı.');
      return;
    }

    const next = prompt('Yeni şifrenizi girin:');
    if (!next) {
      return;
    }

    state.passwordHash = await hashPassword(next);
    localStorage.setItem(PASSWORD_KEY, state.passwordHash);
    alert('Şifre güncellendi.');
    lockPanel();
  });
}

function showLoginOverlay(hasPassword) {
  elements.loginOverlay.classList.remove('hidden');
  elements.loginMessage.textContent = hasPassword
    ? 'Yönetim paneline erişmek için şifrenizi girin.'
    : 'İlk kez kullanıyorsunuz, lütfen yönetici şifresi oluşturun.';
}

function hideLoginOverlay() {
  elements.loginOverlay.classList.add('hidden');
  elements.loginForm.reset();
  setLoginMessage('');
}

function setLoginMessage(message, isError = false) {
  elements.loginMessage.textContent = message;
  elements.loginMessage.style.color = isError ? '#ff4d4f' : 'var(--gray-600)';
}

function lockPanel() {
  sessionStorage.removeItem(SESSION_KEY);
  showLoginOverlay(true);
}

async function hashPassword(password) {
  if (window.crypto?.subtle) {
    const encoded = encoder.encode(password);
    const digest = await window.crypto.subtle.digest('SHA-256', encoded);
    return Array.from(new Uint8Array(digest)).map((b) => b.toString(16).padStart(2, '0')).join('');
  }

  // Basit yedek hash (güvenlik amaçlı değildir)
  let hash = 0;
  for (let i = 0; i < password.length; i += 1) {
    hash = (hash << 5) - hash + password.charCodeAt(i);
    hash |= 0;
  }
  return hash.toString(16);
}

function bindNavigation() {
  elements.navButtons.forEach((button) => {
    button.addEventListener('click', () => {
      elements.navButtons.forEach((btn) => btn.classList.toggle('active', btn === button));
      const targetId = button.dataset.section;
      elements.sections.forEach((section) => {
        section.classList.toggle('hidden', section.id !== targetId);
      });
    });
  });
}

function bindForms() {
  setupForm(elements.classForm, {
    onSubmit: ({ id, name }) => {
      if (id) {
        const item = state.data.classes.find((cls) => cls.id === id);
        if (item) item.name = name;
      } else {
        state.data.classes.push({ id: generateId(), name });
      }
      ensureScheduleStructure();
    },
    onReset: () => {
      elements.classForm.reset();
    },
  });

  elements.classList.addEventListener('click', (event) => handleTableActions(event, 'classes'));

  setupForm(elements.teacherForm, {
    onSubmit: ({ id, name }) => {
      if (id) {
        const item = state.data.teachers.find((t) => t.id === id);
        if (item) item.name = name;
      } else {
        state.data.teachers.push({ id: generateId(), name });
      }
    },
    onReset: () => {
      elements.teacherForm.reset();
    },
  });

  elements.teacherList.addEventListener('click', (event) => handleTableActions(event, 'teachers'));

  setupForm(elements.lessonForm, {
    onSubmit: ({ id, name, teacherId }) => {
      if (id) {
        const item = state.data.lessons.find((lesson) => lesson.id === id);
        if (item) {
          item.name = name;
          item.teacherId = teacherId;
        }
      } else {
        state.data.lessons.push({ id: generateId(), name, teacherId });
      }
    },
    onReset: () => {
      elements.lessonForm.reset();
      refreshLessonTeacherOptions();
    },
  });

  elements.lessonList.addEventListener('click', (event) => handleTableActions(event, 'lessons'));

  setupForm(elements.slotForm, {
    onSubmit: ({ id, name, type, start, end }) => {
      if (timeToMinutes(start) >= timeToMinutes(end)) {
        alert('Başlangıç saati bitişten sonra olamaz.');
        return false;
      }

      if (id) {
        const item = state.data.slots.find((slot) => slot.id === id);
        if (item) {
          const previousType = item.type;
          Object.assign(item, { name, type, start, end });
          if (type === 'break' && previousType !== 'break') {
            clearSlotAssignments(id);
          }
        }
      } else {
        state.data.slots.push({ id: generateId(), name, type, start, end });
      }
      ensureScheduleStructure();
      return true;
    },
    onReset: () => {
      elements.slotForm.reset();
      elements.slotForm.type.value = 'lesson';
    },
  });

  elements.slotList.addEventListener('click', (event) => handleTableActions(event, 'slots'));
}

function bindScheduleControls() {
  elements.scheduleClass.addEventListener('change', () => renderScheduleEditor());
  elements.scheduleDay.addEventListener('change', () => renderScheduleEditor());
}

function bindStorageActions() {
  elements.exportButton.addEventListener('click', exportData);
  elements.importFile.addEventListener('change', importData);
}

function setupForm(form, { onSubmit, onReset }) {
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    const formData = new FormData(form);
    const payload = Object.fromEntries(formData.entries());
    payload.name = payload.name?.trim();

    const result = onSubmit(payload);
    if (result === false) {
      return;
    }
    saveData();
    form.reset();
    renderAll();
  });

  form.querySelector('[data-action="reset"]').addEventListener('click', () => {
    form.reset();
    onReset?.();
  });
}

function handleTableActions(event, type) {
  const button = event.target.closest('button');
  if (!button) return;

  const row = button.closest('tr');
  const id = row?.dataset.id;
  if (!id) return;

  if (button.dataset.action === 'edit') {
    switch (type) {
      case 'classes':
        fillForm(elements.classForm, state.data.classes.find((item) => item.id === id));
        break;
      case 'teachers':
        fillForm(elements.teacherForm, state.data.teachers.find((item) => item.id === id));
        break;
      case 'lessons':
        fillForm(elements.lessonForm, state.data.lessons.find((item) => item.id === id));
        refreshLessonTeacherOptions();
        break;
      case 'slots':
        fillForm(elements.slotForm, state.data.slots.find((item) => item.id === id));
        break;
      default:
        break;
    }
    return;
  }

  if (button.dataset.action === 'delete') {
    if (!confirm('Silmek istediğinizden emin misiniz?')) return;

    switch (type) {
      case 'classes':
        deleteClass(id);
        break;
      case 'teachers':
        deleteTeacher(id);
        break;
      case 'lessons':
        deleteLesson(id);
        break;
      case 'slots':
        deleteSlot(id);
        break;
      default:
        break;
    }
    saveData();
    renderAll();
  }
}

function fillForm(form, data) {
  if (!data) return;
  Object.entries(data).forEach(([key, value]) => {
    if (form.elements[key]) {
      form.elements[key].value = value;
    }
  });
}

function deleteClass(id) {
  state.data.classes = state.data.classes.filter((item) => item.id !== id);
  delete state.data.schedules[id];
}

function deleteTeacher(id) {
  state.data.teachers = state.data.teachers.filter((item) => item.id !== id);
  state.data.lessons = state.data.lessons.filter((lesson) => lesson.teacherId !== id);
  Object.values(state.data.schedules).forEach((days) => {
    Object.values(days).forEach((items) => {
      items.forEach((item) => {
        if (item.lessonId && !state.data.lessons.find((lesson) => lesson.id === item.lessonId)) {
          item.lessonId = '';
        }
      });
    });
  });
}

function deleteLesson(id) {
  state.data.lessons = state.data.lessons.filter((item) => item.id !== id);
  Object.values(state.data.schedules).forEach((days) => {
    Object.values(days).forEach((items) => {
      items.forEach((item) => {
        if (item.lessonId === id) {
          item.lessonId = '';
        }
      });
    });
  });
}

function deleteSlot(id) {
  state.data.slots = state.data.slots.filter((item) => item.id !== id);
  Object.values(state.data.schedules).forEach((days) => {
    Object.keys(days).forEach((dayKey) => {
      days[dayKey] = days[dayKey].filter((item) => item.slotId !== id);
    });
  });
}

function clearSlotAssignments(slotId) {
  Object.values(state.data.schedules).forEach((days) => {
    Object.values(days).forEach((items) => {
      items.forEach((item) => {
        if (item.slotId === slotId) {
          item.lessonId = '';
        }
      });
    });
  });
}

function ensureScheduleStructure() {
  const classIds = state.data.classes.map((cls) => cls.id);
  state.data.schedules ||= {};

  classIds.forEach((classId) => {
    state.data.schedules[classId] ||= {};
    for (let day = 0; day < 7; day += 1) {
      const key = String(day);
      const slots = getSortedSlots();
      const existing = state.data.schedules[classId][key] || [];
      const merged = slots.map((slot) => {
        const found = existing.find((item) => item.slotId === slot.id);
        return {
          slotId: slot.id,
          lessonId: found?.lessonId || '',
        };
      });
      state.data.schedules[classId][key] = merged;
    }
  });

  Object.keys(state.data.schedules).forEach((classId) => {
    if (!classIds.includes(classId)) {
      delete state.data.schedules[classId];
    }
  });
}

function renderAll() {
  refreshLessonTeacherOptions();
  renderClasses();
  renderTeachers();
  renderLessons();
  renderSlots();
  renderScheduleControls();
  renderScheduleEditor();
  updateStorageStats();
}

function renderClasses() {
  const fragment = document.createDocumentFragment();
  state.data.classes.forEach((item) => {
    const tr = document.createElement('tr');
    tr.dataset.id = item.id;
    tr.innerHTML = `
      <td>${escapeHtml(item.name)}</td>
      <td></td>
    `;
    const cell = tr.querySelector('td:last-child');
    cell.append(createActionButtons());
    fragment.append(tr);
  });
  elements.classList.replaceChildren(fragment);
}

function renderTeachers() {
  const fragment = document.createDocumentFragment();
  state.data.teachers.forEach((item) => {
    const tr = document.createElement('tr');
    tr.dataset.id = item.id;
    tr.innerHTML = `
      <td>${escapeHtml(item.name)}</td>
      <td></td>
    `;
    tr.querySelector('td:last-child').append(createActionButtons());
    fragment.append(tr);
  });
  elements.teacherList.replaceChildren(fragment);
}

function renderLessons() {
  const teacherMap = new Map(state.data.teachers.map((teacher) => [teacher.id, teacher.name]));
  const fragment = document.createDocumentFragment();
  state.data.lessons.forEach((item) => {
    const tr = document.createElement('tr');
    tr.dataset.id = item.id;
    const teacherName = teacherMap.get(item.teacherId) || '—';
    tr.innerHTML = `
      <td>${escapeHtml(item.name)}</td>
      <td>${escapeHtml(teacherName)}</td>
      <td></td>
    `;
    tr.querySelector('td:last-child').append(createActionButtons());
    fragment.append(tr);
  });
  elements.lessonList.replaceChildren(fragment);
}

function renderSlots() {
  const fragment = document.createDocumentFragment();
  getSortedSlots().forEach((item) => {
    const tr = document.createElement('tr');
    tr.dataset.id = item.id;
    tr.innerHTML = `
      <td>${escapeHtml(item.name)}</td>
      <td>${item.type === 'break' ? 'Teneffüs' : 'Ders'}</td>
      <td>${item.start}</td>
      <td>${item.end}</td>
      <td></td>
    `;
    tr.querySelector('td:last-child').append(createActionButtons());
    fragment.append(tr);
  });
  elements.slotList.replaceChildren(fragment);
}

function renderScheduleControls() {
  const previousClass = elements.scheduleClass.value;
  const classOptions = state.data.classes.map((cls) => `<option value="${cls.id}">${escapeHtml(cls.name)}</option>`).join('');
  elements.scheduleClass.innerHTML = classOptions || '<option value="">Sınıf bulunamadı</option>';

  if (previousClass && state.data.classes.some((cls) => cls.id === previousClass)) {
    elements.scheduleClass.value = previousClass;
  } else if (state.data.classes.length) {
    elements.scheduleClass.value = state.data.classes[0].id;
  }

  const previousDay = elements.scheduleDay.value;
  const dayOptions = WEEKDAYS.map((day, index) => `<option value="${index}">${day}</option>`).join('');
  elements.scheduleDay.innerHTML = dayOptions;

  if (previousDay) {
    elements.scheduleDay.value = previousDay;
  }

  if (!elements.scheduleDay.value) {
    const now = getIstanbulDayIndex();
    elements.scheduleDay.value = String(now);
  }
}

function renderScheduleEditor() {
  const classId = elements.scheduleClass.value;
  const day = elements.scheduleDay.value;
  const slots = getSortedSlots();

  if (!classId || !slots.length) {
    elements.scheduleList.innerHTML = '<tr><td colspan="2">Önce sınıf ve slot ekleyin.</td></tr>';
    return;
  }

  ensureScheduleStructure();
  const entries = state.data.schedules?.[classId]?.[day] || [];
  const lessonMap = new Map(state.data.lessons.map((lesson) => [lesson.id, lesson]));
  const teacherMap = new Map(state.data.teachers.map((teacher) => [teacher.id, teacher]));

  const fragment = document.createDocumentFragment();

  slots.forEach((slot) => {
    const entry = entries.find((item) => item.slotId === slot.id) || { slotId: slot.id, lessonId: '' };
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>
        <div>
          <strong>${escapeHtml(slot.name)}</strong>
          <div>${slot.start} – ${slot.end}</div>
          <div class="badge">${slot.type === 'break' ? 'Teneffüs' : 'Ders'}</div>
        </div>
      </td>
      <td></td>
    `;

    const contentCell = tr.querySelector('td:last-child');

    if (slot.type === 'break') {
      contentCell.textContent = 'Teneffüs';
    } else {
      const container = document.createElement('div');
      container.className = 'schedule-item';
      const select = document.createElement('select');
      select.dataset.slotId = slot.id;
      select.innerHTML = ['<option value="">— Boş —</option>', ...state.data.lessons.map((lesson) => {
        const teacher = teacherMap.get(lesson.teacherId);
        const teacherLabel = teacher ? ` (${escapeHtml(teacher.name)})` : '';
        return `<option value="${lesson.id}">${escapeHtml(lesson.name)}${teacherLabel}</option>`;
      })].join('');
      select.value = entry.lessonId || '';
      select.addEventListener('change', () => {
        entry.lessonId = select.value;
        const list = state.data.schedules[classId][day];
        const index = list.findIndex((item) => item.slotId === slot.id);
        if (index >= 0) {
          list[index].lessonId = entry.lessonId;
        }
        saveData();
      });
      container.append(select);

      if (entry.lessonId) {
        const lesson = lessonMap.get(entry.lessonId);
        if (lesson) {
          const teacher = teacherMap.get(lesson.teacherId);
          const info = document.createElement('small');
          info.textContent = teacher ? `Öğretmen: ${teacher.name}` : '';
          container.append(info);
        }
      }

      contentCell.append(container);
    }

    fragment.append(tr);
  });

  elements.scheduleList.replaceChildren(fragment);
}

function refreshLessonTeacherOptions() {
  const select = elements.lessonForm.querySelector('select[name="teacherId"]');
  select.innerHTML = state.data.teachers.length
    ? state.data.teachers.map((teacher) => `<option value="${teacher.id}">${escapeHtml(teacher.name)}</option>`).join('')
    : '<option value="">Önce öğretmen ekleyin</option>';
}

function createActionButtons() {
  const template = document.getElementById('action-buttons-template');
  return template.content.cloneNode(true);
}

function saveData() {
  ensureScheduleStructure();
  localStorage.setItem(STORAGE_KEY, JSON.stringify(state.data));
  checkStorageUsage();
}

function loadData() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) {
      return {
        classes: [],
        teachers: [],
        lessons: [],
        slots: [],
        schedules: {},
      };
    }
    const parsed = JSON.parse(raw);
    return {
      classes: parsed.classes || [],
      teachers: parsed.teachers || [],
      lessons: parsed.lessons || [],
      slots: parsed.slots || [],
      schedules: parsed.schedules || {},
    };
  } catch (error) {
    console.error('Veri okunurken hata oluştu:', error);
    return {
      classes: [],
      teachers: [],
      lessons: [],
      slots: [],
      schedules: {},
    };
  }
}

function exportData() {
  const blob = new Blob([JSON.stringify(state.data, null, 2)], { type: 'application/json' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  const now = new Date();
  const stamp = now.toISOString().split('T')[0];
  link.download = `ders-programi-${stamp}.json`;
  document.body.append(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

function importData(event) {
  const file = event.target.files?.[0];
  if (!file) return;

  const reader = new FileReader();
  reader.onload = () => {
    try {
      const imported = JSON.parse(reader.result);
      validateImportedData(imported);
      state.data = {
        classes: imported.classes || [],
        teachers: imported.teachers || [],
        lessons: imported.lessons || [],
        slots: imported.slots || [],
        schedules: imported.schedules || {},
      };
      ensureScheduleStructure();
      saveData();
      renderAll();
      alert('Veriler başarıyla içe aktarıldı.');
    } catch (error) {
      alert('Geçersiz JSON dosyası: ' + error.message);
    } finally {
      event.target.value = '';
    }
  };
  reader.readAsText(file, 'utf-8');
}

function validateImportedData(data) {
  if (typeof data !== 'object' || data === null) {
    throw new Error('Veri nesnesi bekleniyor.');
  }
}

function updateStorageStats() {
  const json = JSON.stringify(state.data);
  const sizeBytes = encoder.encode(json).length;
  const percent = Math.min(100, (sizeBytes / STORAGE_LIMIT) * 100);
  const lines = [
    `<strong>Tahmini kullanım:</strong> ${(sizeBytes / 1024).toFixed(2)} KB`,
    `<strong>Tahmini doluluk:</strong> %${percent.toFixed(1)} (limit ~${(STORAGE_LIMIT / (1024 * 1024)).toFixed(1)} MB)`,
  ];

  if (percent > 80) {
    lines.push('<span style="color:#ff4d4f; font-weight:600;">Uyarı: Depolama sınırına yaklaşıyorsunuz.</span>');
  }

  elements.storageStats.innerHTML = lines.join('<br>');
}

function checkStorageUsage() {
  const json = JSON.stringify(state.data);
  const sizeBytes = encoder.encode(json).length;
  if (sizeBytes > STORAGE_LIMIT * 0.9) {
    alert('Uyarı: localStorage kullanımınız sınırın %90\'ına ulaştı. Lütfen gereksiz verileri temizleyin veya yedek alın.');
  }
}

function getSortedSlots() {
  return [...state.data.slots].sort((a, b) => timeToMinutes(a.start) - timeToMinutes(b.start));
}

function timeToMinutes(time) {
  const [hour, minute] = time.split(':').map(Number);
  return hour * 60 + minute;
}

function generateId() {
  return crypto.randomUUID ? crypto.randomUUID() : `id-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function escapeHtml(value) {
  return value?.replace(/[&<>"]+/g, (match) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
  })[match]) || '';
}

function getIstanbulDayIndex() {
  const formatter = new Intl.DateTimeFormat('tr-TR', { timeZone: 'Europe/Istanbul', weekday: 'long' });
  const weekday = formatter.format(new Date());
  return WEEKDAYS.indexOf(weekday);
}
