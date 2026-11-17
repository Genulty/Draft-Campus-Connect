// Live checks for a disposable, socket-only Create Section fixture.
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { createHash } = require('node:crypto');
const { chromium } = require('playwright');

const [base, socket, mode] = process.argv.slice(2);
assert.match(base, /^http:\/\/127\.0\.0\.1:[0-9]+$/);
assert.match(socket, /^\/.+\/campus-section-db\.[A-Za-z0-9]+\/mysql\.sock$/);
const password = process.env.SECTION_TEST_PASSWORD;
assert.ok(password, 'Use the isolated test runner or the seeded demo credentials');

const sql = query => execFileSync('mysql', [
  '--no-defaults', '--protocol=socket', `--socket=${socket}`, '-uroot', '-NBr', 'campus_connect',
], { input: query, encoding: 'utf8' }).trim();
assert.equal(sql(`SELECT @@skip_networking=1 AND @@server_id=732142 AND @@socket='${socket}'`), '1');

const fingerprint = () => createHash('sha256').update(sql(`
  SELECT * FROM Course_Section ORDER BY CRN;
  SELECT * FROM Course_Section_Lab ORDER BY CRN;
  SELECT * FROM Enrollment ORDER BY student_ID, CRN;
  SELECT * FROM Audit_Log ORDER BY log_ID;`)).digest('hex');

async function response(res, expected) {
  assert.equal(res.status(), expected, await res.text());
  return res.json();
}

async function run() {
  const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH });
  const login = async (id, role = 'Admin') => {
    const context = await browser.newContext();
    await context.route('**/*', route => route.request().url().startsWith(base + '/') ? route.continue() : route.abort());
    await response(await context.request.post(base + '/api/login',
      { data: { email: String(id), password, role } }), 200);
    return context;
  };
  const post = (context, data) => context.request.post(base + '/api/actions/create-section', { data });
  try {
    const write = await login(300001);
    const page = await write.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(base + '/#create-section?semester=SP27');
    const course = page.locator('select[name=course_ID]');
    const faculty = page.locator('select[name=faculty_ID]');
    const selectCourse = async (id, dept) => {
      const expected = sql(`SELECT faculty_ID FROM Faculty_Department WHERE dept_ID='${dept}'`).split('\n').sort();
      const ready = page.waitForResponse(r => r.url().includes('/api/views/create-section') && r.url().includes('course_ID=' + id));
      await course.selectOption(id);
      await ready;
      await faculty.waitFor();
      const options = await faculty.locator('option').evaluateAll(opts => opts.map(o => o.value).filter(Boolean).sort());
      assert.deepEqual(options, expected);
    };
    await selectCourse('CS110', 'CS');
    await faculty.selectOption('200003');
    await selectCourse('BIO110', 'BIO');
    assert.equal(await faculty.inputValue(), '');
    await selectCourse('CS110', 'CS');
    assert.equal(await faculty.inputValue(), '');
    await faculty.selectOption('200003');
    await page.locator('select[name=time_Slot_ID]').selectOption('9');
    await page.locator('select[name=room_ID]').selectOption('ACB-103');
    await page.locator('input[name=max_Seats]').fill('5');

    const beforeDemo = fingerprint();
    let previous;
    for (const [label, checkOnly] of [['Check conflicts', true], ['Create section', undefined]]) {
      const pending = page.waitForResponse(r => r.url().endsWith('/api/actions/create-section'));
      await page.getByRole('button', { name: label, exact: true }).click();
      const res = await pending;
      assert.equal(res.request().postDataJSON().check_only, checkOnly);
      const messages = (await response(res, 422)).error.split('\n');
      assert.equal(messages.length, 3);
      assert.match(messages[0], /limit is 2/);
      assert.match(messages[1], /already teaches.*10551/);
      assert.match(messages[2], /Room ACB-103.*10551/);
      if (previous) assert.deepEqual(messages, previous);
      previous = messages;
      await page.locator('.flash.err li').first().waitFor();
      assert.deepEqual(await page.locator('.flash.err li').allTextContents(), messages);
      assert.equal(await faculty.inputValue(), '200003');
      assert.equal(fingerprint(), beforeDemo);
    }
    assert.deepEqual(errors, []);
    console.log('PASS Chromium: faculty options matched the catalog department and switching cleared the instructor. Preview and save showed all three conflicts for CS110 / Carlos Miller / ACB-103 and left data unchanged.');
    if (mode === '--demo-only') return;

    const read = await login(300003);
    const student = await login(100001, 'Student');
    const first = { semester_ID: 'QAS28', course_ID: 'BIO110', faculty_ID: 200026, time_Slot_ID: 9101, room_ID: 'ACB-101', max_Seats: 5 };
    const otherFaculty = Number(sql("SELECT f.faculty_ID FROM Faculty f JOIN Faculty_Department fd USING(faculty_ID) WHERE fd.dept_ID='BIO' AND f.faculty_Type='Full-time' AND f.faculty_ID<>200026 ORDER BY f.faculty_ID LIMIT 1"));
    assert.ok(otherFaculty);
    sql(`
      INSERT INTO Semester VALUES ('QAS28','QA Scheduling 2028',2028,'2028-01-25','2028-05-14','2027-10-01','2028-02-01','2027-10-01','2028-02-15','2028-05-10','2028-05-28');
      INSERT INTO Period VALUES (9101,'07:40','09:00'),(9102,'09:00','10:20'),(9103,'10:40','12:00'),(9105,'10:20','12:50');
      INSERT INTO Time_Slot VALUES (9101),(9102),(9103),(9104),(9105);
      INSERT INTO Time_Slot_Day VALUES (9101,'M'),(9101,'W'),(9102,'M'),(9102,'W'),(9103,'M'),(9103,'W'),(9104,'T'),(9104,'R'),(9105,'M');
      INSERT INTO Time_Slot_Period VALUES (9101,9101),(9102,9102),(9103,9103),(9104,9101),(9105,9105);`);

    const before = fingerprint();
    for (const context of [read, student]) {
      for (const check_only of [true, false]) await response(await post(context, { ...first, check_only }), 403);
    }
    const malformed = await response(await post(write, { ...first, check_only: 'true' }), 422);
    assert.match(malformed.error, /must be a boolean/);
    assert.match((await response(await post(write, { ...first, check_only: true }), 200)).message, /No section was created/);
    assert.equal(fingerprint(), before);

    const created = await response(await post(write, first), 200);
    const crn = Number(created.message.match(/CRN (\d+)/)[1]);
    assert.equal(sql(`SELECT CONCAT_WS(':',faculty_ID,time_Slot_ID,room_ID,max_Seats,available_Seats) FROM Course_Section WHERE CRN=${crn}`), '200026:9101:ACB-101:5:5');

    const rejected = async (changes, patterns) => {
      const pre = fingerprint(), proposal = { ...first, ...changes };
      const preview = await response(await post(write, { ...proposal, check_only: true }), 422);
      const saved = await response(await post(write, proposal), 422);
      assert.equal(saved.error, preview.error);
      const lines = saved.error.split('\n');
      assert.equal(lines.length, patterns.length, saved.error);
      patterns.forEach((pattern, i) => assert.match(lines[i], pattern));
      assert.equal(fingerprint(), pre);
    };
    await rejected({ faculty_ID: 200003, time_Slot_ID: 9104 }, [/not a member of the BIO department/]);
    await rejected({ room_ID: 'ACB-102' }, [/already teaches/]);
    await rejected({ faculty_ID: otherFaculty }, [/Room ACB-101 is already used/]);
    sql(`INSERT INTO Course_Section_Lab VALUES (${crn},'NSC-105',9105);`);
    await rejected({ time_Slot_ID: 9103, room_ID: 'ACB-102' }, [/already teaches/]);
    await rejected({ time_Slot_ID: 9103, room_ID: 'NSC-105', faculty_ID: otherFaculty }, [/Room NSC-105 is already used/]);

    const preSecond = fingerprint(), second = { ...first, time_Slot_ID: 9102 };
    await response(await post(write, { ...second, check_only: true }), 200);
    assert.equal(fingerprint(), preSecond);
    const secondCrn = Number((await response(await post(write, second), 200)).message.match(/CRN (\d+)/)[1]);
    assert.equal(sql("SELECT COUNT(*) FROM Course_Section WHERE semester_ID='QAS28' AND faculty_ID=200026"), '2');
    assert.equal(sql(`SELECT time_Slot_ID FROM Course_Section WHERE CRN=${secondCrn}`), '9102');
    await rejected({ time_Slot_ID: 9104, room_ID: 'ACB-103' }, [/limit is 2/]);
    await rejected({}, [/limit is 2/, /already teaches/, /Room ACB-101 is already used/]);
    const schedule = await response(await write.request.get(base + '/api/views/master-schedule?semester=QAS28'), 200);
    assert.ok(schedule.blocks.find(b => b.title === 'Course sections').rows.some(row => row[0] === secondCrn));
    console.log('PASS HTTP/MySQL: permission gates, no-write preview, successful saves, second assignment allowed, third rejected, back-to-back accepted, lecture/lab conflicts isolated, and saved section visible in Master Schedule.');
  } finally {
    await browser.close();
  }
}
run().catch(error => { console.error(error); process.exitCode = 1; });
