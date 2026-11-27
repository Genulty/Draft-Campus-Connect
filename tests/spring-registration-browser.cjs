async function revealSection(page, crn) {
  const row = page.locator(`tr[data-crn="${crn}"]`);
  await row.waitFor({state:'attached'});
  const group = page.locator('details.registration-course-group').filter({has:row});
  if (await group.count() && !(await group.evaluate(e=>e.open))) await group.locator(':scope > summary').click();
  return group;
}
// Integration test for the disposable Spring 2027 demo on port 3003.
const assert = require('node:assert/strict');
const { spawnSync, execFileSync } = require('node:child_process');
const { randomBytes } = require('node:crypto');
const { chromium } = require('playwright');

const [socket, mode] = process.argv.slice(2);
const review = mode === '--student-review';
assert.match(socket, review ? /^\/.+\/campus-student-review-db\.[A-Za-z0-9]+\/mysql\.sock$/ : /^\/(?:private\/)?tmp\/campus-audit-preview\.[A-Za-z0-9]+\/mysql\.sock$/);
const base = process.env.REGISTER_BASE || (review ? 'http://127.0.0.1:3012' : 'http://localhost:3003');
const mysql = sql => execFileSync('mysql', [
  '--no-defaults', '--protocol=SOCKET', `--socket=${socket}`, '--user=root', '--batch', '--raw',
  '--skip-column-names', 'campus_connect',
], { input: sql, encoding: 'utf8' }).trim();
assert.equal(mysql(`SELECT @@socket = '${socket}' AND @@skip_networking = 1;`), '1');

if (review) assert.equal(mysql('SELECT @@server_id'), '732156');

const createStudent = (major, name) => {
  const password = 'Registration1-' + randomBytes(16).toString('hex');
  const result = spawnSync('php', ['-r', `
    require 'api/db.php';
    require 'api/accounts.php';
    require 'api/student.php';
    if (!one('SELECT 1 FROM DUAL WHERE @@socket = ? AND @@skip_networking = 1', [getenv('DB_SOCKET')])) exit(1);
    $form = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $admin = one("SELECT admin_ID FROM Admin WHERE security_Level = 'Read-Write' LIMIT 1");
    echo createUser($admin['admin_ID'], $form)['message'];
  `], {
    cwd: require('node:path').join(__dirname, '..'),
    env: { ...process.env, DB_HOST: 'localhost', DB_SOCKET: socket, DB_NAME: 'campus_connect',
      DB_USER: 'root', DB_PASSWORD: '', CAMPUS_CONFIG: socket + '.unused.php' },
    input: JSON.stringify({
      role: 'Student', first_Name: 'Registration', last_Name: name, gender: 'Other', DOB: '2008-01-01',
      level: 'Undergraduate', load: 'Full-time', major_ID: major, password, confirm: password,
    }),
    encoding: 'utf8',
  });
  assert.equal(result.status, 0, result.stderr || result.stdout);
  const id = Number(result.stdout.match(/\bID (\d+),/)[1]);
  return { id, password };
};

const tables = payload => Object.fromEntries(payload.blocks.filter(b => b.type === 'table').map(b => [b.title, b]));
const schedule = payload => tables(payload)['My Spring 2027 registration'].rows;
const stats = payload => payload.blocks.find(b => b.type === 'stats').items;
const creditLoad = payload => stats(payload).find(i => i.label === 'Registered credits').value;
const addable = payload => tables(payload)['Sections you can add'];
const blocked = payload => tables(payload)["Sections you can't add"];
const seats = () => mysql("SELECT CRN, available_Seats FROM Course_Section WHERE semester_ID='SP27' ORDER BY CRN;");

async function run() {
  const testAccounts = [];
  const originalSeats = seats();
  const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH });
  try {
    for (const [major, name] of [['CS-BS', 'Computing'], ['ENG-BS', 'English'], ['ECON-BS', 'Economics']]) {
      const account = createStudent(major, name);
      testAccounts.push(account);
      const context = await browser.newContext();
      await context.route('**/*', route => route.request().url().startsWith(base + '/') ? route.continue() : route.abort());
      const session = await context.request.post(base + '/api/login', {
        data: { email: String(account.id), password: account.password, role: 'Student' },
      });
      assert.equal(session.status(), 200, await session.text());
      const page = await context.newPage();
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      const department = page.locator('select[name=dept]');
      const load = async (dept = '') => {
        const response = await context.request.get(`${base}/api/views/register?semester=SP27&dept=${dept}`);
        assert.equal(response.status(), 200, await response.text());
        return response.json();
      };
      const submit = crn => context.request.post(base + '/api/actions/add-section', { data: { CRN: crn } });
      const selectDepartment = async value => {
        const loaded = page.waitForResponse(r => r.url().includes('/api/views/register'));
        await department.selectOption(value);
        assert.equal((await loaded).status(), 200);
        await department.waitFor();
        assert.equal(await department.inputValue(), value);
      };
      await page.goto(base + '/#register');
      await department.waitFor();
      assert.equal(await department.inputValue(), '');
      assert.equal(await department.locator('option:checked').textContent(), 'All Departments');
      assert.equal(creditLoad(await load()), '0 of 16');

      if (major === 'CS-BS') {
        for (const crn of [10541, 10542]) {
          assert.ok(addable(await load()).rows.some(row => row[0] === crn));
          const response = await submit(crn);
          assert.equal(response.status(), 200, await response.text());
        }
        const all = await load();
        assert.equal(creditLoad(all), '6 of 16');
        assert.equal(addable(await load('CS')).rows.length, 0);
        const otherCsSections = Number(mysql(`SELECT COUNT(*) FROM Course_Section cs
          JOIN Course c USING(course_ID)
          WHERE cs.semester_ID='SP27' AND c.dept_ID='CS' AND c.course_Type='Undergraduate'
            AND cs.CRN NOT IN (SELECT CRN FROM Enrollment WHERE student_ID=${account.id})`));
        assert.equal(blocked(await load('CS')).rows.length, otherCsSections);
        await page.reload();
        await department.waitFor();
        await selectDepartment('CS');
        await page.getByText('No eligible sections in Computer Science', { exact: true }).waitFor();
        const loaded = page.waitForResponse(r => r.url().includes('/api/views/register'));
        await page.getByRole('button', { name: 'Show all departments', exact: true }).click();
        assert.equal((await loaded).status(), 200);
        await department.waitFor();
        assert.equal(await department.inputValue(), '');
        for (const dept of ['CS', 'MATH', 'ENG']) {
          assert.deepEqual(schedule(await load(dept)), schedule(all));
          assert.equal(creditLoad(await load(dept)), '6 of 16');
        }
        for (const crn of [10565, 10597, 10569]) {
          assert.ok(addable(await load()).rows.some(row => row[0] === crn), `Expected CRN ${crn} to fit`);
          const response = await submit(crn);
          assert.equal(response.status(), 200, await response.text());
          if (crn === 10597) assert.equal(creditLoad(await load()), '12 of 16');
        }
        const atCap = await load();
        assert.equal(creditLoad(atCap), '16 of 16');
        const capRow = blocked(atCap).rows.find(row => /limit for full-time students is 16/.test(row.at(-1)));
        assert.ok(capRow);
        const seatSnapshot = seats();
        const overCap = await submit(capRow[0]);
        assert.equal(overCap.status(), 422);
        assert.equal((await overCap.json()).error, capRow.at(-1));
        assert.deepEqual(schedule(await load()), schedule(atCap));
        assert.equal(seats(), seatSnapshot);
      } else {
        await selectDepartment('CS');
        const all = await load();
        const cs = await load('CS');
        // A degree requirement is not an access restriction; no explicit rule is configured here.
        assert.ok(addable(cs).rows.some(row=>row[0]===10551));
        const seatSnapshot = seats();
        const allowed = await submit(10551); assert.equal(allowed.status(),200,await allowed.text());
        const dropped = await context.request.post(base+'/api/actions/drop-section',{data:{CRN:10551}});
        assert.equal(dropped.status(),200,await dropped.text());
        assert.equal(creditLoad(await load()),'0 of 16');assert.equal(seats(),seatSnapshot);

        await selectDepartment('BIO');
        const biology = addable(await load('BIO'));
        const bio110 = biology.rows.find(row => row[0] === 10579);
        assert.match(bio110[biology.columns.indexOf('Requirement match')], /Natural Sciences/);
        assert.ok(bio110[biology.columns.indexOf('Lab time')]);
        const offered = page.locator('.table-card').filter({ has: page.getByRole('heading', { name: 'Sections you can add', exact: true }) });
        await revealSection(page,10579);
        const row = offered.locator('tr[data-crn="10579"]');
        const accepted = page.waitForResponse(r => r.url().endsWith('/api/actions/add-section'));
        await row.getByRole('button', { name: 'Add', exact: true }).click();
        assert.equal((await accepted).status(), 200);
        assert.equal(creditLoad(await load()), '4 of 16');
        assert.ok(schedule(await load()).some(row => row[0] === 10579));
      }
      assert.deepEqual(errors, []);
      await context.close();
      console.log(`PASS: ${major} Chromium and HTTP registration flow`);
    }
  } finally {
    await browser.close();
    if (testAccounts.length) {
      const ids = testAccounts.map(account => account.id).join(',');
      mysql(`START TRANSACTION;
        DELETE FROM Enrollment WHERE student_ID IN (${ids});
        DELETE FROM Audit_Log WHERE user_ID IN (${ids});
        DELETE FROM User WHERE user_ID IN (${ids});
        COMMIT;`);
    }
    assert.equal(seats(), originalSeats, 'Fixture cleanup changed the original seat counts');
  }
}
run().catch(error => { console.error(error); process.exitCode = 1; });
