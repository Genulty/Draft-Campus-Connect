// Login page logic.
const loginPage = document.getElementById('login-page');
const dashboardPage = document.getElementById('dashboard-page');
const form = document.getElementById('login-form');
const message = document.getElementById('message');

function showDashboard(user) {
  document.getElementById('welcome-name').textContent = `Welcome, ${user.name}`;
  document.getElementById('welcome-detail').textContent = `${user.role} · ID ${user.id}`;
  document.getElementById('me-name').textContent = user.name;
  document.getElementById('me-email').textContent = user.email;
  document.getElementById('me-role').textContent = user.role;
  document.getElementById('portal-name').textContent = `${user.role} portal`;
  loginPage.hidden = true;
  dashboardPage.hidden = false;
}

function showLogin() {
  dashboardPage.hidden = true;
  loginPage.hidden = false;
  form.reset();
  message.innerHTML = '';
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
