import { spawn } from 'child_process';
import db from '../utils/db.js';

export default class AudioPlayer {
  constructor(options = {}) {
    this.audioCmd = options.audioCmd || process.env.AUDIO_CMD || 'ffplay';
    this.audioDevice = options.audioDevice || process.env.AUDIO_DEVICE;
    this.fadeEnabled = options.fadeEnabled ?? true;
    this.playbackMode = options.playbackMode || 'stop';
    this.currentProcess = null;
    this.queue = [];
  }

  updateSettings({ fadeEnabled, playbackMode }) {
    this.fadeEnabled = fadeEnabled;
    this.playbackMode = playbackMode || 'stop';
  }

  async play(prayer, filePath, voiceSetId, volume = 80) {
    if (!filePath) {
      this.log(prayer, voiceSetId, filePath, 'error', 'File missing');
      return;
    }

    if (this.currentProcess) {
      if (this.playbackMode === 'queue') {
        this.queue.push({ prayer, filePath, voiceSetId, volume });
        return;
      }
      this.stopCurrent();
    }

    await this._playWithRetry({ prayer, filePath, voiceSetId, volume });
  }

  stopCurrent() {
    if (this.currentProcess) {
      this.currentProcess.kill('SIGTERM');
      this.currentProcess = null;
    }
  }

  async _playWithRetry({ prayer, filePath, voiceSetId, volume }) {
    let attempt = 0;
    while (attempt < 3) {
      try {
        await this.spawnPlayer({ prayer, filePath, voiceSetId, volume });
        break;
      } catch (err) {
        attempt += 1;
        if (attempt >= 3) {
          this.log(prayer, voiceSetId, filePath, 'error', err.message, 0, err.stdout, err.stderr);
        } else {
          await new Promise((resolve) => setTimeout(resolve, 2000));
        }
      }
    }
  }

  spawnPlayer({ prayer, filePath, voiceSetId, volume }) {
    const args = this.buildArgs(filePath, volume);
    return new Promise((resolve, reject) => {
      const start = Date.now();
      const proc = spawn(this.audioCmd, args, { stdio: ['ignore', 'pipe', 'pipe'] });
      this.currentProcess = proc;
      let stdout = '';
      let stderr = '';
      proc.stdout.on('data', (data) => (stdout += data.toString()));
      proc.stderr.on('data', (data) => (stderr += data.toString()));
      proc.on('error', (err) => {
        this.currentProcess = null;
        reject({ message: err.message, stdout, stderr });
      });
      proc.on('close', (code) => {
        this.currentProcess = null;
        const duration = Date.now() - start;
        if (code === 0 || code === null) {
          this.log(prayer, voiceSetId, filePath, 'ok', 'Playback completed', duration, stdout, stderr);
          resolve();
          this.processQueue();
        } else {
          reject({ message: `Player exited with code ${code}`, stdout, stderr });
        }
      });
    });
  }

  processQueue() {
    if (this.queue.length === 0) return;
    const next = this.queue.shift();
    this._playWithRetry(next);
  }

  buildArgs(filePath, volume) {
    const vol = Math.min(Math.max(volume, 0), 100) / 100;
    if (this.audioCmd === 'ffplay') {
      const args = ['-nodisp', '-autoexit', '-volume', String(Math.round(vol * 100))];
      if (this.fadeEnabled) {
        args.push('-af', 'afade=t=in:ss=0:d=0.5,afade=t=out:st=0.5:d=0.5');
      }
      if (this.audioDevice) {
        args.push('-f', 'alsa', '-device', this.audioDevice);
      }
      args.push(filePath);
      return args;
    }
    if (this.audioCmd === 'mpg123') {
      const args = [];
      if (this.audioDevice) {
        args.push('-a', this.audioDevice);
      }
      args.push('-f', String(Math.round(vol * 32768)));
      args.push(filePath);
      return args;
    }
    return [filePath];
  }

  log(prayer, voiceSetId, filePath, result, message, duration, stdout = '', stderr = '') {
    const stmt = db.prepare(`INSERT INTO play_logs (ts, prayer, voice_set_id, file_path, result, message, duration_ms, stdout, stderr)
      VALUES (@ts, @prayer, @voice_set_id, @file_path, @result, @message, @duration_ms, @stdout, @stderr)`);
    stmt.run({
      ts: new Date().toISOString(),
      prayer,
      voice_set_id: voiceSetId,
      file_path: filePath,
      result,
      message,
      duration_ms: duration || null,
      stdout,
      stderr
    });
  }
}
