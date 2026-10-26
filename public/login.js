// Page logic: welcome page, sign in / password reset, and the dashboard.
const welcomePage = document.getElementById('welcome-page');
const loginPage = document.getElementById('login-page');
const dashboardPage = document.getElementById('dashboard-page');
const form = document.getElementById('login-form');
const message = document.getElementById('message');

async function showDashboard(user) {
  document.getElementById('me-name').textContent = user.name;
  document.getElementById('me-email').textContent = user.email;
  document.getElementById('me-role').textContent = user.role === 'StatDept' ? 'Stat-Dept' : user.role;
  document.getElementById('me-id').textContent = `ID ${user.id}`;
  document.getElementById('portal-name').textContent = `${user.role === 'StatDept' ? 'Stat-Dept' : user.role} portal`;
  welcomePage.hidden = true;
  loginPage.hidden = true;
  dashboardPage.hidden = false;
  const { views } = await (await fetch('/api/views')).json();
  const nav = document.getElementById('nav');
  nav.replaceChildren(...views.map((v) => {
    const a = document.createElement('a');
    a.href = `#${v.id}`;
    a.textContent = v.label;
    a.dataset.view = v.id;
    return a;
  }));
  const wanted = location.hash.slice(1).split('?')[0];
  openView(views.some((v) => v.id === wanted) ? wanted : views[0].id);
}

let currentView = null;
let currentParams = {};
async function openView(id, params = {}, flash = null) {
  currentView = id;
  currentParams = params;
  document.querySelectorAll('#nav a').forEach((a) => a.classList.toggle('active', a.dataset.view === id));
  const qs = new URLSearchParams(params).toString();
  history.replaceState(null, '', `#${id}${qs ? `?${qs}` : ''}`);
  const blocks = document.getElementById('page-blocks');
  blocks.innerHTML = '<p class="muted">Loading…</p>';
  const res = await fetch(`/api/views/${id}${qs ? `?${qs}` : ''}`);
  if (res.status === 401) return showLogin();
  const page = await res.json();
  if (currentView !== id) return;
  document.getElementById('page-title').textContent = page.title || page.error;
  document.getElementById('page-subtitle').textContent = page.subtitle || '';
  blocks.replaceChildren(...(flash ? [renderFlash(flash)] : []), ...(page.blocks || []).map((b) => renderBlock(b, id)));
}

function renderFlash({ ok, message, heading }) {
  const box = el('div', ok ? 'flash ok' : 'flash err');
  box.append(el('strong', null, heading || (ok ? 'Done' : 'Not allowed')), el('span', null, message));
  return box;
}

// Runs a database action, then reloads the page with the result on top.
async function runAction(endpoint, body) {
  const res = await fetch(`/api/actions/${endpoint}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  if (res.status === 401) return showLogin();
  const data = await res.json();
  await openView(currentView, currentParams, res.ok ? { ok: true, message: data.message } : { ok: false, message: data.error });
  document.querySelector('.content').scrollIntoView({ behavior: 'smooth' });
}

function el(tag, className, text) {
  const e = document.createElement(tag);
  if (className) e.className = className;
  if (text !== undefined) e.textContent = text;
  return e;
}

// onFilter: what a filter change does. By default it reloads the dashboard page with the new values.
function renderBlock(block, viewId, onFilter = (params) => openView(viewId, params)) {
  if (block.type === 'stats') {
    const grid = el('div', 'stats');
    for (const item of block.items) {
      const card = el('div', 'stat');
      card.append(el('span', 'stat-label', item.label), el('strong', 'stat-value', String(item.value)));
      grid.append(card);
    }
    return grid;
  }
  if (block.type === 'message') return renderFlash(block);
  if (block.type === 'filters') {
    const bar = el('div', 'filters');
    for (const f of block.filters) {
      const label = el('label', null, f.label);
      const select = el('select');
      select.name = f.name;
      for (const [value, text] of f.options) {
        const o = el('option', null, text);
        o.value = value;
        o.selected = value === f.value;
        select.append(o);
      }
      select.addEventListener('change', () => {
        const params = Object.fromEntries([...bar.querySelectorAll('select')].map((s) => [s.name, s.value]));
        onFilter(params);
      });
      label.append(select);
      bar.append(label);
    }
    return bar;
  }
  if (block.type === 'form') {
    const card = el('form', 'table-card form-card');
    card.append(el('h3', null, block.title));
    const grid = el('div', 'form-grid');
    for (const f of block.fields) {
      const label = el('label', null, f.label);
      let input;
      if (f.options) {
        input = el('select');
        for (const [value, text] of f.options) {
          const o = el('option', null, text);
          o.value = value;
          o.selected = value === f.value;
          input.append(o);
        }
        // Some choices reload the form, e.g. the semester (teaching loads depend on it) or the type of user.
        if (f.reload) input.addEventListener('change', () => openView(viewId, { [f.reload]: input.value }));
      } else {
        input = el('input');
        input.type = f.type || 'text';
        if (f.min !== undefined) input.min = f.min;
        if (f.max !== undefined) input.max = f.max;
        input.value = f.value;
        if (f.placeholder) input.placeholder = f.placeholder;
        if (f.type === 'password') input.autocomplete = 'new-password';
      }
      input.name = f.name;
      label.append(input);
      grid.append(label);
    }
    const button = el('button', 'primary', block.submit);
    button.type = 'submit';
    card.append(grid);
    if (block.note) card.append(el('p', 'muted small', block.note));
    card.append(button);
    card.addEventListener('submit', async (e) => {
      e.preventDefault();
      button.disabled = true;
      const body = Object.fromEntries(new FormData(card));
      // A search form only reloads the page with its values; other forms change the database.
      if (block.method === 'view') return openView(viewId, body);
      // Only forms that ask for it keep their values after saving (never passwords).
      if (block.keep) currentParams = { semester: body.semester_ID, ...body };
      await runAction(block.endpoint, body);
    });
    return card;
  }
  // table
  const card = el('div', 'table-card');
  const head = el('div', 'table-head');
  head.append(el('h3', null, block.title), el('span', 'muted small', `${block.rows.length} row${block.rows.length === 1 ? '' : 's'}`));
  card.append(head);
  if (block.note) card.append(el('p', 'muted small note', block.note));
  if (!block.rows.length) {
    card.append(el('p', 'muted empty-row', 'Nothing to show.'));
    return card;
  }
  const wrap = el('div', 'table-wrap');
  const table = el('table');
  const tr = el('tr');
  for (const c of block.columns) tr.append(el('th', null, c));
  if (block.action) tr.append(el('th'));
  const keyIndex = block.action ? block.columns.indexOf(block.action.column) : -1;
  table.append(el('thead'));
  table.tHead.append(tr);
  const body = el('tbody');
  for (const row of block.rows) {
    const r = el('tr');
    for (const v of row) r.append(el('td', null, v === null ? '—' : String(v)));
    if (block.action) {
      const td = el('td');
      const btn = el('button', `row-action ${block.action.style || ''}`, block.action.label);
      btn.type = 'button';
      btn.addEventListener('click', () => {
        btn.disabled = true;
        runAction(block.action.endpoint, { [block.action.column]: row[keyIndex] });
      });
      td.append(btn);
      r.append(td);
    }
    body.append(r);
  }
  table.append(body);
  wrap.append(table);
  card.append(wrap);
  return card;
}

document.getElementById('nav').addEventListener('click', (e) => {
  const a = e.target.closest('a[data-view]');
  if (!a) return;
  e.preventDefault();
  openView(a.dataset.view);
});

function showLogin() {
  dashboardPage.hidden = true;
  welcomePage.hidden = true;
  loginPage.hidden = false;
  showAuthView('login-view');
  form.reset();
  message.innerHTML = '';
  history.replaceState(null, '', location.pathname);
}

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  const button = form.querySelector('button[type=submit]');
  button.disabled = true;
  message.innerHTML = '';
  try {
    const res = await fetch('/api/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: form.email.value, password: form.password.value }),
    });
    const data = await res.json();
    if (!res.ok) {
      const box = document.createElement('div');
      box.className = 'error';
      box.textContent = data.error;
      // After 3 failed attempts (or a locked account) offer a password reset.
      if (data.canReset) {
        const link = el('a', 'reset-link', 'Forgot your password? Reset it');
        link.href = '#';
        link.style.display = 'block';
        link.addEventListener('click', (ev) => { ev.preventDefault(); openReset(form.email.value); });
        box.append(link);
      }
      message.appendChild(box);
      form.password.value = '';
    } else {
      showDashboard(data.user);
    }
  } finally {
    button.disabled = false;
  }
});

// Test-account buttons fill in the form and sign in
document.querySelectorAll('[data-email]').forEach((b) => b.addEventListener('click', () => {
  form.email.value = b.dataset.email;
  form.password.value = 'Campus123!';
  form.requestSubmit();
}));

document.getElementById('logout').addEventListener('click', async () => {
  await fetch('/api/logout', { method: 'POST' });
  showWelcome();
});

// ---------- Switching between sign in and password reset ----------
const authViews = ['login-view', 'reset-view'];
function showAuthView(id) {
  for (const v of authViews) document.getElementById(v).hidden = v !== id;
}
document.querySelectorAll('[data-show]').forEach((a) => a.addEventListener('click', (e) => {
  e.preventDefault();
  showAuthView(a.dataset.show);
}));

function showBox(target, kind, text) {
  target.replaceChildren(el('div', kind, text));
}

// ---------- Password reset ----------
const resetForm = document.getElementById('reset-form');
function openReset(login) {
  resetForm.reset();
  document.getElementById('reset-message').replaceChildren();
  resetForm.login.value = login;
  showAuthView('reset-view');
  resetForm.user_ID.focus();
}
resetForm.addEventListener('submit', async (e) => {
  e.preventDefault();
  const button = resetForm.querySelector('button[type=submit]');
  button.disabled = true;
  const msg = document.getElementById('reset-message');
  try {
    const res = await fetch('/api/reset-password', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.fromEntries(new FormData(resetForm))),
    });
    const data = await res.json();
    if (!res.ok) return showBox(msg, 'error', data.error);
    const login = resetForm.login.value;
    showAuthView('login-view');
    form.reset();
    form.email.value = login;
    message.replaceChildren(el('div', 'notice', data.message));
    form.password.focus();
  } finally {
    button.disabled = false;
  }
});

// ---------- Welcome page ----------
function showWelcome() {
  dashboardPage.hidden = true;
  loginPage.hidden = true;
  welcomePage.hidden = false;
  history.replaceState(null, '', location.pathname);
  window.scrollTo(0, 0);
  loadWelcome();
}

// Buttons and links with data-go open the welcome page or the sign-in form.
document.querySelectorAll('[data-go]').forEach((b) => b.addEventListener('click', (e) => {
  e.preventDefault();
  const go = b.dataset.go;
  if (go === 'welcome') return showWelcome();
  showLogin();
  history.replaceState(null, '', `#${go}`);
  window.scrollTo(0, 0);
}));

async function loadWelcome() {
  try {
    const res = await fetch('/api/welcome');
    if (!res.ok) return;
    const info = await res.json();
    if (info.current) document.getElementById('w-semester').textContent = `${info.current} semester`;
    const reg = document.getElementById('w-registration');
    const r = info.registration;
    if (r && info.next) {
      reg.textContent = Number(r.is_open)
        ? `Registration for ${info.next} is open until ${r.closes}`
        : `Registration for ${info.next} runs ${r.opens} – ${r.closes}`;
      reg.hidden = false;
    }
  } catch {
    // The welcome page still works without the semester and registration dates.
  }
  loadPublicSchedule();
}

// The public master schedule on the welcome page, with its semester and department filters.
async function loadPublicSchedule(params = {}) {
  const box = document.getElementById('w-schedule');
  try {
    const res = await fetch(`/api/master-schedule?${new URLSearchParams(params)}`);
    if (!res.ok) throw new Error();
    const { blocks } = await res.json();
    box.replaceChildren(...blocks.map((b) => renderBlock(b, null, loadPublicSchedule)));
  } catch {
    box.replaceChildren(el('p', 'muted', 'The schedule could not be loaded. Please try again later.'));
  }
}

// On first load: signed in → dashboard; #login → the sign-in form; otherwise the welcome page.
fetch('/api/me').then((r) => (r.ok ? r.json() : null)).then((d) => {
  if (d) return showDashboard(d.user);
  const hash = location.hash.slice(1);
  const link = document.querySelector(`.w-cta [data-go="${hash}"]`);
  if (link) link.click();
  else showWelcome();
});
