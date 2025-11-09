import bcrypt from 'bcrypt';
import db from './db.js';

export function ensureDefaultUser() {
  const user = db.prepare('SELECT * FROM users WHERE email = ?').get('admin@example.com');
  if (!user) {
    const hash = bcrypt.hashSync('admin123', 10);
    db.prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)').run('admin@example.com', hash);
  }
}

export function authenticate(email, password) {
  const user = db.prepare('SELECT * FROM users WHERE email = ?').get(email);
  if (!user) return false;
  return bcrypt.compareSync(password, user.password_hash);
}

export function requireAuth(req, res, next) {
  if (req.session.user) {
    next();
  } else {
    res.status(401).json({ error: 'Unauthorized' });
  }
}
