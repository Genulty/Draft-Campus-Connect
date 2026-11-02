// Full-page browser tests with synthetic API responses and intercepted assets.
// No application server, sign-in writes, or database access.
const { chromium } = require('playwright');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const base = 'http://isolated.invalid/';
const views = role => ({
  Student: ['overview', 'master-schedule', 'catalog', 'degree-audit'],
  Faculty: ['teaching', 'master-schedule', 'catalog'],
  Admin: ['admin-overview', 'master-schedule', 'catalog', 'account'],
  StatDept: ['stats', 'catalog'],
}[role] || []).map(id => ({ id, label: id }));
function master(params) {
  const semester = ['FA26', 'SP27'].includes(params.get('semester')) ? params.get('semester') : 'FA26';
  const dept = ['CS', 'BIO'].includes(params.get('dept')) ? params.get('dept') : '';
  return { title: 'Semester master schedule', blocks: [
    { type: 'filters', filters: [
      { name: 'semester', label: 'Semester', value: semester, options: [['FA26', 'Fall 2026'], ['SP27', 'Spring 2027']] },
      { name: 'dept', label: 'Department', value: dept, options: [['', 'All departments'], ['CS', 'Computer Science'], ['BIO', 'Biology']] },
    ] },
    { type: 'table', title: 'Course sections', columns: ['Fixture selection'], rows: [[`${semester}/${dept || 'ALL'}`]] },
  ] };
}
(async () => {
  const browser = await chromium.launch({ headless: true, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}) });
  const errors = [];
  let pages = 0;
  try {
    async function setup(role = 'Student', hash = '#master-schedule?semester=SP27&dept=CS') {
      const page = await browser.newPage(); pages++;
      const requests = [];
      page.on('pageerror', e => errors.push(e.message));
      page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
      await page.route('**/*', async route => {
        const url = new URL(route.request().url());
        const json = data => route.fulfill({ contentType: 'application/json', body: JSON.stringify(data) });
        if (url.hostname === 'isolated.invalid' && url.pathname.startsWith('/api/')) {
          requests.push(url.pathname + url.search);
          if (url.pathname === '/api/me') return json(role ? { user: { id: 1, role, name: 'Fixture user', email: 'fixture@example.invalid' } } : null);
          if (url.pathname === '/api/views') return json({ views: views(role) });
          if (url.pathname.startsWith('/api/views/')) {
            const id = url.pathname.split('/').at(-1);
            assert.ok(views(role).some(v => v.id === id), `Unauthorized view requested: ${id}`);
            return json(id === 'master-schedule' ? master(url.searchParams) : { title: id, blocks: [] });
          }
          if (url.pathname === '/api/welcome') return json({});
          if (url.pathname === '/api/major-browser') return json({ blocks: [] });
          throw new Error(`Unexpected API request: ${url}`);
        }
        const assets = { '/': ['public/index.html', 'text/html'], '/login.js': ['public/login.js', 'application/javascript'], '/styles.css': ['public/styles.css', 'text/css'] };
        const file = url.hostname === 'isolated.invalid' ? assets[url.pathname] : null;
        return route.fulfill(file ? { body: readFileSync(path.join(root, file[0])), contentType: file[1] } : { body: '', contentType: 'text/css' });
      });
      await page.goto(base + hash, { waitUntil: 'domcontentloaded' });
      return { page, requests };
    }
    async function expectMaster(page, semester, dept) {
      await page.waitForFunction(({ semester, dept }) => {
        const blocks = document.getElementById('page-blocks');
        return blocks.querySelector('[name=semester]')?.value === semester
          && blocks.querySelector('[name=dept]')?.value === dept
          && blocks.textContent.includes(`${semester}/${dept || 'ALL'}`);
      }, { semester, dept });
      assert.equal(await page.evaluate(() => location.hash), `#master-schedule?semester=${semester}&dept=${dept}`);
      assert.equal(await page.locator('#nav a.active').getAttribute('data-view'), 'master-schedule');
    }
    const { page, requests } = await setup();
    await expectMaster(page, 'SP27', 'CS');
    assert.ok(requests.includes('/api/views/master-schedule?semester=SP27&dept=CS'));
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expectMaster(page, 'SP27', 'CS');
    await page.locator('#page-blocks [name=dept]').selectOption('BIO');
    await expectMaster(page, 'SP27', 'BIO');
    await page.locator('#page-blocks [name=semester]').selectOption('FA26');
    await expectMaster(page, 'FA26', 'BIO');
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expectMaster(page, 'FA26', 'BIO');
    await page.goBack(); await expectMaster(page, 'SP27', 'BIO');
    await page.goBack(); await expectMaster(page, 'SP27', 'CS');
    await page.goForward(); await expectMaster(page, 'SP27', 'BIO');
    await page.goForward(); await expectMaster(page, 'FA26', 'BIO');
    await page.locator('#nav [data-view=catalog]').click();
    await page.waitForFunction(() => document.getElementById('page-title').textContent === 'catalog');
    await page.goBack(); await expectMaster(page, 'FA26', 'BIO');
    await page.goForward();
    await page.waitForFunction(() => document.getElementById('page-title').textContent === 'catalog');
    await page.evaluate(() => { location.hash = '#master-schedule?semester=SP27&dept=CS'; });
    await expectMaster(page, 'SP27', 'CS');
    await page.close();

    // Fresh tabs exercise direct bookmarks, absent/invalid values, duplicates, arrays,
    // URL decoding, and safe removal of unrelated parameters from the canonical URL.
    for (const [hash, semester, dept] of [
      ['#master-schedule?semester=FA26&dept=BIO', 'FA26', 'BIO'],
      ['#master-schedule', 'FA26', ''],
      ['#master-schedule?semester=SP27', 'SP27', ''],
      ['#master-schedule?dept=CS', 'FA26', 'CS'],
      ['#master-schedule?semester=invalid&dept=invalid', 'FA26', ''],
      ['#master-schedule?semester[]=SP27&dept[]=CS', 'FA26', ''],
      ['#master-schedule?semester=SP27&dept=%43%53&unrelated=ignored', 'SP27', 'CS'],
      ['#master-schedule?semester=FA26&semester=SP27&dept=CS', 'SP27', 'CS'],
      ['#master-schedule?semester=%E0%A4%A&dept=CS%27%20OR%201%3D1', 'FA26', ''],
    ]) {
      const { page } = await setup('Student', hash);
      await expectMaster(page, semester, dept); await page.close();
    }
    for (const role of ['Faculty', 'Admin']) {
      const { page } = await setup(role);
      await expectMaster(page, 'SP27', 'CS');
      assert.equal(await page.locator('#me-role').textContent(), role);
      await page.close();
    }
    for (const [role, hash, expected] of [
      ['Student', '#admin-overview?semester=SP27', 'overview'],
      ['Student', '#unknown-view?dept=CS', 'overview'],
      ['StatDept', '#master-schedule?semester=SP27&dept=CS', 'stats'],
      ['Student', '#degree-audit', 'degree-audit'],
      ['Faculty', '#teaching', 'teaching'],
      ['Admin', '#account?student_ID=123', 'account'],
    ]) {
      const { page, requests } = await setup(role, hash);
      await page.waitForFunction(title => document.getElementById('page-title').textContent === title, expected);
      assert.ok(!(await page.locator('#dashboard-page').isHidden()));
      if (expected === 'overview' || expected === 'stats') assert.equal(await page.evaluate(() => location.hash), `#${expected}`);
      if (expected === 'account') assert.ok(requests.includes('/api/views/account?student_ID=123'));
      await page.close();
    }
    const anonymous = await setup(null);
    await anonymous.page.waitForFunction(() => document.getElementById('welcome-page').hidden === false && location.hash === '');
    assert.ok(!anonymous.requests.some(url => url.startsWith('/api/views')));
    // Back/hash changes while logged out cannot open an authenticated dashboard.
    await anonymous.page.evaluate(() => { location.hash = '#master-schedule?semester=SP27&dept=CS'; });
    await anonymous.page.evaluate(() => new Promise(resolve => setTimeout(resolve, 0)));
    assert.equal(await anonymous.page.locator('#dashboard-page').isHidden(), true);
    assert.ok(!anonymous.requests.some(url => url.startsWith('/api/views')));
    await anonymous.page.close();
    assert.deepEqual(errors, []);
    console.log(`PASS: ${pages} full-page scenarios: refresh/bookmarks, SP27/CS and FA26/BIO, filter preservation, Back/Forward, hash navigation, invalid parameters, role-specific views and logged-out isolation; no console errors. API/assets simulated; no database access.`);
  } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
