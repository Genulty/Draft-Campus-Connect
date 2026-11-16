const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');

const [base, socket] = process.argv.slice(2);
assert.match(base, /^http:\/\/127\.0\.0\.1:[0-9]+$/);
assert.match(socket, /^\/(?:private\/)?tmp\/campus-audit-preview\.[A-Za-z0-9]+\/mysql\.sock$/);
const mysql = sql => execFileSync('mysql', [
  '--no-defaults', '--protocol=SOCKET', `--socket=${socket}`, '--user=root', '--batch', '--raw',
  '--skip-column-names', 'campus_connect',
], { input: sql, encoding: 'utf8' }).trim();

function academicSnapshot(student) {
  assert.ok(Number.isSafeInteger(student));
  return mysql(`
    SELECT * FROM Student WHERE student_ID=${student};
    SELECT * FROM Undergraduate_Student WHERE student_ID=${student};
    SELECT * FROM Full_Time_Undergraduate WHERE student_ID=${student};
    SELECT * FROM Student_Major WHERE student_ID=${student} ORDER BY major_ID;
    SELECT * FROM Student_Minor WHERE student_ID=${student} ORDER BY minor_ID;
    SELECT * FROM Student_History WHERE student_ID=${student} ORDER BY CRN;
    SELECT * FROM Enrollment WHERE student_ID=${student} ORDER BY CRN;
    SELECT * FROM Advisor WHERE student_ID=${student} ORDER BY faculty_ID;
  `);
}

async function main() {
  assert.equal(mysql(`SELECT @@socket = '${socket}' AND @@skip_networking = 1;`), '1');
  mysql(`
    INSERT INTO Major VALUES ('NOMAP-BS', 'BIO', 'Catalog test major without rules');
    INSERT INTO User (user_ID, first_Name, last_Name, user_Type) VALUES (990009, 'Catalog', 'Fixture', 'Student');
    INSERT INTO Student VALUES (990009, 'NOMAP-BS', 'Freshman', 'Undergraduate');
  `);

  const browser = await chromium.launch({
    headless: true, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}),
  });
  try {
    const context = await browser.newContext();
    await context.route('**/*', route => route.request().url().startsWith(base + '/') ? route.continue() : route.abort());
    const page = await context.newPage();
    const errors = [];
    const writes = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('request', request => {
      if (request.url().includes('/api/') && request.method() !== 'GET') writes.push(request.url());
    });
    await page.goto(base + '/');
    await page.locator('[data-go=login]').click();
    const login = page.waitForResponse(r => r.url().endsWith('/api/login'));
    await page.locator('[data-email="student@campus.edu"]').click();
    assert.equal((await login).status(), 200);

    const get = async path => {
      const response = await context.request.get(base + '/api/views/' + path);
      assert.equal(response.status(), 200, await response.text());
      return response.json();
    };
    const load = async (query = '') => (await get('degree-audit' + query)).blocks.find(block => block.type === 'degree-audit');
    const action = async (name, data) => {
      const response = await context.request.post(base + '/api/actions/' + name, { data });
      assert.equal(response.status(), 200, await response.text());
    };
    const primary = audit => audit.programs.find(p => p.is_primary);
    assert.equal(primary((await load()).audit).id, 'CS-BS');
    await action('add-major', { major_ID: 'MATH-BS' });
    await action('add-minor', { minor_ID: 'PSY-MIN' });

    const baselineBlock = await load();
    const current = baselineBlock.audit;
    const student = current.profile.id;
    const transcript = await get('transcript');
    const snapshot = academicSnapshot(student);
    assert.ok(Object.hasOwn(baselineBlock.major_options, 'ECON-BS'));
    for (const id of ['CS-BS', 'MATH-BS', 'CS-MS']) assert.ok(!Object.hasOwn(baselineBlock.major_options, id));
    const startWrites = writes.length;

    const section = name => page.locator('section.audit-section').filter({
      has: page.getByRole('heading', { name, exact: true }),
    });
    const major = section('Major Requirements');
    await page.goto(base + '/#degree-audit');
    await major.getByText('B.S. in Computer Science', { exact: true }).waitFor();
    await page.getByRole('combobox', { name: 'Major to preview' }).selectOption('ECON-BS');
    await page.getByText('Previewing B.A. in Economics. Your major hasn’t changed.', { exact: true }).waitFor();
    await major.getByText('B.A. in Economics', { exact: true }).waitFor();
    await major.getByText('B.S. in Mathematics', { exact: true }).waitFor();
    await section('Minor requirements').getByText('Minor in Psychology', { exact: true }).waitFor();
    await section('Total credits earned').getByText('50 credits', { exact: true }).waitFor();
    await section('What still counts').getByText(/draft/).first().waitFor();
    await section('What you’d still need').getByText('Degree credit requirements · Rules unavailable', { exact: true }).waitFor();

    const { audit: preview } = await load('?preview_major=ECON-BS&student=990009');
    assert.equal(preview.profile.id, student);
    assert.equal(primary(preview).id, 'ECON-BS');
    assert.equal(primary(preview).requirements.length, 8);
    assert.deepEqual(preview.programs.filter(p => !p.is_primary), current.programs.filter(p => !p.is_primary));
    for (const key of ['credits', 'history', 'in_progress', 'future_registered', 'pending_records', 'gen_ed']) {
      assert.deepEqual(preview[key], current[key], key);
    }
    assert.equal(preview.degree_requirements.status, 'Rules unavailable');
    assert.ok(preview.preview.lost_uses.some(course => course.code === 'CS110'));
    assert.ok(preview.preview.still_counts.some(course => course.requirements.some(rule => rule.endsWith('(draft)'))));
    assert.ok(!preview.preview.needed.some(rule => rule.name === 'Introduction to Programming'));

    await page.getByRole('button', { name: 'Back to my current audit', exact: true }).click();
    await major.getByText('B.S. in Computer Science', { exact: true }).waitFor();
    assert.deepEqual((await load()).audit, current);

    await page.getByRole('combobox', { name: 'Major to preview' }).selectOption('NOMAP-BS');
    await section('What you’d still need').getByText('Rules unavailable', { exact: true }).waitFor();
    const { audit: noRules } = await load('?preview_major=NOMAP-BS');
    assert.deepEqual(primary(noRules).requirements, []);
    assert.equal(noRules.preview.primary_status, 'Rules unavailable');
    assert.equal(noRules.credits.earned_provisional, 50);

    for (const query of ['preview_major=UNKNOWN', 'preview_major=CS-MS', 'preview_major=MATH-BS', 'preview_major=CS-BS',
      'preview_major%5B%5D=ECON-BS']) {
      assert.equal((await context.request.get(base + '/api/views/degree-audit?' + query)).status(), 422);
    }
    const anonymous = await browser.newContext();
    assert.equal((await anonymous.request.get(base + '/api/views/degree-audit?preview_major=ECON-BS')).status(), 401);
    await anonymous.close();
    assert.equal(writes.length, startWrites);
    assert.equal(academicSnapshot(student), snapshot);
    assert.deepEqual(await get('transcript'), transcript);

    await page.goto(base + '/#my-programs');
    const form = page.locator('form').filter({ has: page.getByRole('heading', { name: 'Change a major', exact: true }) });
    await form.locator('select[name=from_ID]').selectOption('CS-BS');
    await form.locator('select[name=major_ID]').selectOption('ECON-BS');
    const changed = page.waitForResponse(r => r.url().endsWith('/api/actions/change-major'));
    await form.getByRole('button', { name: 'Change major', exact: true }).click();
    const response = await changed;
    assert.equal(response.status(), 200, await response.text());
    const { preview: summary, ...simulated } = preview;
    assert.deepEqual((await load()).audit, simulated);
    assert.deepEqual(await get('transcript'), transcript);
    await page.goto(base + '/#degree-audit');
    await major.getByText('B.A. in Economics', { exact: true }).waitFor();
    assert.deepEqual(errors, []);
    console.log('PASS: CS to Economics preview and real change agree, 50 earned credits and transcript unchanged, preserved secondary major and minor, unavailable and invalid rules, unauthenticated rejection, no preview writes.');
  } finally {
    await browser.close();
  }
}

main().catch(error => { console.error(error); process.exitCode = 1; });
