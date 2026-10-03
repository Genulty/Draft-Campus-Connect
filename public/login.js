// Login page logic.
const loginView = document.getElementById('login-view');
const welcomeView = document.getElementById('welcome-view');
const form = document.getElementById('login-form');
const message = document.getElementById('message');

function showWelcome(user) {
  document.getElementById('welcome-name').textContent = `Welcome, ${user.name}`;
  document.getElementById('welcome-detail').textContent = `${user.role} · ${user.email} · ID ${user.id}`;
  loginView.hidden = true;
  welcomeView.hidden = false;
}

function showLogin() {
  welcomeView.hidden = true;
  loginView.hidden = false;
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
      showWelcome(data.user);
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
fetch('/api/me').then((r) => (r.ok ? r.json() : null)).then((d) => { if (d) showWelcome(d.user); });
