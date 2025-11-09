import test from 'node:test';
import assert from 'node:assert/strict';

process.env.TZ = 'Europe/Istanbul';

await import('../src/utils/db.js');
const { default: db } = await import('../src/utils/db.js');
const { default: SchedulerService } = await import('../src/services/schedulerService.js');

class StubAudio {
  constructor() {
    this.calls = [];
  }
  play(...args) {
    this.calls.push(args);
  }
  log() {}
}

test('scheduler triggers playback for active voice', () => {
  db.prepare('DELETE FROM voice_sets').run();
  db.prepare('DELETE FROM voice_files').run();
  db.prepare('UPDATE settings SET master_volume = 75 WHERE id = 1').run();
  const insertVoice = db.prepare('INSERT INTO voice_sets (name, is_active) VALUES (?, 1)');
  const voiceId = insertVoice.run('Test Set').lastInsertRowid;
  db.prepare('INSERT INTO voice_files (voice_set_id, prayer, path) VALUES (?, ?, ?)').run(voiceId, 'fajr', '/tmp/fajr.mp3');

  const audio = new StubAudio();
  const scheduler = new SchedulerService(audio);
  scheduler.clearJobs();
  scheduler.triggerPrayer('fajr');
  assert.equal(audio.calls.length, 1);
  const [prayer, filePath, voiceSetId, volume] = audio.calls[0];
  assert.equal(prayer, 'fajr');
  assert.equal(filePath, '/tmp/fajr.mp3');
  assert.equal(voiceSetId, voiceId);
  assert.equal(volume, 75);
});
