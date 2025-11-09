import test from 'node:test';
import assert from 'node:assert/strict';
process.env.TZ = 'Europe/Istanbul';

await import('../src/utils/db.js');
const { default: PrayerTimeService } = await import('../src/services/prayerTimeService.js');
const dbModule = await import('../src/utils/db.js');
const db = dbModule.default;

db.prepare('UPDATE settings SET lat=?, lon=?, timezone=? WHERE id = 1').run(41.0082, 28.9784, 'Europe/Istanbul');

test('prayer times are computed and stored', () => {
  const service = new PrayerTimeService();
  const times = service.calculateTimes(new Date());
  assert.ok(times.fajr, 'fajr exists');
  service.saveTimes('2099-01-01', times);
  const row = db.prepare('SELECT * FROM times WHERE date = ?').get('2099-01-01');
  assert.equal(row.date, '2099-01-01');
});
