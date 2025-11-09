import { PrayerTimes, CalculationMethod, Madhab, HighLatitudeRule } from 'adhan';
import dayjs from 'dayjs';
import utc from 'dayjs/plugin/utc.js';
import timezone from 'dayjs/plugin/timezone.js';
import customParseFormat from 'dayjs/plugin/customParseFormat.js';
import db from '../utils/db.js';

dayjs.extend(utc);
dayjs.extend(timezone);
dayjs.extend(customParseFormat);

const PRAYERS = ['fajr', 'sunrise', 'dhuhr', 'asr', 'maghrib', 'isha'];

const METHOD_MAP = {
  MuslimWorldLeague: CalculationMethod.MuslimWorldLeague,
  UmmAlQura: CalculationMethod.UmmAlQura,
  Egyptian: CalculationMethod.Egyptian,
  Diyanet: CalculationMethod.Diyanet,
  NorthAmerica: CalculationMethod.NorthAmerica
};

const MADHAB_MAP = {
  Shafi: Madhab.Shafi,
  Hanafi: Madhab.Hanafi
};

const HLR_MAP = {
  MiddleOfTheNight: HighLatitudeRule.MiddleOfTheNight,
  SeventhOfTheNight: HighLatitudeRule.SeventhOfTheNight,
  TwilightAngle: HighLatitudeRule.TwilightAngle
};

export default class PrayerTimeService {
  constructor() {
    this.loadSettings();
  }

  loadSettings() {
    this.settings = db.prepare('SELECT * FROM settings WHERE id = 1').get();
    this.offsets = db.prepare('SELECT * FROM prayer_offsets WHERE id = 1').get();
    if (!this.settings.timezone) {
      this.settings.timezone = process.env.TZ || Intl.DateTimeFormat().resolvedOptions().timeZone;
    }
  }

  getCoordinates() {
    const { lat, lon } = this.settings;
    if (lat && lon) {
      return { latitude: Number(lat), longitude: Number(lon) };
    }
    return null;
  }

  ensureTimesForRange(days = 7) {
    const start = dayjs().tz(this.settings.timezone).startOf('day');
    for (let i = 0; i < days; i++) {
      const target = start.add(i, 'day');
      const dateStr = target.format('YYYY-MM-DD');
      const existing = db.prepare('SELECT id FROM times WHERE date = ?').get(dateStr);
      if (!existing) {
        const times = this.calculateTimes(target.toDate());
        this.saveTimes(dateStr, times);
      }
    }
  }

  calculateTimes(date) {
    this.loadSettings();
    const coords = this.getCoordinates();
    if (!coords) {
      throw new Error('Coordinates not set');
    }

    const methodKey = this.settings.method || 'MuslimWorldLeague';
    const madhabKey = this.settings.madhab || 'Shafi';
    const hlrKey = this.settings.hlr || 'MiddleOfTheNight';

    const params = METHOD_MAP[methodKey] ? METHOD_MAP[methodKey]() : CalculationMethod.MuslimWorldLeague();
    params.madhab = MADHAB_MAP[madhabKey] || Madhab.Shafi;
    params.highLatitudeRule = HLR_MAP[hlrKey] || HighLatitudeRule.MiddleOfTheNight;

    const ramadanOffset = Number(this.settings.ramadan_offset || 0);

    const times = new PrayerTimes(coords, date, params);

    const results = {};
    for (const prayer of PRAYERS) {
      const dt = dayjs(times[prayer]).tz(this.settings.timezone);
      let adjusted = dt.add(this.offsets[prayer] || 0, 'minute');
      if (ramadanOffset && this.isRamadan(adjusted)) {
        adjusted = adjusted.add(ramadanOffset, 'minute');
      }
      results[prayer] = adjusted.format();
    }

    return {
      ...results,
      method_snapshot: JSON.stringify({ methodKey, params }),
      offsets_snapshot: JSON.stringify({ ...this.offsets, ramadan_offset: ramadanOffset })
    };
  }

  isRamadan(date) {
    // simple placeholder: Ramadan detection not trivial; allow admin to set offsets when needed.
    // Here we just return false and leave room for extension.
    return false;
  }

  saveTimes(dateStr, times) {
    const stmt = db.prepare(`INSERT OR REPLACE INTO times
      (date, fajr, sunrise, dhuhr, asr, maghrib, isha, method_snapshot, offsets_snapshot)
      VALUES (@date, @fajr, @sunrise, @dhuhr, @asr, @maghrib, @isha, @method_snapshot, @offsets_snapshot)`);
    stmt.run({ date: dateStr, ...times });
  }

  listTimes(startDate, days = 7) {
    const stmt = db.prepare('SELECT * FROM times WHERE date >= ? ORDER BY date LIMIT ?');
    return stmt.all(startDate, days);
  }

  recompute(days = 7) {
    const start = dayjs().tz(this.settings.timezone).startOf('day');
    for (let i = 0; i < days; i++) {
      const target = start.add(i, 'day');
      const dateStr = target.format('YYYY-MM-DD');
      const times = this.calculateTimes(target.toDate());
      this.saveTimes(dateStr, times);
    }
  }
}

export { PRAYERS };
