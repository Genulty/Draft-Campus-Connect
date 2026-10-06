// Campus Connect — login server.
const path = require('path');
const crypto = require('crypto');
const express = require('express');
const session = require('express-session');
const { db, verifyPassword } = require('./db');
const { viewsFor, buildView } = require('./views');

const MAX_ATTEMPTS = 5;
const app = express();
app.set('trust proxy', 1); // runs behind nginx in production
app.use(express.json());
app.use(session({
  secret: process.env.SESSION_SECRET || crypto.randomBytes(32).toString('hex'),
  resave: false,
  saveUninitialized: false,
  cookie: { httpOnly: true, sameSite: 'lax', maxAge: 1000 * 60 * 60 * 8 },
}));

async function userInfo(id) {
  const [[u]] = await db.query(`SELECT u.user_ID, u.first_Name, u.last_Name, u.user_Type, l.user_Email
    FROM User u JOIN Login l ON l.user_ID = u.user_ID WHERE u.user_ID = ?`, [id]);
  return { id: u.user_ID, name: `${u.first_Name} ${u.last_Name}`, role: u.user_Type, email: u.user_Email };
}

// Use cases: successful / unsuccessful login
app.post('/api/login', async (req, res) => {
  const { email, password } = req.body || {};
  if (!email || !password) return res.status(400).json({ error: 'Enter your email and password.' });

  const [[login]] = await db.query('SELECT * FROM Login WHERE user_Email = ?', [String(email).trim()]);
  if (!login) return res.status(401).json({ error: 'Invalid email or password.' });
  if (login.lock_Var) {
    return res.status(423).json({ error: 'This account is locked after too many failed attempts. Contact an administrator.' });
  }

  if (!verifyPassword(password, login.user_Password)) {
    const tries = login.no_Of_Tries + 1;
    const locked = tries >= MAX_ATTEMPTS ? 1 : 0;
    await db.query('UPDATE Login SET no_Of_Tries = ?, lock_Var = ? WHERE user_ID = ?', [tries, locked, login.user_ID]);
    return res.status(401).json({
      error: locked
        ? 'Invalid email or password. The account is now locked.'
        : `Invalid email or password. ${MAX_ATTEMPTS - tries} attempt(s) left before the account is locked.`,
    });
  }

  await db.query('UPDATE Login SET no_Of_Tries = 0 WHERE user_ID = ?', [login.user_ID]);
  req.session.user = await userInfo(login.user_ID);
  res.json({ user: req.session.user });
});

app.post('/api/logout', (req, res) => req.session.destroy(() => res.json({ ok: true })));

app.get('/api/me', (req, res) => {
  if (!req.session.user) return res.status(401).json({ error: 'Not logged in.' });
  res.json({ user: req.session.user });
});

// Dashboard pages for the signed-in user's role (read-only).
app.get('/api/views', (req, res) => {
  if (!req.session.user) return res.status(401).json({ error: 'Not logged in.' });
  res.json({ views: viewsFor(req.session.user.role) });
});

app.get('/api/views/:id', async (req, res) => {
  if (!req.session.user) return res.status(401).json({ error: 'Not logged in.' });
  const page = await buildView(req.session.user, req.params.id, req.query);
  if (!page) return res.status(404).json({ error: 'That page is not available for your role.' });
  res.json(page);
});

app.use(express.static(path.join(__dirname, '..', 'public')));

const PORT = process.env.PORT || 3000;
const HOST = process.env.HOST || '0.0.0.0';
app.listen(PORT, HOST, () => console.log(`Campus Connect running at http://${HOST}:${PORT}`));
