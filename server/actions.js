// Actions that change the database: a student adds or drops a course section,
// and an admin creates a course section. Every SRS rule is checked before anything is
// written; a broken rule returns a message naming the requirement (e.g. S-F4).
const { db } = require('./db');

class RuleError extends Error {}
const fail = (msg) => { throw new RuleError(msg); };

const GRADE_ORDER = `'F','D','D+','C-','C','C+','B-','B','B+','A-','A'`;
const MAX_CREDITS = { 'Full-time': 16, 'Part-time': 8 };   // S-F7, S-F8
const TEACH_LOAD = { 'Full-time': 2, 'Part-time': 1 };     // A-F7, A-F8

// Sections that meet on a shared day in the same period clash.
const CLASH = `EXISTS (SELECT 1 FROM Time_Slot_Day d1 JOIN Time_Slot_Day d2 ON d2.day_ID = d1.day_ID
    JOIN Time_Slot_Period p1 ON p1.time_Slot_ID = d1.time_Slot_ID
    JOIN Time_Slot_Period p2 ON p2.time_Slot_ID = d2.time_Slot_ID AND p2.period_ID = p1.period_ID
  WHERE d1.time_Slot_ID = cs.time_Slot_ID AND d2.time_Slot_ID = ?)`;

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

// ---------- Student: add a course section (S-R22, UC-24/25) ----------
async function addSection(studentId, crn) {
  return transaction(async (conn) => {
    const q = async (sql, params) => (await conn.query(sql, params))[0];
    // Lock the section row so two students can't take the last seat at the same time.
    const [sec] = await q(`SELECT cs.*, c.course_Name, c.course_Credits, c.course_Type, s.semester_Name,
        CURDATE() BETWEEN s.add_Start AND s.add_End AS add_open, s.add_Start, s.add_End
      FROM Course_Section cs JOIN Course c ON c.course_ID = cs.course_ID JOIN Semester s ON s.semester_ID = cs.semester_ID
      WHERE cs.CRN = ? FOR UPDATE`, [crn]);
    if (!sec) fail('That course section does not exist.');
    const label = `${sec.course_ID} ${sec.course_Name} (CRN ${sec.CRN})`;

    const [stu] = await q(`SELECT s.student_Type, COALESCE(u.undergraduate_Student_Type, g.graduate_Student_Type) AS load_type
      FROM Student s LEFT JOIN Undergraduate_Student u ON u.student_ID = s.student_ID
      LEFT JOIN Graduate_Student g ON g.student_ID = s.student_ID WHERE s.student_ID = ?`, [studentId]);

    if (!sec.add_open) fail(`S-F12: the add period for ${sec.semester_Name} is ${sec.add_Start} to ${sec.add_End}.`);
    const holds = await q(`SELECT h.hold_Type FROM Student_Hold sh JOIN Hold h ON h.hold_ID = sh.hold_ID WHERE sh.student_ID = ?`, [studentId]);
    if (holds.length) fail(`S-F3: you have an active ${holds.map((h) => h.hold_Type.toLowerCase()).join(' and ')} hold.`);
    if (sec.course_Type !== stu.student_Type) {
      fail(stu.student_Type === 'Undergraduate' ? 'S-F5: undergraduates cannot register for graduate courses.' : 'S-F6: graduate students cannot register for undergraduate courses.');
    }
    const [already] = await q(`SELECT cs.CRN FROM Enrollment e JOIN Course_Section cs ON cs.CRN = e.CRN
      WHERE e.student_ID = ? AND cs.course_ID = ? AND cs.semester_ID = ?`, [studentId, sec.course_ID, sec.semester_ID]);
    if (already) fail(`You are already registered for ${sec.course_ID} this semester (CRN ${already.CRN}).`);
    const [passed] = await q(`SELECT h.grade FROM Student_History h JOIN Course_Section hc ON hc.CRN = h.CRN
      WHERE h.student_ID = ? AND hc.course_ID = ? AND h.grade <> 'F'`, [studentId, sec.course_ID]);
    if (passed) fail(`S-F10: you already passed ${sec.course_ID} (grade ${passed.grade}).`);
    const [inProgress] = await q(`SELECT s.semester_Name FROM Enrollment e JOIN Course_Section cs ON cs.CRN = e.CRN
      JOIN Semester s ON s.semester_ID = cs.semester_ID WHERE e.student_ID = ? AND cs.course_ID = ? AND e.grade IS NULL`, [studentId, sec.course_ID]);
    if (inProgress) fail(`You are already taking ${sec.course_ID} in ${inProgress.semester_Name}.`);
    const missing = await q(`SELECT cp.prerequisite_Course_ID AS id, cp.min_Grade_Req AS min FROM Course_Prerequisite cp
      WHERE cp.course_ID = ? AND NOT EXISTS (
        SELECT 1 FROM Student_History h JOIN Course_Section hc ON hc.CRN = h.CRN
        WHERE h.student_ID = ? AND hc.course_ID = cp.prerequisite_Course_ID
          AND FIELD(h.grade, ${GRADE_ORDER}) >= FIELD(cp.min_Grade_Req, ${GRADE_ORDER}))`, [sec.course_ID, studentId]);
    if (missing.length) fail(`S-F4: missing prerequisite ${missing.map((m) => `${m.id} (grade ${m.min} or better)`).join(', ')}.`);
    const [{ taken }] = await q('SELECT COUNT(*) AS taken FROM Enrollment WHERE CRN = ?', [crn]);
    if (taken >= sec.max_Seats) fail(`S-F9: ${label} is full (${sec.max_Seats} seats).`);
    const [{ credits }] = await q(`SELECT COALESCE(SUM(c.course_Credits), 0) AS credits FROM Enrollment e
      JOIN Course_Section cs ON cs.CRN = e.CRN JOIN Course c ON c.course_ID = cs.course_ID
      WHERE e.student_ID = ? AND cs.semester_ID = ?`, [studentId, sec.semester_ID]);
    const max = MAX_CREDITS[stu.load_type];
    if (Number(credits) + sec.course_Credits > max) {
      fail(`${stu.load_type === 'Full-time' ? 'S-F8' : 'S-F7'}: this would bring you to ${Number(credits) + sec.course_Credits} credits; the limit for ${stu.load_type.toLowerCase()} students is ${max}.`);
    }
    const [clash] = await q(`SELECT cs.course_ID, cs.CRN FROM Enrollment e JOIN Course_Section cs ON cs.CRN = e.CRN
      WHERE e.student_ID = ? AND cs.semester_ID = ? AND ${CLASH}`, [studentId, sec.semester_ID, sec.time_Slot_ID]);
    if (clash) fail(`S-F11: ${label} meets at the same time as ${clash.course_ID} (CRN ${clash.CRN}).`);

    await q('INSERT INTO Enrollment (student_ID, CRN, grade) VALUES (?, ?, NULL)', [studentId, crn]);
    return { message: `Added ${label}.`, sql: `INSERT INTO Enrollment (student_ID, CRN, grade) VALUES (${studentId}, ${crn}, NULL);` };
  });
}

// ---------- Student: drop a course section (S-R23, UC-26/27) ----------
async function dropSection(studentId, crn) {
  return transaction(async (conn) => {
    const q = async (sql, params) => (await conn.query(sql, params))[0];
    const [row] = await q(`SELECT e.grade, cs.course_ID, s.semester_Name, s.drop_Start, s.drop_End,
        CURDATE() BETWEEN s.drop_Start AND s.drop_End AS drop_open
      FROM Enrollment e JOIN Course_Section cs ON cs.CRN = e.CRN JOIN Semester s ON s.semester_ID = cs.semester_ID
      WHERE e.student_ID = ? AND e.CRN = ? FOR UPDATE`, [studentId, crn]);
    if (!row) fail('You are not registered for that section.');
    if (!row.drop_open) fail(`S-F13: the drop period for ${row.semester_Name} is ${row.drop_Start} to ${row.drop_End}.`);
    if (row.grade) fail('A graded section cannot be dropped.');
    await q('DELETE FROM Enrollment WHERE student_ID = ? AND CRN = ?', [studentId, crn]);
    return { message: `Dropped ${row.course_ID} (CRN ${crn}).`, sql: `DELETE FROM Enrollment WHERE student_ID = ${studentId} AND CRN = ${crn};` };
  });
}

// ---------- Admin: create a course section (A-R26, UC-13) ----------
async function createSection(adminId, form) {
  return transaction(async (conn) => {
    const q = async (sql, params) => (await conn.query(sql, params))[0];
    const [admin] = await q('SELECT security_Level FROM Admin WHERE admin_ID = ?', [adminId]);
    if (!admin || admin.security_Level !== 'Read-Write') fail('A-F2: Read-Only admins cannot create course sections.');

    const seats = Number(form.max_Seats);
    if (!Number.isInteger(seats) || seats < 1 || seats > 10) fail('A-F9: a section holds 1 to 10 students.');
    const [sem] = await q('SELECT semester_ID, semester_Name, start_Date, start_Date <= CURDATE() AS started FROM Semester WHERE semester_ID = ?', [form.semester_ID]);
    if (!sem) fail('Choose a semester.');
    if (sem.started) fail(`${sem.semester_Name} has already started; sections can only be added to an upcoming semester.`);
    const [course] = await q('SELECT course_ID, course_Name, dept_ID FROM Course WHERE course_ID = ?', [form.course_ID]);
    if (!course) fail('Choose a course.');
    const [fac] = await q(`SELECT f.faculty_ID, f.faculty_Type, CONCAT(u.first_Name, ' ', u.last_Name) AS name
      FROM Faculty f JOIN User u ON u.user_ID = f.faculty_ID WHERE f.faculty_ID = ?`, [form.faculty_ID]);
    if (!fac) fail('Choose a faculty member.');
    const [slot] = await q('SELECT time_Slot_ID FROM Time_Slot WHERE time_Slot_ID = ?', [form.time_Slot_ID]);
    if (!slot) fail('Choose a time slot.');
    const [room] = await q('SELECT room_ID, capacity FROM Room WHERE room_ID = ?', [form.room_ID]);
    if (!room) fail('Choose a room.');
    if (room.capacity < seats) fail(`Room ${room.room_ID} only holds ${room.capacity}.`);

    const [member] = await q('SELECT 1 AS ok FROM Faculty_Department WHERE faculty_ID = ? AND dept_ID = ?', [fac.faculty_ID, course.dept_ID]);
    if (!member) fail(`A-F4: ${fac.name} is not a member of the ${course.dept_ID} department.`);
    // Lock this faculty member's sections for the semester while checking the teaching load.
    const teaching = await q('SELECT CRN FROM Course_Section WHERE faculty_ID = ? AND semester_ID = ? FOR UPDATE', [fac.faculty_ID, sem.semester_ID]);
    if (teaching.length >= TEACH_LOAD[fac.faculty_Type]) {
      fail(`${fac.faculty_Type === 'Full-time' ? 'A-F7' : 'A-F8'}: ${fac.name} (${fac.faculty_Type.toLowerCase()}) already teaches ${teaching.length} section(s) in ${sem.semester_Name}.`);
    }
    const [facClash] = await q(`SELECT cs.CRN, cs.course_ID FROM Course_Section cs WHERE cs.faculty_ID = ? AND cs.semester_ID = ? AND ${CLASH}`,
      [fac.faculty_ID, sem.semester_ID, slot.time_Slot_ID]);
    if (facClash) fail(`A-F5: ${fac.name} already teaches ${facClash.course_ID} (CRN ${facClash.CRN}) at that time.`);
    const [roomClash] = await q(`SELECT cs.CRN, cs.course_ID FROM Course_Section cs WHERE cs.room_ID = ? AND cs.semester_ID = ? AND ${CLASH} FOR UPDATE`,
      [room.room_ID, sem.semester_ID, slot.time_Slot_ID]);
    if (roomClash) fail(`A-F6: room ${room.room_ID} is already used by ${roomClash.course_ID} (CRN ${roomClash.CRN}) at that time.`);

    const [{ crn }] = await q('SELECT COALESCE(MAX(CRN), 10000) + 1 AS crn FROM Course_Section FOR UPDATE');
    const [{ n }] = await q('SELECT COUNT(*) AS n FROM Course_Section WHERE course_ID = ? AND semester_ID = ?', [course.course_ID, sem.semester_ID]);
    const sectionNo = String(n + 1).padStart(3, '0');
    const values = [crn, course.course_ID, sectionNo, fac.faculty_ID, slot.time_Slot_ID, room.room_ID, sem.semester_ID, seats];
    await q(`INSERT INTO Course_Section (CRN, course_ID, section_No, faculty_ID, time_Slot_ID, room_ID, semester_ID, max_Seats)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?)`, values);
    return {
      message: `Created ${course.course_ID} section ${sectionNo} (CRN ${crn}) for ${sem.semester_Name}, taught by ${fac.name}.`,
      sql: `INSERT INTO Course_Section (CRN, course_ID, section_No, faculty_ID, time_Slot_ID, room_ID, semester_ID, max_Seats)\nVALUES (${values.map((v) => (typeof v === 'number' ? v : `'${v}'`)).join(', ')});`,
    };
  });
}

module.exports = { addSection, dropSection, createSection, RuleError };
