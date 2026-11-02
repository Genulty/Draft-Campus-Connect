// Isolated Chromium test of the actual HTML, JavaScript, and CSS. All API responses
// are synthetic and manually released; no local server or database is contacted.
// Run with Playwright installed: node tests/major-browser-ordering.cjs
const { chromium } = require('playwright');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
function payload(params) {
  const semester = params.semester || 'FA26';
  const major = params.major || '';
  const filters = { type: 'filters', filters: [
    { name: 'semester', label: 'Semester', value: semester, options: [['FA26', 'Fall 2026'], ['SP27', 'Spring 2027']] },
    { name: 'major', label: 'Choose Major', value: major, options: [['', 'Choose a major'], ['CS_TEST', 'Computing fixture'], ['BIO_TEST', 'Biology fixture']] },
  ] };
  if (!major) return { blocks: [filters, { type: 'empty-major', message: 'Select a major above to explore available courses.' }] };
  const section = n => ({ section: `00${n}`, crn: 90000 + n, days: 'MW', time: '9:00 AM – 10:20 AM', instructor: 'Fixture instructor', room: 'Fixture room', seats: 5 });
  const courses = major === 'BIO_TEST'
    ? [{ code: 'TEST110', title: `Biology fixture ${semester}`, level: 'Freshman', credits: 4, sections: [{ ...section(1), labDays: 'F', labTime: 'Fixture lab time', labRoom: 'Fixture lab room' }] }]
    : [{ code: 'TEST100', title: `Computing fixture ${semester}`, level: 'Freshman', credits: 3, sections: [section(1), section(2)] },
      { code: 'TEST600', title: `Graduate fixture ${semester}`, level: 'Graduate', credits: 3, sections: [section(3)] }];
  return { blocks: [filters, { type: 'course-cards', title: 'Available courses', courses }] };
}
(async () => {
  const browser = await chromium.launch({ headless: true, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}) });
  try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    page.on('console', msg => { if (msg.type() === 'error') errors.push(msg.text()); });
    await page.route('**/*', route => {
      const url = new URL(route.request().url());
      const file = { '/': ['public/index.html', 'text/html'], '/login.js': ['public/login.js', 'application/javascript'], '/styles.css': ['public/styles.css', 'text/css'] }[url.pathname];
      return route.fulfill(file && url.hostname === 'isolated.invalid'
        ? { body: readFileSync(path.join(root, file[0])), contentType: file[1] }
        : { body: '', contentType: 'text/css' });
    });
    await page.addInitScript(() => {
      window.browserRequests = [];
      window.fetch = async input => {
        const url = new URL(input, location.href);
        if (url.pathname === '/api/me') return { ok: true, json: async () => null };
        if (url.pathname === '/api/welcome') return { ok: true, json: async () => ({ current: 'Fall 2026' }) };
        if (url.pathname !== '/api/major-browser') throw new Error(`Unexpected API: ${url.pathname}`);
        return new Promise((resolve, reject) => browserRequests.push({ params: Object.fromEntries(url.searchParams), resolve, reject }));
      };
    });
    await page.goto('http://isolated.invalid/', { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => browserRequests.length === 1);
    const box = page.locator('#w-schedule');
    const count = () => page.evaluate(() => browserRequests.length);
    async function choose(name, value) {
      const before = await count();
      await box.locator(`select[name=${name}]`).selectOption(value);
      await page.waitForFunction(n => browserRequests.length === n + 1, before);
      return before;
    }
    async function release(index, kind = 'success') {
      const params = await page.evaluate(i => browserRequests[i].params, index);
      await page.evaluate(async ({ index, kind, data }) => {
        const request = browserRequests[index];
        if (kind === 'network-error') request.reject(new Error('Simulated network failure'));
        else if (kind === 'http-error') request.resolve({ ok: false });
        else if (kind === 'pending-body') request.resolve({ ok: true, json: () => new Promise((resolve, reject) => { request.bodyResolve = resolve; request.bodyReject = reject; }) });
        else if (kind === 'body-success') request.bodyResolve(data);
        else if (kind === 'body-error') request.bodyReject(new Error('Simulated JSON error'));
        else request.resolve({ ok: true, json: async () => data });
        await new Promise(resolve => setTimeout(resolve, 0)); // Flush response/render continuations, no timed race.
      }, { index, kind, data: payload(params) });
    }
    async function selected(major, semester) {
      assert.equal(await box.locator('[name=major]').inputValue(), major);
      assert.equal(await box.locator('[name=semester]').inputValue(), semester);
    }
    async function showing(text) {
      assert.ok((await box.textContent()).includes(text), `Missing ${text}`);
      assert.equal(await box.getAttribute('aria-busy'), 'false');
      assert.ok(!(await box.textContent()).includes('could not be loaded'));
    }
    await release(0);
    await selected('', 'FA26');
    assert.equal(await box.locator('.major-empty').count(), 1);
    assert.equal(await box.locator('.course-card').count(), 0);

    // Rapid major switches: older success arrives after latest success.
    let older = await choose('major', 'CS_TEST');
    let newer = await choose('major', 'BIO_TEST');
    await release(newer); await release(older);
    await selected('BIO_TEST', 'FA26'); await showing('Biology fixture FA26');
    assert.ok((await box.textContent()).includes('Freshman · 4 credits · 1 section(s)'));
    assert.ok((await box.textContent()).includes('Lab: F'));

    // Semester change while loading retains the selected major and ignores FA26.
    older = await choose('major', 'CS_TEST'); newer = await choose('semester', 'SP27');
    await release(newer); await release(older);
    await selected('CS_TEST', 'SP27'); await showing('Computing fixture SP27');
    assert.ok(!(await box.textContent()).includes('fixture FA26'));
    assert.equal(await box.locator('.course-card').count(), 2);
    assert.equal(await box.locator('.course-section').count(), 3);
    assert.ok((await box.textContent()).includes('Freshman · 3 credits · 2 section(s)'));
    assert.ok((await box.textContent()).includes('Graduate · 3 credits · 1 section(s)'));

    // A response can become stale while JSON is still being read.
    older = await choose('major', 'BIO_TEST'); await release(older, 'pending-body');
    newer = await choose('major', 'CS_TEST'); await release(newer); await release(older, 'body-success');
    await selected('CS_TEST', 'SP27'); await showing('Computing fixture SP27');

    // Clear during a pending request: previous courses disappear immediately.
    older = await choose('major', 'BIO_TEST'); newer = await choose('major', '');
    assert.equal(await box.locator('.course-card').count(), 0);
    await release(newer); await release(older);
    await selected('', 'SP27'); assert.equal(await box.locator('.major-empty').count(), 1);
    assert.equal(await box.locator('.course-card').count(), 0);

    // Stale network error cannot replace newer successful results.
    older = await choose('major', 'CS_TEST'); newer = await choose('major', 'BIO_TEST');
    await release(newer); await release(older, 'network-error'); await showing('Biology fixture SP27');

    // Stale HTTP error cannot clear the newer loading state.
    older = await choose('major', 'CS_TEST'); newer = await choose('major', 'BIO_TEST');
    await release(older, 'http-error');
    assert.equal(await box.getAttribute('aria-busy'), 'true');
    assert.ok((await box.textContent()).includes('Loading course options...'));
    assert.ok(!(await box.textContent()).includes('could not be loaded'));
    await release(newer); await showing('Biology fixture SP27');

    // Current error retains filters and allows recovery by changing semester.
    newer = await choose('major', 'CS_TEST'); await release(newer, 'http-error');
    assert.ok((await box.textContent()).includes('could not be loaded'));
    await selected('CS_TEST', 'SP27'); assert.equal(await box.getAttribute('aria-busy'), 'false');
    newer = await choose('semester', 'FA26'); await release(newer);
    await selected('CS_TEST', 'FA26'); await showing('Computing fixture FA26');

    // Stale JSON error also leaves the latest loading/results alone.
    older = await choose('major', 'BIO_TEST'); await release(older, 'pending-body');
    const intermediate = await choose('major', 'CS_TEST'); newer = await choose('semester', 'SP27');
    await release(older, 'body-error'); assert.equal(await box.getAttribute('aria-busy'), 'true');
    await release(newer); await release(intermediate);
    await selected('CS_TEST', 'SP27'); await showing('Computing fixture SP27');
    assert.deepEqual(errors, []);
    console.log(`PASS: ${await count()} controlled requests covering initial empty selection, rapid major/FA26/SP27 changes, stale success/network/HTTP/JSON responses, clearing during loading, error recovery, supplied levels/credits/grouping/labs, and loading-state ownership. Actual HTML/JS/CSS; no browser-console errors or real API/database access.`);
  } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
