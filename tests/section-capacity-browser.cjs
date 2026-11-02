// Database-free Chromium test: actual PHP form metadata and actual JS renderer.
// Run with Playwright installed: node tests/section-capacity-browser.cjs
const { chromium } = require('playwright');
const { readFileSync } = require('node:fs');
const { execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');
const path = require('node:path');
(async () => {
  const root = path.resolve(__dirname, '..');
  const block = JSON.parse(execFileSync('php', [path.join(__dirname, 'section-capacity.php'), '--form'], { encoding: 'utf8' }));
  const source = readFileSync(path.join(root, 'public/login.js'), 'utf8');
  const renderer = source.slice(source.indexOf('function el('), source.indexOf("document.getElementById('nav').addEventListener"));
  const browser = await chromium.launch({ headless: true, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}) });
  try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.route('**/*', route => route.abort()); // No application server or database access.
    await page.setContent('<!doctype html><html><body></body></html>');
    await page.addScriptTag({ content: `let currentParams = {}; window.submissions = []; async function runAction(endpoint, body) { window.submissions.push({endpoint,body}); }\n${renderer}` });
    const mount = async () => page.evaluate(block => document.body.replaceChildren(renderBlock(block, 'create-section')), block);
    for (const value of ['0', '1', '4', '11', '-1', '-10', '5.5', 'text', '']) {
      await mount();
      await page.locator('[name=max_Seats]').fill(value === 'text' ? '' : value);
      if (value === 'text') await page.locator('[name=max_Seats]').pressSequentially('text');
      assert.equal(await page.locator('[name=max_Seats]').evaluate(el => el.checkValidity()), false, `Accepted ${value}`);
      assert.ok(await page.locator('[name=max_Seats]').evaluate(el => el.validationMessage.length > 0));
      await page.locator('button').click();
      assert.equal(await page.evaluate(() => submissions.length), 0, `Submitted invalid ${value}`);
    }
    for (const value of ['5', '6', '9', '10']) {
      await mount();
      await page.locator('[name=max_Seats]').fill(value);
      await page.locator('button').click();
      const last = await page.evaluate(() => submissions.at(-1));
      assert.equal(last.endpoint, 'create-section');
      assert.equal(last.body.max_Seats, value);
      assert.equal(last.body.semester_ID, 'TEST');
      assert.equal(last.body.room_ID, 'TEST');
    }
    assert.deepEqual(errors, []);
    console.log('PASS: Chromium rejected 9 invalid inputs without submitting, accepted 4 valid inputs, preserved semester/room fields, and reported no script errors. No HTTP requests or database writes.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
