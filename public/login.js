// Login page logic.
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
async function openView(id, params = {}) {
  currentView = id;
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
  blocks.replaceChildren(...(page.blocks || []).map((b) => renderBlock(b, id)));
}

function el(tag, className, text) {
  const e = document.createElement(tag);
  if (className) e.className = className;
  if (text !== undefined) e.textContent = text;
  return e;
}

function renderBlock(block, viewId) {
  if (block.type === 'stats') {
    const grid = el('div', 'stats');
    for (const item of block.items) {
      const card = el('div', 'stat');
      card.append(el('span', 'stat-label', item.label), el('strong', 'stat-value', String(item.value)));
      grid.append(card);
    }
    return grid;
  }
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
        openView(viewId, params);
      });
      label.append(select);
      bar.append(label);
    }
    return bar;
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
  table.append(el('thead'));
  table.tHead.append(tr);
  const body = el('tbody');
  for (const row of block.rows) {
    const r = el('tr');
    for (const v of row) r.append(el('td', null, v === null ? '—' : String(v)));
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
  loginPage.hidden = false;
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
  showLogin();
});

// Stay signed in after a page refresh
fetch('/api/me').then((r) => (r.ok ? r.json() : null)).then((d) => { if (d) showDashboard(d.user); });
