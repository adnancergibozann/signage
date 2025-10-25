(function () {
  const STORAGE_KEY = 'dp_data_v1';
  const TIMEZONE = 'Europe/Istanbul';
  const WEEKDAYS = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];
  const STYLE_ID = 'dp-widget-styles';

  init();

  function init() {
    injectStyles();
    renderWidgets();
    window.addEventListener('storage', (event) => {
      if (event.key === STORAGE_KEY) {
        renderWidgets();
      }
    });
  }

  function renderWidgets() {
    const countdownContainers = document.querySelectorAll('[data-widget="countdown"]');
    const scheduleContainers = document.querySelectorAll('[data-widget="schedule"]');

    countdownContainers.forEach((el) => setupCountdownWidget(el));
    scheduleContainers.forEach((el) => setupScheduleWidget(el));
  }

  function setupCountdownWidget(container) {
    container.classList.add('dp-widget-root');
    container.innerHTML = '<div class="dp-widget-card"><div class="dp-widget-title">Geri Sayım</div><div class="dp-widget-content dp-countdown"><p>Yükleniyor…</p></div></div>';

    const content = container.querySelector('.dp-countdown');
    const timerId = container.dataset.timerId;
    if (timerId) {
      clearInterval(Number(timerId));
    }

    const update = () => {
      const data = loadData();
      const classInfo = resolveClass(container, data);
      if (!classInfo) {
        content.innerHTML = '<p>Henüz sınıf tanımlanmadı.</p>';
        return;
      }

      const schedule = getScheduleForClass(data, classInfo.id);
      const slots = getSortedSlots(data.slots);
      if (!slots.length) {
        content.innerHTML = '<p>Slot bilgisi bulunmuyor.</p>';
        return;
      }

      const now = getZonedTime();
      const todayEntries = schedule[String(now.dayIndex)] || [];
      const lessonMap = new Map(data.lessons.map((lesson) => [lesson.id, lesson]));
      const teacherMap = new Map(data.teachers.map((teacher) => [teacher.id, teacher]));
      const merged = mergeSlotsWithEntries(slots, todayEntries, { lessonMap, teacherMap });

      const status = calculateCurrentStatus(merged, now);
      content.innerHTML = renderCountdownContent(status, classInfo.name);
    };

    update();
    const intervalId = window.setInterval(update, 1000);
    container.dataset.timerId = String(intervalId);
  }

  function renderCountdownContent(status, className) {
    if (!status) {
      return '<p>Bugün için program bulunamadı.</p>';
    }

    const lines = [`<div class="dp-widget-subtitle">${escapeHtml(className)}</div>`];

    if (status.state === 'before-day') {
      lines.push(`<p>Günün dersleri henüz başlamadı.</p>`);
      if (status.next) {
        lines.push(`<p>İlk ders: <strong>${escapeHtml(status.next.label)}</strong> (${status.next.range})</p>`);
        lines.push(`<div class="dp-countdown-timer">${formatDuration(status.next.diffSeconds)}</div>`);
      }
    } else if (status.state === 'after-day') {
      lines.push('<p>Bugünkü dersler tamamlandı.</p>');
    } else if (status.state === 'in-slot') {
      const label = status.current.type === 'break' ? 'Şu an teneffüstesiniz.' : `Şu an <strong>${escapeHtml(status.current.label)}</strong> dersindesiniz.`;
      lines.push(`<p>${label}</p>`);
      if (status.current.teacher) {
        lines.push(`<p>Öğretmen: ${escapeHtml(status.current.teacher)}</p>`);
      }
      lines.push(`<div class="dp-countdown-timer">${formatDuration(status.current.diffSeconds)}</div>`);
      if (status.next && status.current.type !== 'break') {
        lines.push(`<p>Sıradaki: ${escapeHtml(status.next.label)} (${status.next.range})</p>`);
      }
    } else if (status.state === 'between') {
      lines.push(`<p>Şu an teneffüstesiniz.</p>`);
      if (status.next) {
        lines.push(`<p>Sonraki ders: <strong>${escapeHtml(status.next.label)}</strong> (${status.next.range})</p>`);
        lines.push(`<div class="dp-countdown-timer">${formatDuration(status.next.diffSeconds)}</div>`);
      }
    }

    lines.push(`<div class="dp-widget-footer">${formatDateLabel()}</div>`);
    return lines.join('');
  }

  function setupScheduleWidget(container) {
    container.classList.add('dp-widget-root');
    const view = (container.dataset.view || 'daily').split(',').map((part) => part.trim().toLowerCase()).filter(Boolean);
    container.innerHTML = '<div class="dp-widget-card"><div class="dp-widget-title">Ders Programı</div><div class="dp-widget-content"></div></div>';
    const content = container.querySelector('.dp-widget-content');

    const render = () => {
      const data = loadData();
      const classInfo = resolveClass(container, data);
      if (!classInfo) {
        content.innerHTML = '<p>Henüz sınıf tanımlanmadı.</p>';
        return;
      }

      const schedule = getScheduleForClass(data, classInfo.id);
      const slots = getSortedSlots(data.slots);
      if (!slots.length) {
        content.innerHTML = '<p>Slot bilgisi bulunmuyor.</p>';
        return;
      }

      const lessonMap = new Map(data.lessons.map((lesson) => [lesson.id, lesson]));
      const teacherMap = new Map(data.teachers.map((teacher) => [teacher.id, teacher]));
      const now = getZonedTime();

      const parts = [`<div class="dp-widget-subtitle">${escapeHtml(classInfo.name)}</div>`];
      const effectiveView = view.length ? view : ['daily'];

      if (effectiveView.includes('daily')) {
        const todayEntries = mergeSlotsWithEntries(slots, schedule[String(now.dayIndex)] || [], { lessonMap, teacherMap });
        parts.push(renderScheduleDay(todayEntries, now.dayIndex));
      }

      if (effectiveView.includes('weekly')) {
        parts.push('<div class="dp-weekly">');
        for (let day = 1; day <= 5; day += 1) {
          const entries = mergeSlotsWithEntries(slots, schedule[String(day)] || [], { lessonMap, teacherMap });
          parts.push(renderScheduleDay(entries, day, true));
        }
        parts.push('</div>');
      }

      content.innerHTML = parts.join('');
    };

    render();
    container.dataset.renderListener ||= '';
    container.dpRenderInterval && clearInterval(container.dpRenderInterval);
    container.dpRenderInterval = setInterval(render, 60 * 1000);
  }

  function renderScheduleDay(entries, dayIndex, compact = false) {
    const dayName = WEEKDAYS[dayIndex] || '';
    const rows = entries.map((entry) => {
      if (!entry.slot) {
        return '';
      }
      if (entry.slot.type === 'break') {
        return `
          <div class="dp-schedule-row dp-break">
            <div>
              <div class="dp-time">${entry.slot.start} – ${entry.slot.end}</div>
              <div class="dp-lesson">${escapeHtml(entry.slot.name)}</div>
            </div>
            <div class="dp-teacher">Teneffüs</div>
          </div>`;
      }
      const lessonName = entry.lesson ? entry.lesson.name : '— Boş —';
      const teacherName = entry.teacher ? entry.teacher.name : '';
      return `
        <div class="dp-schedule-row">
          <div>
            <div class="dp-time">${entry.slot.start} – ${entry.slot.end}</div>
            <div class="dp-lesson">${escapeHtml(lessonName)}</div>
          </div>
          <div class="dp-teacher">${escapeHtml(teacherName)}</div>
        </div>`;
    }).join('');

    return `
      <section class="dp-schedule-day ${compact ? 'compact' : ''}">
        <header>${escapeHtml(dayName)}</header>
        ${rows || '<p class="dp-empty">Program bulunamadı.</p>'}
      </section>
    `;
  }

  function calculateCurrentStatus(entries, now) {
    if (!entries.length) {
      return null;
    }

    const currentSeconds = now.hour * 3600 + now.minute * 60 + now.second;
    let current = null;
    let next = null;

    for (const entry of entries) {
      if (!entry.slot) continue;
      const start = timeToSeconds(entry.slot.start);
      const end = timeToSeconds(entry.slot.end);

      if (currentSeconds >= start && currentSeconds < end) {
        current = buildStatusEntry(entry, end - currentSeconds);
      } else if (currentSeconds < start) {
        next = next || buildStatusEntry(entry, start - currentSeconds);
        break;
      }
    }

    if (current) {
      next = next || findNext(entries, (entry) => timeToSeconds(entry.slot.start) > timeToSeconds(current.slot.start), currentSeconds);
      return { state: 'in-slot', current, next };
    }

    if (!next) {
      const lastSlot = entries[entries.length - 1];
      const endSeconds = lastSlot?.slot ? timeToSeconds(lastSlot.slot.end) : 0;
      if (currentSeconds >= endSeconds) {
        return { state: 'after-day' };
      }
      next = findNext(entries, () => true, currentSeconds);
    }

    if (currentSeconds < timeToSeconds(entries[0].slot.start)) {
      return { state: 'before-day', next };
    }

    return { state: 'between', next };
  }

  function buildStatusEntry(entry, diffSeconds) {
    const lesson = entry.lesson;
    const teacher = entry.teacher;
    return {
      label: entry.slot.type === 'break' ? entry.slot.name : lesson?.name || entry.slot.name,
      type: entry.slot.type,
      teacher: teacher?.name || '',
      range: `${entry.slot.start} – ${entry.slot.end}`,
      diffSeconds,
      slot: entry.slot,
    };
  }

  function findNext(entries, predicate, currentSeconds) {
    for (const entry of entries) {
      if (!entry.slot) continue;
      if (predicate(entry)) {
        const startSeconds = timeToSeconds(entry.slot.start);
        const baseSeconds = typeof currentSeconds === 'number' ? currentSeconds : (() => {
          const zoned = getZonedTime();
          return zoned.hour * 3600 + zoned.minute * 60 + zoned.second;
        })();
        return buildStatusEntry(entry, Math.max(startSeconds - baseSeconds, 0));
      }
    }
    return null;
  }

  function mergeSlotsWithEntries(slots, entries, { lessonMap, teacherMap } = {}) {
    const lessonLookup = new Map(entries.map((item) => [item.slotId, item.lessonId]));
    return slots.map((slot) => {
      const lessonId = lessonLookup.get(slot.id) || '';
      const lesson = lessonMap ? lessonMap.get(lessonId) || null : null;
      const teacher = teacherMap && lesson ? teacherMap.get(lesson.teacherId) || null : null;
      return {
        slot,
        lessonId,
        lesson,
        teacher,
      };
    });
  }

  function getScheduleForClass(data, classId) {
    return data.schedules?.[classId] || {};
  }

  function getSortedSlots(slots) {
    return [...slots].sort((a, b) => timeToSeconds(a.start) - timeToSeconds(b.start));
  }

  function resolveClass(element, data) {
    const identifier = element.dataset.class || element.dataset.classId;
    if (!data.classes.length) {
      return null;
    }

    if (identifier) {
      const match = data.classes.find((cls) => cls.id === identifier || cls.name === identifier);
      if (match) {
        return match;
      }
    }

    return data.classes[0];
  }

  function loadData() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) {
        return { classes: [], teachers: [], lessons: [], slots: [], schedules: {} };
      }
      const parsed = JSON.parse(raw);
      parsed.lessons = parsed.lessons || [];
      parsed.teachers = parsed.teachers || [];
      parsed.classes = parsed.classes || [];
      parsed.slots = parsed.slots || [];
      parsed.schedules = parsed.schedules || {};

      const teacherMap = new Map(parsed.teachers.map((teacher) => [teacher.id, teacher]));
      parsed.lessons.forEach((lesson) => {
        lesson.teacher = teacherMap.get(lesson.teacherId) || null;
      });

      return parsed;
    } catch (error) {
      console.error('Widget verisi okunamadı', error);
      return { classes: [], teachers: [], lessons: [], slots: [], schedules: {} };
    }
  }

  function getZonedTime() {
    const now = new Date();
    const formatter = new Intl.DateTimeFormat('tr-TR', {
      timeZone: TIMEZONE,
      hour12: false,
      weekday: 'long',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
    });
    const parts = formatter.formatToParts(now);
    const lookup = Object.fromEntries(parts.map((part) => [part.type, part.value]));
    let dayIndex = WEEKDAYS.indexOf(lookup.weekday);
    if (dayIndex === -1) {
      dayIndex = new Date().getDay();
    }
    return {
      dayIndex,
      hour: Number(lookup.hour),
      minute: Number(lookup.minute),
      second: Number(lookup.second),
    };
  }

  function formatDateLabel() {
    const formatter = new Intl.DateTimeFormat('tr-TR', {
      timeZone: TIMEZONE,
      dateStyle: 'full',
    });
    return formatter.format(new Date());
  }

  function formatDuration(totalSeconds) {
    const seconds = Math.max(0, Math.floor(totalSeconds));
    const hrs = Math.floor(seconds / 3600);
    const mins = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;
    if (hrs > 0) {
      return `${String(hrs).padStart(2, '0')}:${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
    }
    return `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
  }

  function timeToSeconds(time) {
    const [hour, minute] = time.split(':').map(Number);
    return hour * 3600 + minute * 60;
  }

  function injectStyles() {
    if (document.getElementById(STYLE_ID)) {
      return;
    }
    const style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent = `
      .dp-widget-root { font-family: "Segoe UI", system-ui, sans-serif; color: #0a0a0a; }
      .dp-widget-card { background: #ffffff; border: 2px solid #ffd400; border-radius: 16px; padding: 16px 20px; max-width: 420px; box-shadow: 0 10px 25px rgba(10,10,10,0.12); }
      .dp-widget-title { font-size: 1.2rem; font-weight: 700; margin-bottom: 4px; }
      .dp-widget-subtitle { font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; font-size: 0.85rem; color: #666666; margin-bottom: 12px; }
      .dp-widget-content p { margin: 0 0 8px; }
      .dp-countdown-timer { font-size: 2rem; font-weight: 700; margin: 12px 0; }
      .dp-widget-footer { margin-top: 16px; font-size: 0.8rem; color: #666666; }
      .dp-schedule-day { border: 1px solid rgba(10,10,10,0.08); border-radius: 12px; padding: 12px; margin-bottom: 16px; }
      .dp-schedule-day header { font-weight: 700; margin-bottom: 8px; color: #0a0a0a; }
      .dp-schedule-row { display: flex; justify-content: space-between; gap: 12px; padding: 8px 0; border-top: 1px dashed rgba(10,10,10,0.1); }
      .dp-schedule-row:first-of-type { border-top: none; }
      .dp-schedule-row.dp-break { color: #666666; }
      .dp-time { font-size: 0.85rem; color: #666666; }
      .dp-lesson { font-size: 1rem; font-weight: 600; }
      .dp-teacher { font-size: 0.85rem; color: #666666; display: flex; align-items: center; }
      .dp-empty { margin: 0; color: #666666; font-style: italic; }
      .dp-weekly { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; }
      .dp-schedule-day.compact { margin: 0; }
    `;
    document.head.append(style);
  }

  function escapeHtml(value) {
    return value ? value.replace(/[&<>\"]/g, (match) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
    })[match]) : '';
  }
})();
