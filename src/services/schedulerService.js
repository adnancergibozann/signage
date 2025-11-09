import cron from 'node-cron';
import dayjs from 'dayjs';
import timezone from 'dayjs/plugin/timezone.js';
import utc from 'dayjs/plugin/utc.js';
import db from '../utils/db.js';
import PrayerTimeService, { PRAYERS } from './prayerTimeService.js';

dayjs.extend(utc);
dayjs.extend(timezone);

export default class SchedulerService {
  constructor(audioPlayer) {
    this.audioPlayer = audioPlayer;
    this.prayerService = new PrayerTimeService();
    this.jobs = [];
    this.scheduleMidnightJob();
    this.reschedule();
  }

  scheduleMidnightJob() {
    cron.schedule('5 0 * * *', () => {
      this.prayerService.ensureTimesForRange();
      this.reschedule();
    });
  }

  reschedule() {
    this.clearJobs();
    const settings = this.prayerService.settings;
    const tz = settings.timezone || process.env.TZ || 'UTC';
    const now = dayjs().tz(tz);
    const start = now.startOf('day');
    this.prayerService.ensureTimesForRange();
    const rows = db
      .prepare('SELECT * FROM times WHERE date >= ? ORDER BY date LIMIT 7')
      .all(start.format('YYYY-MM-DD'));
    const activeVoice = db.prepare('SELECT * FROM voice_sets WHERE is_active = 1').get();

    for (const row of rows) {
      for (const prayer of PRAYERS) {
        if (prayer === 'sunrise') continue;
        const ts = dayjs(row[prayer]).tz(tz);
        if (ts.isBefore(now)) continue;
        const cronExpression = `${ts.minute()} ${ts.hour()} ${ts.date()} ${ts.month() + 1} *`;
        const job = cron.schedule(
          cronExpression,
          () => this.triggerPrayer(prayer, activeVoice),
          { timezone: tz }
        );
        this.jobs.push(job);
      }
    }
  }

  triggerPrayer(prayer, activeVoice) {
    const settings = this.prayerService.settings;
    const volume = settings.master_volume || 80;
    const voice = activeVoice || db.prepare('SELECT * FROM voice_sets WHERE is_active = 1').get();
    if (!voice) {
      this.audioPlayer.log(prayer, null, null, 'error', 'No active voice set configured');
      return;
    }
    const file = db.prepare('SELECT * FROM voice_files WHERE voice_set_id = ? AND prayer = ?').get(voice.id, prayer);
    if (!file) {
      this.audioPlayer.log(prayer, voice.id, null, 'error', 'No audio file for prayer');
      return;
    }
    this.audioPlayer.play(prayer, file.path, voice.id, volume);
  }

  clearJobs() {
    for (const job of this.jobs) {
      job.stop();
    }
    this.jobs = [];
  }
}
