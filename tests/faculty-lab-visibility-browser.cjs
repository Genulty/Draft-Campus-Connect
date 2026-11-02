// Uses real SELECT-only view output, excluding student rosters. All HTTP requests
// are intercepted: there is no web-server login, registration, or database write.
// DB_USER/DB_PASSWORD configure tests/faculty-lab-visibility.php (SELECT-only guard).
const { chromium } = require('playwright');
const { readFileSync } = require('node:fs');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const assert = require('node:assert/strict');
(async () => {
  const root = path.resolve(__dirname, '..');
  const fixture = JSON.parse(execFileSync('php', [path.join(__dirname, 'faculty-lab-visibility.php'), '--browser-fixture'], { encoding: 'utf8', env: process.env }));
  const browser = await chromium.launch({ headless: true, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}) });
  const errors = []; let pages = 0;
  try {
    for (const width of [1440, 390]) for (const [id, data] of Object.entries(fixture.faculty)) {
      const page = await browser.newPage({ viewport: { width, height: 900 } }); pages++;
      page.on('pageerror', e => errors.push(e.message));
      page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
      const requests = [];
      await page.route('**/*', route => {
        const url = new URL(route.request().url());
        const json = body => route.fulfill({ contentType: 'application/json', body: JSON.stringify(body) });
        if (url.hostname === 'isolated.invalid' && url.pathname.startsWith('/api/')) {
          requests.push(url.pathname + url.search);
          if (url.pathname === '/api/me') return json({ user: { id: Number(id), role: 'Faculty', name: `Faculty ${id}`, email: 'fixture@example.invalid' } });
          if (url.pathname === '/api/views') return json({ views: [{ id: 'teaching', label: 'My teaching' }] });
          if (url.pathname === '/api/views/teaching') return json(data.pages[url.searchParams.get('semester')] || data.pages[fixture.defaultSemester]);
          throw new Error(`Unexpected API request: ${url}`);
        }
        const file = url.hostname === 'isolated.invalid' ? { '/': ['public/index.html','text/html'], '/login.js': ['public/login.js','application/javascript'], '/styles.css': ['public/styles.css','text/css'] }[url.pathname] : null;
        return route.fulfill(file ? { body: readFileSync(path.join(root,file[0])), contentType: file[1] } : { body: '', contentType: 'text/css' });
      });
      async function verify(semester) {
        const expected = data.pages[semester];
        const schedule = expected.blocks[1];
        const card = page.locator('#page-blocks .table-card');
        await page.waitForFunction(title => document.querySelector('#page-blocks .table-card h3')?.textContent === title, schedule.title);
        assert.equal(await page.locator('#page-blocks select[name=semester]').inputValue(), semester);
        if (schedule.rows.length) {
          assert.deepEqual(await card.locator('th').allTextContents(), schedule.columns);
          const actual = await card.locator('tbody tr').evaluateAll(rows => rows.map(row => [...row.cells].map(c => c.textContent)));
          assert.deepEqual(actual, schedule.rows.map(row => row.map(v => v === null ? '—' : String(v))));
        } else {
          assert.equal(await card.locator('.empty-row').textContent(), 'Nothing to show.');
        }
        const meetingIndex = schedule.columns.indexOf('Meeting');
        const labRows = schedule.rows.filter(row => row[meetingIndex] === 'Lab');
        if (semester === 'FA26') assert.equal(labRows.length, id === '200027' ? 2 : id === '200001' ? 0 : 1);
        else assert.equal(labRows.length, 0, 'Historical/upcoming display leaked FA26 labs');
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Page exceeds viewport; table must scroll within its wrapper');
        if (schedule.rows.length) assert.equal(await card.locator('.table-wrap').evaluate(el => getComputedStyle(el).overflowX), 'auto');
      }
      await page.goto('http://isolated.invalid/#teaching?semester=FA26', { waitUntil: 'domcontentloaded' });
      await verify('FA26');
      if (id === '200027') await page.screenshot({ path: `/private/tmp/Campus-Connect-faculty-labs-${width}.png`, fullPage: true });
      for (const semester of ['SP27','FA25','FA26']) {
        await page.locator('#page-blocks select[name=semester]').selectOption(semester);
        await verify(semester);
      }
      await page.reload({ waitUntil: 'domcontentloaded' }); await verify('FA26');
      assert.ok(requests.includes('/api/views/teaching?semester=SP27'));
      assert.ok(requests.includes('/api/views/teaching?semester=FA25'));
      await page.close();
    }
    assert.deepEqual(errors, []);
    console.log(`PASS: ${pages} desktop/mobile pages across all five affected faculty plus a no-lab faculty member; all six FA26 labs rendered with exact meeting fields, Lecture/Lab labels, grouping by parent CRN, semester switches/refresh, responsive table scrolling, and no console errors. Real SELECT-only payloads; HTTP intercepted.`);
  } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
