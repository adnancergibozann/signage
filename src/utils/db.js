import Database from 'better-sqlite3';
import fs from 'fs';
import path from 'path';

const dbPath = path.join(process.cwd(), 'database.sqlite');
const db = new Database(dbPath);

db.pragma('journal_mode = WAL');

db.exec(`
CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT UNIQUE NOT NULL,
  password_hash TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS settings (
  id INTEGER PRIMARY KEY CHECK (id = 1),
  country TEXT,
  city TEXT,
  district TEXT,
  lat REAL,
  lon REAL,
  method TEXT DEFAULT 'MuslimWorldLeague',
  madhab TEXT DEFAULT 'Shafi',
  hlr TEXT DEFAULT 'MiddleOfTheNight',
  timezone TEXT,
  master_volume INTEGER DEFAULT 80,
  fade_enabled INTEGER DEFAULT 1,
  playback_mode TEXT DEFAULT 'stop',
  ramadan_offset INTEGER DEFAULT 0
);

INSERT OR IGNORE INTO settings (id) VALUES (1);

CREATE TABLE IF NOT EXISTS prayer_offsets (
  id INTEGER PRIMARY KEY CHECK (id = 1),
  fajr INTEGER DEFAULT 0,
  dhuhr INTEGER DEFAULT 0,
  asr INTEGER DEFAULT 0,
  maghrib INTEGER DEFAULT 0,
  isha INTEGER DEFAULT 0
);

INSERT OR IGNORE INTO prayer_offsets (id) VALUES (1);

CREATE TABLE IF NOT EXISTS voice_sets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  is_active INTEGER DEFAULT 0
);

CREATE TABLE IF NOT EXISTS voice_files (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  voice_set_id INTEGER NOT NULL,
  prayer TEXT NOT NULL,
  path TEXT NOT NULL,
  duration_ms INTEGER,
  FOREIGN KEY (voice_set_id) REFERENCES voice_sets(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS times (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  date TEXT NOT NULL UNIQUE,
  fajr TEXT NOT NULL,
  sunrise TEXT NOT NULL,
  dhuhr TEXT NOT NULL,
  asr TEXT NOT NULL,
  maghrib TEXT NOT NULL,
  isha TEXT NOT NULL,
  method_snapshot TEXT,
  offsets_snapshot TEXT
);

CREATE TABLE IF NOT EXISTS play_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ts TEXT NOT NULL,
  prayer TEXT NOT NULL,
  voice_set_id INTEGER,
  file_path TEXT,
  result TEXT NOT NULL,
  message TEXT,
  duration_ms INTEGER,
  stdout TEXT,
  stderr TEXT
);
`);

function ensureUploadsDir() {
  const dir = path.join(process.cwd(), 'public', 'uploads');
  if (!fs.existsSync(dir)) {
    fs.mkdirSync(dir, { recursive: true });
  }
}

ensureUploadsDir();

export default db;
