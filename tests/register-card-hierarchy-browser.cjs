const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const base = process.env.REGISTER_BASE || 'http://127.0.0.1:3020';
assert.match(base, /^http:\/\/(localhost|127\.0\.0\.1):\d+$/);
(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH });
  try {
    const context = await browser.newContext();
    await context.route('**/*', r => r.request().url().startsWith(base + '/') ? r.continue() : r.abort());
    const login = await context.request.post(base + '/api/login', { data: { email: '100001', role: 'Student', password: process.env.DEMO_PASSWORD } });
    assert.equal(login.status(), 200);
    const response = await context.request.get(base + '/api/views/register?semester=SP27&dept=&unmet=');
    assert.equal(response.status(), 200);
    const groups = (await response.json()).blocks.flatMap(b => b.courseGroups || []);
    assert.deepEqual(groups.find(g => g.code === 'BIO115').prerequisiteDetails, []);
    const page = await context.newPage();
    await page.goto(base + '/#register?semester=SP27&dept=&unmet=');
    const group = page.locator('details[data-course="BIO220"]');
    const summary = group.locator(':scope > summary');
    await summary.waitFor();
    assert.doesNotMatch(await summary.textContent(), /BIO115|Prerequisites/);
    if (await group.evaluate(e => e.open)) await summary.click();
    await summary.focus(); await page.keyboard.press('Enter');
    assert.equal(await group.evaluate(e => e.open), true);
    const rule = group.locator('.registration-prerequisite-rule');
    await rule.getByText('BIO115 · General Biology II', { exact: true }).waitFor();
    await rule.getByText('Minimum grade: D', { exact: true }).waitFor();
    assert.equal(await rule.locator('.registration-rule-state').textContent(), 'Met');
    assert.equal(await group.locator('.registration-course-content .registration-match').count(), 0);
    for (const width of [1440, 390]) {
      await page.setViewportSize({ width, height: 960 });
      await group.scrollIntoViewIfNeeded();
      assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
      const bounds = await rule.evaluate(e => ({ row: e.getBoundingClientRect().right, badge: e.querySelector('.registration-rule-state').getBoundingClientRect().right }));
      assert.ok(Math.abs(bounds.row - bounds.badge) < 2, 'Met badge is not at right edge');
      if (process.env.REGISTER_EVIDENCE_DIR) await page.screenshot({ path: `${process.env.REGISTER_EVIDENCE_DIR}/hierarchy-${width}.png` });
    }
    await summary.focus(); await page.keyboard.press('Enter');
    assert.equal(await group.evaluate(e => e.open), false);
    assert.equal(await page.getByRole('button', { name: 'Try', exact: true }).count(), 0);
    console.log('PASS actual demo API/browser: BIO115 unconfigured; BIO220 BIO115 D met; no duplicate summaries/matches; right-aligned Met; keyboard; desktop/mobile; no Try. No academic writes.');
  } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
