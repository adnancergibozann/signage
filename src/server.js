import './utils/db.js';
import dotenv from 'dotenv';
import express from 'express';
import session from 'express-session';
import SQLiteStoreFactory from 'connect-sqlite3';
import path from 'path';
import { fileURLToPath } from 'url';
import multer from 'multer';
import fs from 'fs';
import dayjs from 'dayjs';

import PrayerTimeService from './services/prayerTimeService.js';
import AudioPlayer from './services/audioPlayer.js';
import SchedulerService from './services/schedulerService.js';
import db from './utils/db.js';
import { ensureDefaultUser, authenticate, requireAuth } from './utils/auth.js';
import { PRAYERS } from './services/prayerTimeService.js';

dotenv.config();

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const app = express();
const port = process.env.PORT || 3000;

const SQLiteStore = SQLiteStoreFactory(session);

app.use(express.json());
app.use(express.urlencoded({ extended: true }));

app.use(
  session({
    store: new SQLiteStore({ db: 'sessions.sqlite', dir: process.cwd() }),
    secret: process.env.SESSION_SECRET || 'secret',
    resave: false,
    saveUninitialized: false
  })
);

const upload = multer({ dest: path.join(process.cwd(), 'public', 'uploads') });

ensureDefaultUser();

const prayerService = new PrayerTimeService();
prayerService.ensureTimesForRange();

const audioPlayer = new AudioPlayer({
  audioCmd: process.env.AUDIO_CMD,
  audioDevice: process.env.AUDIO_DEVICE,
  fadeEnabled: prayerService.settings.fade_enabled === 1,
  playbackMode: prayerService.settings.playback_mode
});

const scheduler = new SchedulerService(audioPlayer);

app.use(express.static(path.join(process.cwd(), 'public')));

app.post('/api/login', (req, res) => {
  const { email, password } = req.body;
  if (authenticate(email, password)) {
    req.session.user = { email };
    res.json({ ok: true });
  } else {
    res.status(401).json({ error: 'Invalid credentials' });
  }
});

app.post('/api/logout', (req, res) => {
  req.session.destroy(() => {
    res.json({ ok: true });
  });
});

app.get('/api/times', requireAuth, (req, res) => {
  const date = req.query.date || dayjs().format('YYYY-MM-DD');
  const rows = prayerService.listTimes(date, 7);
  res.json(rows);
});

app.post('/api/settings', requireAuth, (req, res) => {
  const {
    country,
    city,
    district,
    lat,
    lon,
    method,
    madhab,
    hlr,
    timezone,
    master_volume,
    fade_enabled,
    playback_mode,
    ramadan_offset
  } = req.body;

  const stmt = db.prepare(`UPDATE settings SET country=@country, city=@city, district=@district, lat=@lat, lon=@lon,
    method=@method, madhab=@madhab, hlr=@hlr, timezone=@timezone, master_volume=@master_volume,
    fade_enabled=@fade_enabled, playback_mode=@playback_mode, ramadan_offset=@ramadan_offset WHERE id = 1`);
  stmt.run({
    country,
    city,
    district,
    lat,
    lon,
    method,
    madhab,
    hlr,
    timezone,
    master_volume,
    fade_enabled: fade_enabled ? 1 : 0,
    playback_mode,
    ramadan_offset
  });

  const offsetsStmt = db.prepare('UPDATE prayer_offsets SET fajr=?, dhuhr=?, asr=?, maghrib=?, isha=? WHERE id = 1');
  offsetsStmt.run(
    req.body.offsets?.fajr || 0,
    req.body.offsets?.dhuhr || 0,
    req.body.offsets?.asr || 0,
    req.body.offsets?.maghrib || 0,
    req.body.offsets?.isha || 0
  );

  prayerService.loadSettings();
  audioPlayer.updateSettings({ fadeEnabled: prayerService.settings.fade_enabled === 1, playbackMode: prayerService.settings.playback_mode });
  prayerService.recompute();
  scheduler.reschedule();

  res.json({ ok: true });
});

app.post('/api/voices', requireAuth, (req, res) => {
  const { name } = req.body;
  if (!name) return res.status(400).json({ error: 'Name required' });
  const result = db.prepare('INSERT INTO voice_sets (name) VALUES (?)').run(name);
  res.json({ id: result.lastInsertRowid, name });
});

app.get('/api/voices', requireAuth, (req, res) => {
  const rows = db.prepare('SELECT * FROM voice_sets').all();
  const files = db.prepare('SELECT * FROM voice_files').all();
  const grouped = rows.map((set) => ({
    ...set,
    files: files.filter((f) => f.voice_set_id === set.id)
  }));
  res.json(grouped);
});

app.delete('/api/voices/:id', requireAuth, (req, res) => {
  const id = Number(req.params.id);
  const stmt = db.prepare('DELETE FROM voice_sets WHERE id = ?');
  stmt.run(id);
  res.json({ ok: true });
});

app.post('/api/voices/:id/upload', requireAuth, upload.single('file'), (req, res) => {
  const id = Number(req.params.id);
  const { prayer } = req.query;
  if (!PRAYERS.includes(prayer)) {
    return res.status(400).json({ error: 'Invalid prayer' });
  }
  const filePath = req.file.path;
  const existing = db.prepare('SELECT * FROM voice_files WHERE voice_set_id = ? AND prayer = ?').get(id, prayer);
  if (existing) {
    try {
      fs.unlinkSync(existing.path);
    } catch (err) {
      // ignore
    }
    db.prepare('UPDATE voice_files SET path = ? WHERE id = ?').run(filePath, existing.id);
  } else {
    db.prepare('INSERT INTO voice_files (voice_set_id, prayer, path) VALUES (?, ?, ?)').run(id, prayer, filePath);
  }
  res.json({ ok: true, path: filePath });
});

app.post('/api/active-voice', requireAuth, (req, res) => {
  const { voice_set_id } = req.body;
  db.prepare('UPDATE voice_sets SET is_active = 0').run();
  db.prepare('UPDATE voice_sets SET is_active = 1 WHERE id = ?').run(voice_set_id);
  scheduler.reschedule();
  res.json({ ok: true });
});

app.post('/api/test-play', requireAuth, (req, res) => {
  const { prayer } = req.body;
  const activeVoice = db.prepare('SELECT * FROM voice_sets WHERE is_active = 1').get();
  if (!activeVoice) return res.status(400).json({ error: 'No active voice set' });
  const file = db.prepare('SELECT * FROM voice_files WHERE voice_set_id = ? AND prayer = ?').get(activeVoice.id, prayer);
  if (!file) return res.status(400).json({ error: 'Audio missing' });
  audioPlayer.play(prayer, file.path, activeVoice.id, prayerService.settings.master_volume);
  res.json({ ok: true });
});

app.get('/api/schedule', requireAuth, (req, res) => {
  const now = dayjs();
  const rows = db.prepare('SELECT * FROM times WHERE date >= ? ORDER BY date LIMIT 7').all(now.format('YYYY-MM-DD'));
  res.json(rows);
});

app.get('/api/logs', requireAuth, (req, res) => {
  const page = Number(req.query.page || 1);
  const pageSize = 20;
  const offset = (page - 1) * pageSize;
  const rows = db.prepare('SELECT * FROM play_logs ORDER BY ts DESC LIMIT ? OFFSET ?').all(pageSize, offset);
  res.json(rows);
});

app.post('/api/stop', requireAuth, (req, res) => {
  audioPlayer.stopCurrent();
  res.json({ ok: true });
});

app.post('/api/snooze', requireAuth, (req, res) => {
  const { minutes } = req.body;
  scheduler.clearJobs();
  setTimeout(() => scheduler.reschedule(), (minutes || 5) * 60 * 1000);
  res.json({ ok: true });
});

app.get('/api/settings', requireAuth, (req, res) => {
  const settings = db.prepare('SELECT * FROM settings WHERE id = 1').get();
  const offsets = db.prepare('SELECT * FROM prayer_offsets WHERE id = 1').get();
  res.json({ settings, offsets });
});

app.get('/api/locations', requireAuth, (req, res) => {
  const data = fs.readFileSync(path.join(process.cwd(), 'data', 'locations.json'), 'utf-8');
  res.json(JSON.parse(data));
});

app.get('*', (req, res) => {
  res.sendFile(path.join(process.cwd(), 'public', 'index.html'));
});

app.listen(port, () => {
  console.log(`Server running on port ${port}`);
});

export { prayerService, scheduler, audioPlayer };
