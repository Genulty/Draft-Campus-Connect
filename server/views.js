// Read-only dashboard pages for each role. Every page returns
// { title, subtitle, blocks }, where a block is a row of stat cards, a set of
// filters, or a table. The browser renders them generically (public/login.js).
const { db } = require('./db');

async function table(title, sql, params = [], note) {
  const [rows, fields] = await db.query(sql, params);
  return { type: 'table', title, note, columns: fields.map((f) => f.name), rows: rows.map((r) => fields.map((f) => r[f.name])) };
}
async function one(sql, params = []) {
  const [[row]] = await db.query(sql, params);
  return row || {};
}
const stats = (items) => ({ type: 'stats', items: items.map(([label, value]) => ({ label, value: value ?? '—' })) });

// The current semester is the latest one that has started; the next is the first one that hasn't.
const currentSemester = () => one(`SELECT semester_ID AS id, semester_Name AS name FROM Semester
  WHERE start_Date <= CURDATE() ORDER BY start_Date DESC LIMIT 1`);
const nextSemester = () => one(`SELECT semester_ID AS id, semester_Name AS name FROM Semester
  WHERE start_Date > CURDATE() ORDER BY start_Date LIMIT 1`);

// Columns shared by every list of course sections.
const SECTION_COLUMNS = `cs.CRN, cs.course_ID AS Course, c.course_Name AS Title, cs.section_No AS Section, c.course_Credits AS Credits,
  (SELECT GROUP_CONCAT(d.day_ID ORDER BY FIELD(d.day_ID,'M','T','W','R','F') SEPARATOR '')
     FROM Time_Slot_Day d WHERE d.time_Slot_ID = cs.time_Slot_ID) AS Days,
  CONCAT(TIME_FORMAT(p.start_Time, '%l:%i %p'), ' – ', TIME_FORMAT(p.end_Time, '%l:%i %p')) AS Time,
  CONCAT(b.building_Name, ' ', r.room_No) AS Room`;
const SECTION_JOINS = `JOIN Course c ON c.course_ID = cs.course_ID
  JOIN Time_Slot_Period tp ON tp.time_Slot_ID = cs.time_Slot_ID
  JOIN Period p ON p.period_ID = tp.period_ID
  JOIN Room r ON r.room_ID = cs.room_ID
  JOIN Building b ON b.building_ID = r.building_ID
  LEFT JOIN User fu ON fu.user_ID = cs.faculty_ID`;
const INSTRUCTOR = `CONCAT(fu.first_Name, ' ', fu.last_Name) AS Instructor`;
const SEATS = `cs.max_Seats - (SELECT COUNT(*) FROM Enrollment e2 WHERE e2.CRN = cs.CRN) AS \`Open seats\``;
const ORDER_BY_TIME = `ORDER BY FIELD(LEFT(Days, 1),'M','T','W','R','F'), p.start_Time`;

const GPA_POINTS = `CASE h.grade WHEN 'A' THEN 4 WHEN 'A-' THEN 3.7 WHEN 'B+' THEN 3.3 WHEN 'B' THEN 3 WHEN 'B-' THEN 2.7
  WHEN 'C+' THEN 2.3 WHEN 'C' THEN 2 WHEN 'C-' THEN 1.7 WHEN 'D+' THEN 1.3 WHEN 'D' THEN 1 ELSE 0 END`;

async function semesterFilter(q, dept = true) {
  const cur = await currentSemester();
  const [sems] = await db.query('SELECT semester_ID, semester_Name FROM Semester ORDER BY start_Date');
  const [depts] = await db.query('SELECT dept_ID, dept_Name FROM Department ORDER BY dept_Name');
  const semester = sems.some((s) => s.semester_ID === q.semester) ? q.semester : cur.id;
  const deptId = depts.some((d) => d.dept_ID === q.dept) ? q.dept : '';
  const filters = [{ name: 'semester', label: 'Semester', value: semester, options: sems.map((s) => [s.semester_ID, s.semester_Name]) }];
  if (dept) filters.push({ name: 'dept', label: 'Department', value: deptId, options: [['', 'All departments'], ...depts.map((d) => [d.dept_ID, d.dept_Name])] });
  return { semester, deptId, block: { type: 'filters', filters } };
}

const VIEWS = [
  // ---------------- Student ----------------
  { id: 'overview', label: 'Overview', roles: ['Student'], async build(u) {
    const s = await one(`SELECT s.student_Year, s.student_Type,
        COALESCE(ug.undergraduate_Student_Type, g.graduate_Student_Type) AS load_type,
        COALESCE(ft.credits_Earned, pt.credits_Earned, fg.credits_Earned, pg.credits_Earned) AS credits,
        COALESCE(ft.status, pt.status) AS status,
        (SELECT GROUP_CONCAT(m.major_Name SEPARATOR ', ') FROM Student_Major sm JOIN Major m ON m.major_ID = sm.major_ID WHERE sm.student_ID = s.student_ID) AS majors,
        (SELECT GROUP_CONCAT(m.minor_Name SEPARATOR ', ') FROM Student_Minor sm JOIN Minor m ON m.minor_ID = sm.minor_ID WHERE sm.student_ID = s.student_ID) AS minors
      FROM Student s
      LEFT JOIN Undergraduate_Student ug ON ug.student_ID = s.student_ID
      LEFT JOIN Graduate_Student g ON g.student_ID = s.student_ID
      LEFT JOIN Full_Time_Undergraduate ft ON ft.student_ID = s.student_ID
      LEFT JOIN Part_Time_Undergraduate pt ON pt.student_ID = s.student_ID
      LEFT JOIN Full_Time_Graduate_Student fg ON fg.student_ID = s.student_ID
      LEFT JOIN Part_Time_Graduate_Student pg ON pg.student_ID = s.student_ID
      WHERE s.student_ID = ?`, [u.id]);
    const gpa = await one(`SELECT ROUND(SUM(${GPA_POINTS} * c.course_Credits) / SUM(c.course_Credits), 2) AS gpa
      FROM Student_History h JOIN Course_Section cs ON cs.CRN = h.CRN JOIN Course c ON c.course_ID = cs.course_ID
      WHERE h.student_ID = ?`, [u.id]);
    const cur = await currentSemester();
    const now = await one(`SELECT COUNT(*) AS n, COALESCE(SUM(c.course_Credits), 0) AS credits FROM Enrollment e
      JOIN Course_Section cs ON cs.CRN = e.CRN JOIN Course c ON c.course_ID = cs.course_ID
      WHERE e.student_ID = ? AND cs.semester_ID = ?`, [u.id, cur.id]);
    const holds = await one('SELECT COUNT(*) AS n FROM Student_Hold WHERE student_ID = ?', [u.id]);
    return {
      title: `Welcome, ${u.name}`,
      subtitle: `${s.student_Type} · ${s.student_Year} · ${s.load_type}${s.status ? ` · ${s.status}` : ''}`,
      blocks: [
        stats([['Major', s.majors], ['Minor', s.minors || 'None'], ['GPA', gpa.gpa], ['Credits earned', s.credits],
          [`${cur.name} classes`, `${now.n} (${now.credits} credits)`], ['Active holds', holds.n]]),
        await table(`${cur.name} schedule`, `SELECT ${SECTION_COLUMNS}, ${INSTRUCTOR} FROM Enrollment e
          JOIN Course_Section cs ON cs.CRN = e.CRN ${SECTION_JOINS}
          WHERE e.student_ID = ? AND cs.semester_ID = ? ${ORDER_BY_TIME}`, [u.id, cur.id]),
      ],
    };
  } },
  { id: 'schedule', label: 'My schedule', roles: ['Student'], async build(u) {
    const cur = await currentSemester();
    const next = await nextSemester();
    const blocks = [await table(`${cur.name} (current semester) · S-R4`, `SELECT ${SECTION_COLUMNS}, ${INSTRUCTOR} FROM Enrollment e
      JOIN Course_Section cs ON cs.CRN = e.CRN ${SECTION_JOINS}
      WHERE e.student_ID = ? AND cs.semester_ID = ? ${ORDER_BY_TIME}`, [u.id, cur.id])];
    if (next.id) {
      blocks.push(await table(`${next.name} (next semester) · S-R5`, `SELECT ${SECTION_COLUMNS}, ${INSTRUCTOR} FROM Enrollment e
        JOIN Course_Section cs ON cs.CRN = e.CRN ${SECTION_JOINS}
        WHERE e.student_ID = ? AND cs.semester_ID = ? ${ORDER_BY_TIME}`, [u.id, next.id], 'Registration for this semester has not opened yet.'));
    }
    return { title: 'My schedule', blocks };
  } },
  { id: 'transcript', label: 'Transcript', roles: ['Student'], async build(u) {
    const gpa = await one(`SELECT ROUND(SUM(${GPA_POINTS} * c.course_Credits) / SUM(c.course_Credits), 2) AS gpa,
        SUM(IF(h.grade <> 'F', c.course_Credits, 0)) AS earned, COUNT(*) AS courses
      FROM Student_History h JOIN Course_Section cs ON cs.CRN = h.CRN JOIN Course c ON c.course_ID = cs.course_ID
      WHERE h.student_ID = ?`, [u.id]);
    return { title: 'Unofficial transcript', subtitle: 'S-R7, S-R8', blocks: [
      stats([['Cumulative GPA', gpa.gpa], ['Credits earned', gpa.earned], ['Courses completed', gpa.courses]]),
      await table('Completed courses', `SELECT sem.semester_Name AS Semester, cs.course_ID AS Course, c.course_Name AS Title,
          c.course_Credits AS Credits, h.grade AS Grade
        FROM Student_History h JOIN Course_Section cs ON cs.CRN = h.CRN JOIN Course c ON c.course_ID = cs.course_ID
        JOIN Semester sem ON sem.semester_ID = h.semester_ID
        WHERE h.student_ID = ? ORDER BY sem.start_Date, cs.course_ID`, [u.id]),
    ] };
  } },
  { id: 'holds-advisors', label: 'Holds & advisors', roles: ['Student'], async build(u) {
    return { title: 'Holds & advisors', blocks: [
      await table('Active holds · S-R6', `SELECT h.hold_Type AS Hold, DATE_FORMAT(sh.hold_Date, '%b %e, %Y') AS \`Placed on\`
        FROM Student_Hold sh JOIN Hold h ON h.hold_ID = sh.hold_ID WHERE sh.student_ID = ?`, [u.id], 'A student with an active hold cannot register (S-F3).'),
      await table('My advisors · S-R10', `SELECT CONCAT(u.first_Name, ' ', u.last_Name) AS Advisor, f.\`rank\` AS \`Rank\`,
          (SELECT GROUP_CONCAT(d.dept_Name SEPARATOR ', ') FROM Faculty_Department fd JOIN Department d ON d.dept_ID = fd.dept_ID WHERE fd.faculty_ID = f.faculty_ID) AS Department,
          l.user_Email AS Email, CONCAT(o.building_ID, '-', o.room_No) AS Office
        FROM Advisor a JOIN Faculty f ON f.faculty_ID = a.faculty_ID JOIN User u ON u.user_ID = f.faculty_ID
        JOIN Login l ON l.user_ID = f.faculty_ID LEFT JOIN Office o ON o.office_ID = f.office_ID
        WHERE a.student_ID = ?`, [u.id]),
    ] };
  } },

  // ---------------- Faculty ----------------
  { id: 'teaching', label: 'My teaching', roles: ['Faculty'], async build(u) {
    const f = await one(`SELECT f.\`rank\`, f.faculty_Type, f.specialty,
        (SELECT GROUP_CONCAT(CONCAT(d.dept_Name, ' (', fd.percent_Time, '%)') SEPARATOR ', ') FROM Faculty_Department fd
          JOIN Department d ON d.dept_ID = fd.dept_ID WHERE fd.faculty_ID = f.faculty_ID) AS depts,
        CONCAT(o.building_ID, '-', o.room_No) AS office
      FROM Faculty f LEFT JOIN Office o ON o.office_ID = f.office_ID WHERE f.faculty_ID = ?`, [u.id]);
    const cur = await currentSemester();
    const next = await nextSemester();
    const advisees = await one('SELECT COUNT(*) AS n FROM Advisor WHERE faculty_ID = ?', [u.id]);
    const [mine] = await db.query(`SELECT cs.CRN, cs.course_ID FROM Course_Section cs WHERE cs.faculty_ID = ? AND cs.semester_ID = ? ORDER BY cs.CRN`, [u.id, cur.id]);
    const blocks = [
      stats([['Rank', f.rank], ['Type', f.faculty_Type], ['Department(s)', f.depts], ['Office', f.office],
        [`${cur.name} sections`, mine.length], ['Advisees', advisees.n]]),
      await table(`${cur.name} teaching schedule · F-R8`, `SELECT ${SECTION_COLUMNS}, ${SEATS} FROM Course_Section cs ${SECTION_JOINS}
        WHERE cs.faculty_ID = ? AND cs.semester_ID = ? ${ORDER_BY_TIME}`, [u.id, cur.id]),
    ];
    for (const s of mine) {
      blocks.push(await table(`Roster: ${s.course_ID} (CRN ${s.CRN}) · F-R10`, `SELECT u.user_ID AS \`Student ID\`,
          CONCAT(u.first_Name, ' ', u.last_Name) AS Student, st.student_Year AS Year, l.user_Email AS Email,
          CONCAT(ROUND(100 * AVG(a.attendance_Status = 'Present')), '%') AS Attendance
        FROM Enrollment e JOIN User u ON u.user_ID = e.student_ID JOIN Student st ON st.student_ID = e.student_ID
        JOIN Login l ON l.user_ID = e.student_ID
        LEFT JOIN Attendance a ON a.CRN = e.CRN AND a.student_ID = e.student_ID
        WHERE e.CRN = ? GROUP BY u.user_ID, st.student_Year, l.user_Email ORDER BY u.last_Name`, [s.CRN]));
    }
    if (next.id) {
      blocks.push(await table(`${next.name} teaching schedule · F-R9`, `SELECT ${SECTION_COLUMNS} FROM Course_Section cs ${SECTION_JOINS}
        WHERE cs.faculty_ID = ? AND cs.semester_ID = ? ${ORDER_BY_TIME}`, [u.id, next.id]));
    }
    return { title: `Welcome, ${u.name}`, subtitle: f.specialty ? `Specialty: ${f.specialty}` : '', blocks };
  } },
  { id: 'advisees', label: 'Advisees', roles: ['Faculty'], async build(u) {
    return { title: 'My advisees', subtitle: 'F-R12 · full-time faculty advise at most 15 students (F-F9)', blocks: [
      await table('Advisees', `SELECT st.student_ID AS \`Student ID\`, CONCAT(u.first_Name, ' ', u.last_Name) AS Student,
          st.student_Type AS Level, st.student_Year AS Year,
          (SELECT GROUP_CONCAT(m.major_Name SEPARATOR ', ') FROM Student_Major sm JOIN Major m ON m.major_ID = sm.major_ID WHERE sm.student_ID = st.student_ID) AS Major,
          (SELECT GROUP_CONCAT(h.hold_Type SEPARATOR ', ') FROM Student_Hold sh JOIN Hold h ON h.hold_ID = sh.hold_ID WHERE sh.student_ID = st.student_ID) AS Holds,
          DATE_FORMAT(a.date_Of_Appointment, '%b %e, %Y') AS \`Advising since\`
        FROM Advisor a JOIN Student st ON st.student_ID = a.student_ID JOIN User u ON u.user_ID = st.student_ID
        WHERE a.faculty_ID = ? ORDER BY u.last_Name`, [u.id]),
    ] };
  } },

  // ---------------- Admin ----------------
  { id: 'admin-overview', label: 'Overview', roles: ['Admin'], async build(u) {
    const a = await one('SELECT security_Level FROM Admin WHERE admin_ID = ?', [u.id]);
    const cur = await currentSemester();
    const c = await one(`SELECT
        (SELECT COUNT(*) FROM Student) AS students, (SELECT COUNT(*) FROM Faculty) AS faculty,
        (SELECT COUNT(*) FROM Department) AS depts, (SELECT COUNT(*) FROM Course) AS courses,
        (SELECT COUNT(*) FROM Course_Section WHERE semester_ID = ?) AS sections,
        (SELECT COUNT(*) FROM Enrollment e JOIN Course_Section cs ON cs.CRN = e.CRN WHERE cs.semester_ID = ?) AS enrollments,
        (SELECT COUNT(DISTINCT student_ID) FROM Student_Hold) AS holds,
        (SELECT COUNT(*) FROM Login WHERE lock_Var = 1) AS locked`, [cur.id, cur.id]);
    return { title: `Welcome, ${u.name}`, subtitle: `${a.security_Level} administrator (A-R2)`, blocks: [
      stats([['Students', c.students], ['Faculty', c.faculty], ['Departments', c.depts], ['Courses', c.courses],
        [`${cur.name} sections`, c.sections], [`${cur.name} enrollments`, c.enrollments], ['Students with holds', c.holds], ['Locked accounts', c.locked]]),
      await table('Departments', `SELECT d.dept_ID AS ID, d.dept_Name AS Department, CONCAT(u.first_Name, ' ', u.last_Name) AS Chair,
          d.dept_Manager AS Manager, d.phone_No AS Phone, d.email AS Email,
          (SELECT COUNT(*) FROM Faculty_Department fd WHERE fd.dept_ID = d.dept_ID) AS Faculty,
          (SELECT COUNT(*) FROM Course c WHERE c.dept_ID = d.dept_ID) AS Courses
        FROM Department d LEFT JOIN User u ON u.user_ID = d.chair_ID ORDER BY d.dept_Name`),
    ] };
  } },
  { id: 'holds', label: 'Student holds', roles: ['Admin'], async build() {
    return { title: 'Student holds', blocks: [
      await table('Active holds', `SELECT sh.student_ID AS \`Student ID\`, CONCAT(u.first_Name, ' ', u.last_Name) AS Student,
          h.hold_Type AS Hold, DATE_FORMAT(sh.hold_Date, '%b %e, %Y') AS \`Placed on\`
        FROM Student_Hold sh JOIN Hold h ON h.hold_ID = sh.hold_ID JOIN User u ON u.user_ID = sh.student_ID
        ORDER BY sh.hold_Date DESC`),
    ] };
  } },
  { id: 'audit', label: 'Audit log', roles: ['Admin'], async build() {
    return { title: 'Audit log', subtitle: 'A-R43, A-R44 · every admin update to user information', blocks: [
      await table('Entries', `SELECT l.log_ID AS \`#\`, DATE_FORMAT(l.date_Time, '%b %e, %Y %l:%i %p') AS \`Date & time\`,
          CONCAT(au.first_Name, ' ', au.last_Name) AS Admin, CONCAT(uu.first_Name, ' ', uu.last_Name, ' (', l.user_ID, ')') AS \`Affected user\`,
          l.action_Type AS Action, l.field_Name AS Field, l.old_Value AS \`Old value\`, l.new_Value AS \`New value\`
        FROM Audit_Log l JOIN User au ON au.user_ID = l.admin_ID JOIN User uu ON uu.user_ID = l.user_ID
        ORDER BY l.date_Time DESC`),
    ] };
  } },

  // ---------------- Statistics department ----------------
  { id: 'stats', label: 'Statistics', roles: ['StatDept'], async build(u) {
    const cur = await currentSemester();
    const c = await one(`SELECT
        (SELECT COUNT(*) FROM Student WHERE student_Type = 'Undergraduate') AS ug,
        (SELECT COUNT(*) FROM Student WHERE student_Type = 'Graduate') AS gr,
        (SELECT COUNT(*) FROM Faculty WHERE faculty_Type = 'Full-time') AS ft,
        (SELECT COUNT(*) FROM Faculty WHERE faculty_Type = 'Part-time') AS pt,
        (SELECT COUNT(*) FROM Course_Section WHERE semester_ID = ?) AS sections,
        (SELECT SUM(max_Seats) FROM Course_Section WHERE semester_ID = ?) AS seats,
        (SELECT COUNT(*) FROM Enrollment e JOIN Course_Section cs ON cs.CRN = e.CRN WHERE cs.semester_ID = ?) AS filled`, [cur.id, cur.id, cur.id]);
    return { title: `Welcome, ${u.name}`, subtitle: 'Aggregate data only: no student is identified (SD-R12, SD-F5)', blocks: [
      stats([['Undergraduate students', c.ug], ['Graduate students', c.gr], ['Full-time faculty', c.ft], ['Part-time faculty', c.pt],
        [`${cur.name} sections`, c.sections], [`${cur.name} seats filled`, `${c.filled} of ${c.seats}`]]),
      await table(`Students per major · SD-R7`, `SELECT m.major_Name AS Major, COUNT(sm.student_ID) AS Students
        FROM Major m LEFT JOIN Student_Major sm ON sm.major_ID = m.major_ID GROUP BY m.major_ID, m.major_Name ORDER BY Students DESC`),
      await table(`Grades by department · SD-R10`, `SELECT d.dept_Name AS Department, COUNT(*) AS Grades,
          ROUND(AVG(${GPA_POINTS}), 2) AS \`Avg grade points\`,
          SUM(h.grade LIKE 'A%') AS A, SUM(h.grade LIKE 'B%') AS B, SUM(h.grade LIKE 'C%') AS C,
          SUM(h.grade LIKE 'D%') AS D, SUM(h.grade = 'F') AS F
        FROM Student_History h JOIN Course_Section cs ON cs.CRN = h.CRN JOIN Course c ON c.course_ID = cs.course_ID
        JOIN Department d ON d.dept_ID = c.dept_ID GROUP BY d.dept_ID, d.dept_Name ORDER BY d.dept_Name`),
    ] };
  } },
  { id: 'enrollment-counts', label: 'Enrollment counts', roles: ['StatDept'], async build(u, q) {
    const f = await semesterFilter(q);
    return { title: 'Enrollment counts', subtitle: 'SD-R5, SD-R8 · number of students per section, without names', blocks: [
      f.block,
      await table('Sections', `SELECT cs.CRN, cs.course_ID AS Course, c.course_Name AS Title, cs.section_No AS Section,
          COUNT(e.student_ID) AS Registered, cs.max_Seats AS \`Max seats\`, cs.max_Seats - COUNT(e.student_ID) AS Available
        FROM Course_Section cs JOIN Course c ON c.course_ID = cs.course_ID LEFT JOIN Enrollment e ON e.CRN = cs.CRN
        WHERE cs.semester_ID = ? AND (? = '' OR c.dept_ID = ?)
        GROUP BY cs.CRN, cs.course_ID, c.course_Name, cs.section_No, cs.max_Seats ORDER BY cs.course_ID, cs.section_No`,
      [f.semester, f.deptId, f.deptId]),
    ] };
  } },

  // ---------------- Everyone ----------------
  { id: 'master-schedule', label: 'Master schedule', roles: ['Faculty', 'Admin', 'Student'], async build(u, q) {
    const f = await semesterFilter(q);
    return { title: 'Semester master schedule', subtitle: 'F-R13 – F-R19', blocks: [
      f.block,
      await table('Course sections', `SELECT ${SECTION_COLUMNS}, ${INSTRUCTOR}, ${SEATS}
        FROM Course_Section cs ${SECTION_JOINS}
        WHERE cs.semester_ID = ? AND (? = '' OR c.dept_ID = ?) ORDER BY cs.course_ID, cs.section_No`,
      [f.semester, f.deptId, f.deptId]),
    ] };
  } },
  { id: 'catalog', label: 'Course catalog', roles: ['Student', 'Faculty', 'Admin', 'StatDept'], async build(u, q) {
    const [depts] = await db.query('SELECT dept_ID, dept_Name FROM Department ORDER BY dept_Name');
    const deptId = depts.some((d) => d.dept_ID === q.dept) ? q.dept : depts[0].dept_ID;
    return { title: 'Course catalog', subtitle: 'S-R12 – S-R18, F-R20 – F-R26', blocks: [
      { type: 'filters', filters: [{ name: 'dept', label: 'Department', value: deptId, options: depts.map((d) => [d.dept_ID, d.dept_Name]) }] },
      await table('Courses', `SELECT c.course_ID AS Course, c.course_Name AS Title, c.course_Credits AS Credits, c.course_Type AS Level,
          COALESCE((SELECT GROUP_CONCAT(CONCAT(cp.prerequisite_Course_ID, ' (min ', cp.min_Grade_Req, ')') SEPARATOR ', ')
            FROM Course_Prerequisite cp WHERE cp.course_ID = c.course_ID), '—') AS Prerequisites
        FROM Course c WHERE c.dept_ID = ? ORDER BY c.course_ID`, [deptId]),
      await table('Majors and minors', `SELECT 'Major' AS Type, m.major_Name AS Program,
          (SELECT GROUP_CONCAT(r.course_ID ORDER BY r.course_ID SEPARATOR ', ') FROM Major_Course_Requirement r WHERE r.major_ID = m.major_ID) AS \`Required courses\`
        FROM Major m WHERE m.dept_ID = ?
        UNION ALL SELECT 'Minor', n.minor_Name,
          (SELECT GROUP_CONCAT(r.course_ID ORDER BY r.course_ID SEPARATOR ', ') FROM Minor_Course_Requirement r WHERE r.minor_ID = n.minor_ID)
        FROM Minor n WHERE n.dept_ID = ?`, [deptId, deptId]),
    ] };
  } },
];

const viewsFor = (role) => VIEWS.filter((v) => v.roles.includes(role)).map(({ id, label }) => ({ id, label }));

async function buildView(user, id, query) {
  const view = VIEWS.find((v) => v.id === id && v.roles.includes(user.role));
  if (!view) return null;
  return view.build(user, query);
}

module.exports = { viewsFor, buildView };
