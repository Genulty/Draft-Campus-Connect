// Live Chromium and API checks. Institutional writes run only in a new disposable fixture.
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { createHash } = require('node:crypto');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');
const [base, socket, mode] = process.argv.slice(2);
assert.match(base, /^http:\/\/127\.0\.0\.1:[0-9]+$/);
assert.match(socket, /^\/.+\/campus-admin-db\.[A-Za-z0-9]+\/mysql\.sock$/);

const sql = query => execFileSync('mysql', [
  '--no-defaults', '--protocol=socket', `--socket=${socket}`, '-uroot', '-NBr', 'campus_connect',
], { input: query, encoding: 'utf8' }).trim();
assert.equal(sql(`SELECT @@skip_networking=1 AND @@server_id=732141 AND @@socket='${socket}'`), '1');

function fingerprint() {
  const tables = {
    User: 'user_ID', Admin: 'admin_ID', Audit_Log: 'log_ID', Course: 'course_ID',
    Course_Prerequisite: 'course_ID, prerequisite_Course_ID', Course_Section: 'CRN',
    Course_Section_Lab: 'CRN', Enrollment: 'student_ID, CRN', Student_Hold: 'student_ID, hold_ID',
    Department: 'dept_ID', Email_Message: 'message_ID', Password_Reset: 'reset_ID',
  };
  const query = Object.entries(tables).map(([table, order]) => `SELECT * FROM ${table} ORDER BY ${order};`).join('\n');
  return createHash('sha256').update(sql(query)).digest('hex');
}

async function request(response, status = 200, message = null) {
  assert.equal(response.status(), status, await response.text());
  const data = await response.json();
  if (message) assert.match(data.error ?? data.message, message);
  return data;
}
const table = (payload, title) => payload.blocks.find(b => b.title === title);
const byEndpoint = (payload, endpoint) => payload.blocks.find(b => b.endpoint === endpoint);

async function run() {
  const password = process.env.ADMIN_TEST_PASSWORD;
  assert.ok(password, 'Start the isolated test runner or supply its fixture password');
  const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH });
  const errors = [];
  const context = async (id, role) => {
    const c = await browser.newContext();
    await c.route('**/*', route => route.request().url().startsWith(base + '/') ? route.continue() : route.abort());
    if (id) await request(await c.request.post(base + '/api/login',
      { data: { email: String(id), password, role } }));
    return c;
  };
  const view = async (c, route, status) => request(await c.request.get(base + '/api/views/' + route), status);
  const post = async (c, route, data, status, message) =>
    request(await c.request.post(base + '/api/actions/' + route, { data }), status, message);
  const openPage = async (c, route) => {
    const page = await c.newPage();
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(base + '/#' + route);
    return page;
  };
  const noWrites = /Read-Only/;

  try {
    const read = await context(300003, 'Admin');
    const write = await context(300001, 'Admin');
    assert.deepEqual(await request(await read.request.get(base + '/api/views')),
      await request(await write.request.get(base + '/api/views')));
    const readOverview = await view(read, 'admin-overview');
    const writeOverview = await view(write, 'admin-overview');
    assert.match(readOverview.subtitle, noWrites);
    assert.match(writeOverview.subtitle, /Read-Write/);
    assert.deepEqual(table(readOverview, 'Departments').rows, table(writeOverview, 'Departments').rows);

    const readPage = await openPage(read, 'update-user-info');
    await readPage.getByText(/Read-Only access/).waitFor();
    await readPage.getByRole('textbox', { name: 'User ID or campus email' }).fill('100014');
    await readPage.getByRole('button', { name: 'Find', exact: true }).click();
    const readEdit = readPage.getByRole('button', {name: 'Save changes', exact: true});
    await readEdit.waitFor();
    assert.ok(await readEdit.isDisabled());
    assert.ok(await readPage.getByRole('textbox', { name: 'First name', exact: true }).isDisabled());
    const originalName = await readPage.getByRole('textbox', {name: 'First name', exact: true}).inputValue();
    assert.ok(originalName);
    const observeWrites = [];
    readPage.on('request', r => { if (r.method() === 'POST') observeWrites.push(r.url()); });
    await readEdit.locator('..').dispatchEvent('submit');
    assert.deepEqual(observeWrites, []);
    await readPage.reload();
    await readEdit.waitFor();
    assert.equal(await readPage.getByRole('textbox', {name: 'First name', exact: true}).inputValue(), originalName);

    await readPage.goto(base + '/#manage-departments');
    await readPage.locator('select[name=edit]').selectOption('BIO');
    await readPage.getByRole('heading', { name:'Edit Biology', exact:true }).waitFor();
    assert.ok(await readPage.locator('select[name=edit]').isEnabled());
    assert.ok(await readPage.getByRole('button', {name:'Save changes',exact:true}).isDisabled());

    await readPage.goto(base + '/#edit-section');
    const section = readPage.locator('form').filter({ has: readPage.getByRole('heading', { name:'Reassign faculty',exact:true }) }).locator('select[name=CRN]');
    await section.selectOption('10579');
    await readPage.waitForURL(/CRN=10579/);
    await readPage.getByRole('button', {name:'Reassign',exact:true}).waitFor();
    assert.ok(await section.isEnabled());
    assert.ok(await readPage.getByRole('button', {name:'Reassign',exact:true}).isDisabled());
    assert.ok(await readPage.getByRole('button', {name:'Change time slot',exact:true}).isDisabled());

    await readPage.goto(base + '/#admin-register?semester=SP27');
    await readPage.getByRole('spinbutton', {name:'Student ID',exact:true}).fill('100014');
    await readPage.getByRole('button', {name:'Find',exact:true}).click();
    await readPage.getByRole('heading', {name:'Fall 2026 schedule',exact:true}).waitFor();
    await readPage.locator('select[name=dept]').selectOption('BIO');
    await readPage.waitForURL(/student_ID=100014/);
    await readPage.getByRole('heading', {name:'Fall 2026 schedule',exact:true}).waitFor();
    assert.equal(await readPage.locator('select[name=semester]').inputValue(), 'SP27');
    assert.equal(await readPage.locator('select[name=dept]').inputValue(), 'BIO');
    assert.equal(await readPage.getByRole('button', {name:'Register',exact:true}).count(), 0);
    assert.deepEqual(observeWrites, []);
    const readLookup = await view(read, 'admin-register?student_ID=100014&semester=SP27&dept=BIO');
    const writeLookup = await view(write, 'admin-register?student_ID=100014&semester=SP27&dept=BIO');
    assert.deepEqual(table(readLookup, 'Fall 2026 schedule'), table(writeLookup, 'Fall 2026 schedule'));
    assert.deepEqual(table(readLookup, 'Course sections (to find a CRN)'), table(writeLookup, 'Course sections (to find a CRN)'));
    assert.equal(byEndpoint(readLookup, 'register-student'), undefined);
    assert.ok(byEndpoint(writeLookup, 'register-student'));

    const adminViews = [
      'create-course', 'create-section', 'create-user', 'reset-password', 'manage-departments',
      'edit-section', 'cancel-sections', 'admin-register', 'holds', 'update-user-info?user=100014',
    ];
    for (const route of adminViews) {
      const payload = await view(read, route);
      assert.match(payload.subtitle, noWrites);
      assert.ok(payload.blocks.every(b => !b.endpoint || b.disabled));
      assert.ok(payload.blocks.every(b => !b.action || b.action.disabled));
    }
    console.log('PASS A-R2/A-R3: Admin read views match; browser user/department/section/student lookup works; institutional write controls hidden or disabled.');
    if (mode === '--view-only') {
      assert.deepEqual(errors, []);
      return;
    }

    const student = await context(100001, 'Student');
    const faculty = await context(200001, 'Faculty');
    const stats = await context(400001, 'StatDept');
    const anonymous = await context();
    for (const c of [student, faculty, stats]) await view(c, 'update-user-info?user=100014', 404);
    await view(anonymous, 'update-user-info?user=100014', 401);

    const user = byEndpoint(await view(write, 'update-user-info?user=100014'), 'update-user-info');
    const readUser = byEndpoint(await view(read, 'update-user-info?user=100014'), 'update-user-info');
    assert.deepEqual(readUser.fields, user.fields);
    const update = Object.fromEntries(user.fields.map(({ name, value }) => [name, value]));
    update.street = '45 Fixture Lane';
    update.city = 'Hempstead';
    update.state = 'NY';
    update.zip_Code = '11550';
    const hold = { student_ID: 100014, hold_Type: 'Financial' };
    const enrollment = { student_ID: 100014, CRN: 10579 };
    const payloads = {
      'create-course': {}, 'create-section': {}, 'create-user': {}, 'send-password-reset': { login: '100014' },
      'upsert-department': { dept_ID: 'QAFIXTURE', dept_Name: 'Admin QA Fixture' },
      'reassign-faculty': { CRN: 10579, faculty_ID: 200029 },
      'update-section-time-slot': { CRN: 10579, time_Slot_ID: 1 },
      'cancel-section': { CRN: 10579 }, 'register-student': enrollment,
      'place-hold': hold, 'remove-hold': { student_ID: 100019, hold_Type:'Academic' }, 'update-user-info': update,
    };
    const router = readFileSync(path.resolve(__dirname, '../api/index.php'), 'utf8');
    const governed = [...router.matchAll(/'([a-z-]+)'\s*=>\s*\['Admin'\]/g)].map(matches => matches[1]);
    assert.deepEqual([...governed].sort(), Object.keys(payloads).sort());
    const before = fingerprint();
    for (const [route, payload] of Object.entries(payloads)) {
      await post(read, route, payload, 403, noWrites);
      for (const c of [student, faculty, stats]) await post(c, route, payload, 403, /Your role/);
      await post(anonymous, route, payload, 401);
    }
    assert.equal(fingerprint(), before, 'Denied requests changed the database');
    console.log(`PASS A-F2: ${governed.length} Admin-only routes denied Read-Only, Student, Faculty, StatDept and anonymous requests; institutional records, reset rows and audit logs unchanged.`);

    const writePage = await openPage(write, 'update-user-info?user=100014');
    const street = writePage.getByRole('textbox', { name:'Street', exact:true });
    await street.waitFor();
    assert.ok(await street.isEnabled());
    assert.ok(await writePage.getByRole('button', {name:'Save changes',exact:true}).isEnabled());
    await post(write, 'update-user-info', update, 200, /Updated information/);
    assert.equal(sql('SELECT street FROM User WHERE user_ID=100014;'), update.street);
    await writePage.reload();
    await writePage.waitForFunction(value => document.querySelector('input[name=street]')?.value === value, update.street);
    assert.equal(sql("SELECT new_Value FROM Audit_Log WHERE admin_ID=300001 AND user_ID=100014 AND field_Name='User.street' ORDER BY log_ID DESC LIMIT 1;"), update.street);
    await street.fill('47 Fixture Lane');
    const save = writePage.waitForResponse(r => r.url().endsWith('/api/actions/update-user-info'));
    await writePage.getByRole('button', {name:'Save changes',exact:true}).click();
    await request(await save, 200, /Updated information/);
    await writePage.waitForFunction(() => document.querySelector('input[name=street]')?.value === '47 Fixture Lane');
    assert.match(new URL(writePage.url()).hash, /user=100014/);
    assert.equal(sql('SELECT street FROM User WHERE user_ID=100014;'), '47 Fixture Lane');
    console.log('PASS A-R4/A-R6/A-R43/A-R44: same valid update denied for Read-Only and persisted for Read-Write after Chromium refresh; audit entry saved. The browser Save control also saved and kept the selected record.');

    await post(write, 'upsert-department', payloads['upsert-department'], 200, /Created/);
    assert.equal(sql("SELECT dept_Name FROM Department WHERE dept_ID='QAFIXTURE';"), 'Admin QA Fixture');
    await post(write, 'place-hold', hold, 200, /Placed/);
    assert.ok(table(await view(read, 'holds'), 'Active holds').rows.some(([id, , type]) => id === 100014 && type === 'Financial'));
    const held = fingerprint();
    await post(read, 'remove-hold', hold, 403, noWrites);
    assert.equal(fingerprint(), held);
    await post(write, 'register-student', enrollment, 422, /hold/);
    await post(write, 'remove-hold', hold, 200, /Removed/);
    assert.equal(sql("SELECT COUNT(*) FROM Student_Hold sh JOIN Hold h USING(hold_ID) WHERE student_ID=100014 AND hold_Type='Financial';"), '0');
    const seats = Number(sql('SELECT available_Seats FROM Course_Section WHERE CRN=10579;'));
    await post(write, 'register-student', enrollment, 200, /Registered/);
    assert.equal(sql('SELECT COUNT(*) FROM Enrollment WHERE student_ID=100014 AND CRN=10579;'), '1');
    assert.equal(Number(sql('SELECT available_Seats FROM Course_Section WHERE CRN=10579;')), seats - 1);
    const registered = fingerprint();
    await post(read, 'cancel-section', { CRN:10579 }, 403, noWrites);
    assert.equal(fingerprint(), registered);
    await post(write, 'cancel-section', { CRN:10579 }, 200, /notified 1 student/);
    assert.equal(sql('SELECT COUNT(*) FROM Course_Section WHERE CRN=10579;'), '0');
    assert.equal(sql('SELECT COUNT(*) FROM Enrollment WHERE student_ID=100014 AND CRN=10579;'), '0');
    assert.equal(sql("SELECT COUNT(*) FROM Email_Message WHERE user_ID=100014 AND subject LIKE '%CRN 10579%cancelled';"), '1');
    console.log('PASS create/delete/workflows: Read-Write created a department, placed and removed a hold, registered an eligible student, and cancelled the under-enrolled fixture section with an internal notice. Read-Only and held-student attempts were refused.');

    await post(write, 'send-password-reset', payloads['send-password-reset'], 422, /delivery is unavailable/);
    console.log('PASS reset gate: Read-Only 403; Read-Write reached the normal delivery-disabled guard. Secure delivery was not tested.');
    assert.deepEqual(errors, []);
  } finally {
    await browser.close();
  }
}
run().catch(error => { console.error(error); process.exitCode = 1; });
