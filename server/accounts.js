// Student sign-up and password reset.
const crypto = require('crypto');
const { db, hashPassword } = require('./db');

class AccountError extends Error {}
const fail = (msg) => { throw new AccountError(msg); };

const RESET_AFTER = 3;          // a password reset is offered after 3 failed sign-in attempts
const MAX_ADVISEES = 15;        // F-F9
const NAME = /^[A-Za-z][A-Za-z' -]{0,49}$/;

function checkPassword(password, confirm) {
  if (typeof password !== 'string' || password.length < 8 || !/[A-Za-z]/.test(password) || !/\d/.test(password)) {
    fail('Password must be at least 8 characters and include a letter and a number.');
  }
  if (password !== confirm) fail('The two passwords do not match.');
}

async function transaction(work) {
  const conn = await db.getConnection();
  try {
    await conn.beginTransaction();
    const result = await work(conn);
    await conn.commit();
    return result;
  } catch (err) {
    await conn.rollback();
    throw err;
  } finally {
    conn.release();
  }
}

// ---------- Sign up as a new undergraduate student ----------
async function signUp(form) {
  const clean = (v) => (typeof v === 'string' ? v.trim() : '');
  const first = clean(form.first_Name), middle = clean(form.middle_Name), last = clean(form.last_Name);
  if (!NAME.test(first)) fail('Enter your first name (letters only).');
  if (middle && !NAME.test(middle)) fail('Middle name can only contain letters.');
  if (!NAME.test(last)) fail('Enter your last name (letters only).');
  if (!['Male', 'Female', 'Other'].includes(form.gender)) fail('Choose a gender.');
  const dob = clean(form.DOB);
  const born = new Date(`${dob}T00:00:00Z`);
  if (!/^\d{4}-\d{2}-\d{2}$/.test(dob) || Number.isNaN(born.getTime())) fail('Enter your date of birth.');
  const age = (Date.now() - born.getTime()) / (365.25 * 24 * 3600 * 1000);
  if (age < 14 || age > 100) fail('Date of birth must make you between 14 and 100 years old.');
  const street = clean(form.street) || null, city = clean(form.city) || null;
  const state = clean(form.state).toUpperCase() || null, zip = clean(form.zip_Code) || null;
  if (state && !/^[A-Z]{2}$/.test(state)) fail('State must be a 2-letter code, like NY.');
  if (zip && !/^\d{5}$/.test(zip)) fail('ZIP code must be 5 digits.');
  if (!['Full-time', 'Part-time'].includes(form.type)) fail('Choose full-time or part-time.');
  checkPassword(form.password, form.confirm);

  return transaction(async (conn) => {
    const q = async (sql, params) => (await conn.query(sql, params))[0];
    const run = (table, cols, values) =>
      q(`INSERT INTO ${table} (${cols.join(', ')}) VALUES (${cols.map(() => '?').join(', ')})`, values);

    const [major] = await q(`SELECT m.major_ID, m.major_Name, m.dept_ID FROM Major m
      WHERE m.major_ID = ? AND m.major_ID IN (SELECT major_ID FROM Major_Course_Requirement r JOIN Course c ON c.course_ID = r.course_ID
        WHERE c.course_Type = 'Undergraduate')`, [form.major_ID]);
    if (!major) fail('Choose an undergraduate major.');

    // A random, unused 6-digit student ID in the student range (100000–199999).
    let id;
    for (let i = 0; i < 50 && !id; i++) {
      const candidate = crypto.randomInt(100000, 200000);
      const [taken] = await q('SELECT user_ID FROM User WHERE user_ID = ? FOR UPDATE', [candidate]);
      if (!taken) id = candidate;
    }
    if (!id) fail('Could not pick a student ID. Please try again.');

    // Campus email from the name: first.last@campus.edu, with a number added if it is taken.
    const base = `${first}.${last}`.toLowerCase().replace(/[^a-z.]/g, '');
    let email = `${base}@campus.edu`;
    for (let n = 2; (await q('SELECT 1 FROM Login WHERE user_Email = ? FOR UPDATE', [email])).length; n++) email = `${base}${n}@campus.edu`;

    const today = new Date().toISOString().slice(0, 10);
    await run('User', ['user_ID', 'first_Name', 'middle_Name', 'last_Name', 'gender', 'DOB', 'street', 'city', 'state', 'zip_Code', 'user_Type'],
      [id, first, middle || null, last, form.gender, dob, street, city, state, zip, 'Student']);
    await run('Login', ['user_ID', 'user_Email', 'user_Password', 'no_Of_Tries', 'lock_Var'], [id, email, hashPassword(form.password), 0, 0]);
    await run('Student', ['student_ID', 'student_Year', 'student_Type'], [id, 'Freshman', 'Undergraduate']);
    await run('Undergraduate_Student', ['student_ID', 'dept_ID', 'undergraduate_Student_Type'], [id, major.dept_ID, form.type]);
    const fullTime = form.type === 'Full-time';
    await run(fullTime ? 'Full_Time_Undergraduate' : 'Part_Time_Undergraduate',
      ['student_ID', 'status', 'min_Credits', 'max_Credits', 'credits_Earned'], [id, 'Good Standing', fullTime ? 12 : 1, fullTime ? 16 : 8, 0]);
    await run('Student_Major', ['student_ID', 'major_ID', 'date_Of_Choice'], [id, major.major_ID, today]);

    // Advisor: the full-time faculty member in the major's department with the fewest advisees (F-F9, F-F10).
    const [advisor] = await q(`SELECT f.faculty_ID, CONCAT(u.first_Name, ' ', u.last_Name) AS name,
        (SELECT COUNT(*) FROM Advisor a WHERE a.faculty_ID = f.faculty_ID) AS n
      FROM Faculty f JOIN Faculty_Department fd ON fd.faculty_ID = f.faculty_ID JOIN User u ON u.user_ID = f.faculty_ID
      WHERE fd.dept_ID = ? AND f.faculty_Type = 'Full-time'
      HAVING n < ? ORDER BY n, f.faculty_ID LIMIT 1 FOR UPDATE`, [major.dept_ID, MAX_ADVISEES]);
    if (advisor) await run('Advisor', ['faculty_ID', 'student_ID', 'date_Of_Appointment'], [advisor.faculty_ID, id, today]);

    return { id, email, name: `${first} ${last}`, major: major.major_Name, advisor: advisor ? advisor.name : null };
  });
}

// ---------- Reset a password after 3 failed sign-in attempts (S-R2, UC-3) ----------
// Verification: the account's user ID and date of birth must match (S-F2).
const verifyFailures = new Map(); // login user_ID -> { count, until }

async function resetPassword(form) {
  const loginName = typeof form.login === 'string' ? form.login.trim() : '';
  const [[login]] = await db.query(`SELECT l.user_ID, l.no_Of_Tries, l.lock_Var, u.DOB FROM Login l JOIN User u ON u.user_ID = l.user_ID
    WHERE ${/^\d+$/.test(loginName) ? 'l.user_ID' : 'l.user_Email'} = ?`, [loginName]);
  if (!login) fail('No account matches that email or ID.');
  if (login.no_Of_Tries < RESET_AFTER && !login.lock_Var) fail(`Password reset is available after ${RESET_AFTER} failed sign-in attempts.`);

  const block = verifyFailures.get(login.user_ID);
  if (block && block.count >= 5 && block.until > Date.now()) fail('Too many failed verification attempts. Try again in 15 minutes.');
  if (String(form.user_ID).trim() !== String(login.user_ID) || String(form.DOB).trim() !== login.DOB) {
    const count = (block && block.until > Date.now() ? block.count : 0) + 1;
    verifyFailures.set(login.user_ID, { count, until: Date.now() + 15 * 60 * 1000 });
    fail('The ID and date of birth do not match this account.');
  }
  checkPassword(form.password, form.confirm);

  const hash = hashPassword(form.password);
  await db.query('UPDATE Login SET user_Password = ?, no_Of_Tries = 0, lock_Var = 0 WHERE user_ID = ?', [hash, login.user_ID]);
  verifyFailures.delete(login.user_ID);
  return { message: 'Your password was reset and the account is unlocked. You can sign in now.' };
}

// Undergraduate majors for the sign-up form.
async function undergraduateMajors() {
  const [rows] = await db.query(`SELECT m.major_ID, m.major_Name FROM Major m
    WHERE EXISTS (SELECT 1 FROM Major_Course_Requirement r JOIN Course c ON c.course_ID = r.course_ID
      WHERE r.major_ID = m.major_ID AND c.course_Type = 'Undergraduate')
    ORDER BY m.major_Name`);
  return rows;
}

module.exports = { signUp, resetPassword, undergraduateMajors, AccountError, RESET_AFTER };
