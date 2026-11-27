// Isolated browser test of actual view payloads, openView, filters, and table renderer.
const { chromium } = require('playwright');
const { readFileSync } = require('node:fs');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const assert = require('node:assert/strict');
(async () => {
  const { progress, ...pages } = JSON.parse(execFileSync('php', [path.join(__dirname, 'registration-windows.php'), '--pages'], { encoding: 'utf8' }));
  const source = readFileSync(path.join(__dirname, '../public/login.js'), 'utf8');
  const openView = source.slice(source.indexOf('let currentView ='), source.indexOf('// Student audit:'));
  const renderer = source.slice(source.indexOf('function el('), source.indexOf("document.getElementById('nav').addEventListener"));
  const browser = await chromium.launch({ headless: true, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}) });
  try {
    const page = await browser.newPage(); const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.route('**/*', route => route.fulfill({ contentType: 'text/html', body: '<!doctype html><div id="nav"></div><h1 id="page-title"></h1><p id="page-subtitle"></p><div id="page-blocks"></div>' }));
    await page.goto('http://isolated.invalid/');
    await page.addScriptTag({ content: `${openView}\n${renderer}\nwindow.submissions=[]; window.requests=[]; async function runAction(endpoint,body) { submissions.push({endpoint,body}); } window.fetch=async url=>{ requests.push(url); return {status:200,json:async()=>window.fixturePage}; };` });
    for (const [name, fixture] of Object.entries(pages)) {
      await page.evaluate(async fixture => { window.fixturePage=fixture; submissions.length=0; await openView('register',{semester:'SP27'}); }, fixture);
      assert.equal(await page.locator('#page-subtitle').textContent(), fixture.subtitle);
      if (name === 'both-closed') { assert.equal(await page.locator('button').count(),0); continue; }
      const drops=page.getByRole('button',{name:'Drop',exact:true});
      if (name === 'no-enrollments') assert.equal(await drops.count(),0);
      else assert.equal(await drops.isEnabled(), name !== 'add-only');
      if (name === 'empty-department') {
        assert.equal(await page.getByText('No eligible sections in Other fixture', { exact: true }).count(), 1);
        const requestCount = await page.evaluate(() => requests.length);
        await page.getByRole('button', { name: 'Show all departments', exact: true }).click();
        await page.waitForFunction(count => requests.length > count, requestCount);
        assert.equal(await page.evaluate(() => requests.at(-1)), '/api/views/register?semester=SP27&dept=');
        continue;
      }
      assert.equal(await page.locator('select[name=dept]').inputValue(), '');
      const adds=page.getByRole('button',{name:'Add',exact:true});
      if (name === 'both-open' || name === 'add-only') {
        assert.equal(await adds.isEnabled(),true);
        await adds.click();
        assert.equal(await page.evaluate(()=>submissions.at(-1).endpoint),'add-section');
      } else {
        assert.equal(await adds.count(),0);
        assert.equal(await page.getByRole('button',{name:'Try',exact:true}).count(),0);
        assert.equal(await page.evaluate(()=>submissions.length),0);
      }
      if (name === 'add-only') {
        await drops.dispatchEvent('click');
        assert.equal(await page.evaluate(()=>submissions.filter(s=>s.endpoint==='drop-section').length),0);
      }
      if (name === 'drop-only') {
        page.once('dialog', async dialog => {
          assert.equal(dialog.message(), 'Drop this section from your registration?');
          await dialog.dismiss();
        });
        await drops.click();
        assert.equal(await page.evaluate(() => submissions.length), 0);
        assert.equal(await drops.isEnabled(), true);
        page.once('dialog', dialog => dialog.accept());
        await drops.click();
        assert.equal(await page.evaluate(()=>submissions.at(-1).endpoint),'drop-section');
        await page.locator('select[name=dept]').selectOption('OTHER');
        assert.equal(await page.evaluate(()=>requests.at(-1)), '/api/views/register?semester=SP27&dept=OTHER&unmet=');
        assert.equal(await page.locator('select[name=semester]').inputValue(),'SP27');
        assert.ok((await page.evaluate(()=>location.hash)).includes('semester=SP27&dept=OTHER'));
        await page.locator('select[name=semester]').selectOption('SP27');
        assert.ok((await page.evaluate(()=>requests.at(-1))).includes('semester=SP27'));
      }
    }
    const expectedByTotal = { 8: '4 credits to go', 11: '1 credit to go', 12: 'Goal reached', 13: 'Goal reached' };
    assert.deepEqual(Object.keys(progress), Object.keys(expectedByTotal));
    for (const [total, fixtures] of Object.entries(progress)) {
      await page.evaluate(async fixtures => {
        window.fetch = async url => {
          const dept = new URL(url, location.href).searchParams.get('dept') ?? '';
          return { status: 200, json: async () => fixtures[dept] };
        };
        await openView('register', { semester: 'SP27' });
      }, fixtures);
      const dept = page.locator('select[name=dept]');
      assert.equal(await dept.inputValue(), '');
      const registration = page.locator('.table-card').filter({ has: page.getByRole('heading', { name: 'My Spring 2027 registration' }) });
      const expectedRows = await registration.locator('tbody').innerText();
      for (const value of ['TEST', 'OTHER', '']) {
        await dept.selectOption(value);
        assert.equal(await dept.inputValue(), value);
        assert.equal(await registration.locator('tbody').innerText(), expectedRows);
        assert.equal(await page.getByText(`${total} of 16`, { exact: true }).count(), 1);
        const goalCard = page.locator('.stat').filter({ has: page.getByText('Credit Goal: 12', { exact: true }) });
        assert.equal(await goalCard.locator('.stat-value').textContent(), expectedByTotal[total]);
      }
    }
    assert.deepEqual(errors,[]);
    console.log('PASS: Full-time guidance and semester-wide registrations remain stable while switching departments.');
    console.log('PASS: Chromium rendered both-open, add-only, drop-only, last-drop-day, both-closed, and no-enrollment states; disabled controls blocked dispatch; semester/department navigation and hash passed; no browser errors. Requests/actions intercepted; no MySQL access.');
  } finally { await browser.close(); }
})().catch(e=>{ console.error(e); process.exitCode=1; });
