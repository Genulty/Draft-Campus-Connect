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

function renderFlash({ ok, message, sql }) {
  const box = el('div', ok ? 'flash ok' : 'flash err');
  box.append(el('strong', null, ok ? 'Saved to the database' : 'Not allowed'), el('span', null, message));
  if (sql) box.append(el('code', 'sql', sql));
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
  await openView(currentView, currentParams, res.ok ? { ok: true, message: data.message, sql: data.sql } : { ok: false, message: data.error });
  document.querySelector('.content').scrollIntoView({ behavior: 'smooth' });
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
        // Changing the semester reloads the form (teaching loads depend on it).
        if (f.reload) input.addEventListener('change', () => openView(viewId, { semester: input.value }));
      } else {
        input = el('input');
        input.type = f.type || 'text';
        if (f.min !== undefined) input.min = f.min;
        if (f.max !== undefined) input.max = f.max;
        input.value = f.value;
      }
      input.name = f.name;
      label.append(input);
      grid.append(label);
    }
    const button = el('button', 'primary', block.submit);
    button.type = 'submit';
    card.append(grid, button);
    card.addEventListener('submit', async (e) => {
      e.preventDefault();
      button.disabled = true;
      const body = Object.fromEntries(new FormData(card));
      currentParams = { semester: body.semester_ID, ...body };
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
  if (block.sql) card.append(el('code', 'sql query', block.sql));
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
