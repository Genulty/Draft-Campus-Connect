// Generates db/Project_data.sql: realistic, consistent data for every table
// in db/Database_schema.sql. The output is the same every run (seeded random).
//   node db/generate-data.js
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

// ---------- helpers ----------
let seed = 5910;
function rand() { // mulberry32
  seed |= 0; seed = (seed + 0x6D2B79F5) | 0;
  let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
  t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
  return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
}
const pick = (arr) => arr[Math.floor(rand() * arr.length)];
const int = (lo, hi) => lo + Math.floor(rand() * (hi - lo + 1));
const chance = (p) => rand() < p;
function shuffle(arr) {
  const a = arr.slice();
  for (let i = a.length - 1; i > 0; i--) { const j = Math.floor(rand() * (i + 1)); [a[i], a[j]] = [a[j], a[i]]; }
  return a;
}
const pad = (n, w = 2) => String(n).padStart(w, '0');
const date = (y, m, d) => `${y}-${pad(m)}-${pad(d)}`;
function addDays(iso, n) {
  const d = new Date(iso + 'T00:00:00Z'); d.setUTCDate(d.getUTCDate() + n);
  return d.toISOString().slice(0, 10);
}

// Same password format as server/db.js: "salt:scrypt(password)". Every account uses Campus123!
const PASSWORD = 'Campus123!';
function hashPassword() {
  const salt = Array.from({ length: 16 }, () => pad(int(0, 255).toString(16))).join('');
  return `${salt}:${crypto.scryptSync(PASSWORD, salt, 32).toString('hex')}`;
}

const tables = {}; // table -> { cols, rows }
function insert(table, cols, row) {
  (tables[table] ||= { cols, rows: [] }).rows.push(row);
}
function sqlValue(v) {
  if (v === null || v === undefined) return 'NULL';
  if (typeof v === 'number') return String(v);
  return `'${String(v).replace(/\\/g, '\\\\').replace(/'/g, "''")}'`;
}

// ---------- reference data ----------
const FIRST_M = ['James','Michael','David','Daniel','Kevin','Jason','Brian','Anthony','Carlos','Luis','Jose','Andre','Marcus','Tyler','Ryan','Ethan','Noah','Liam','Omar','Samuel','Victor','Aaron','Jordan','Malik','Raj','Wei','Hiroshi','Diego','Mateo','Isaac'];
const FIRST_F = ['Maria','Jennifer','Ashley','Jessica','Emily','Sarah','Nicole','Olivia','Sophia','Isabella','Mia','Ava','Grace','Chloe','Aaliyah','Priya','Mei','Fatima','Camila','Valentina','Hannah','Rachel','Lauren','Brianna','Jasmine','Natalie','Elena','Amara','Yuki','Zoe'];
const LAST = ['Smith','Johnson','Williams','Brown','Jones','Garcia','Miller','Davis','Rodriguez','Martinez','Hernandez','Lopez','Gonzalez','Wilson','Anderson','Thomas','Taylor','Moore','Jackson','Martin','Lee','Perez','Thompson','White','Harris','Sanchez','Clark','Ramirez','Lewis','Robinson','Walker','Young','Allen','King','Wright','Scott','Torres','Nguyen','Hill','Flores','Green','Adams','Nelson','Baker','Hall','Rivera','Campbell','Mitchell','Carter','Roberts','Patel','Kim','Chen','Singh','Okafor','Mensah','Cohen','Rossi','Murphy','Sullivan'];
const MIDDLE = ['A.','J.','M.','L.','R.','K.','E.','T.', null, null, null, null];
const TOWNS = [
  ['Old Westbury','11568'],['Westbury','11590'],['Hicksville','11801'],['Garden City','11530'],['Mineola','11501'],
  ['Hempstead','11550'],['Levittown','11756'],['Freeport','11520'],['Roslyn','11576'],['Jericho','11753'],
  ['Syosset','11791'],['Glen Cove','11542'],['Bethpage','11714'],['Massapequa','11758'],['Uniondale','11553'],
];
const STREETS = ['Main St','Oak Ave','Maple Dr','Park Ave','Cedar Ln','Elm St','Pine St','Lake View Rd','Hillside Ave','Jericho Tpke','Post Ave','Willis Ave','Old Country Rd','Stewart Ave','Franklin Ave'];
const BUILDINGS = ['Academic Building', 'Natural Sciences', 'Campus Center', 'Library Hall', 'Business Center'];

const usedEmails = new Set();
function person(gender, yearFrom, yearTo) {
  const first = gender === 'Female' ? pick(FIRST_F) : pick(FIRST_M);
  const last = pick(LAST);
  const [city, zip] = pick(TOWNS);
  return {
    first, middle: pick(MIDDLE), last, gender,
    dob: date(int(yearFrom, yearTo), int(1, 12), int(1, 28)),
    street: `${int(10, 999)} ${pick(STREETS)}`, city, state: 'NY', zip,
  };
}
function emailFor(first, last) {
  const base = `${first}.${last}`.toLowerCase().replace(/[^a-z.]/g, '');
  let email = `${base}@campus.edu`, n = 2;
  while (usedEmails.has(email)) email = `${base}${n++}@campus.edu`;
  usedEmails.add(email);
  return email;
}
const USER_COLS = ['user_ID','first_Name','middle_Name','last_Name','gender','DOB','street','city','state','zip_Code','user_Type'];
function addUser(id, p, type, email) {
  insert('User', USER_COLS, [id, p.first, p.middle, p.last, p.gender, p.dob, p.street, p.city, p.state, p.zip, type]);
  email = email || emailFor(p.first, p.last);
  usedEmails.add(email);
  insert('Login', ['user_ID','user_Email','user_Password','no_Of_Tries','lock_Var','user_Type'], [id, email, hashPassword(), 0, 0, type]);
}

// ---------- offices & labs ----------
const offices = [];
for (const [b, bldg] of BUILDINGS.entries()) {
  for (let r = 1; r <= 10; r++) {
    const id = `OF${b + 1}${pad(r)}`;
    offices.push(id);
    insert('Office', ['office_ID','bldg_Name','room_No'], [id, bldg, `${b + 1}${pad(r + 10, 2)}`]);
  }
}
const labs = [];
for (const [b, bldg] of BUILDINGS.entries()) {
  for (let r = 1; r <= 5; r++) {
    const id = `LB${b + 1}${pad(r)}`;
    labs.push(id);
    insert('Lab', ['lab_ID','bldg_Name','room_No','capacity'], [id, bldg, `${b + 1}${pad(r + 50)}`, pick([20, 25, 30, 35, 40])]);
  }
}
const freeOffices = offices.slice();

// ---------- departments, courses ----------
const DEPTS = [
  { id: 'CS', name: 'Computer Science', manager: 'Linda Park', courses: [
    ['Introduction to Programming', 'Intro to Python'], ['Data Structures'], ['Discrete Mathematics'], ['Computer Organization'],
    ['Algorithms'], ['Database Systems'], ['Operating Systems'], ['Software Engineering'], ['Computer Networks'], ['System Design and Implementation'],
  ], grad: ['Advanced Algorithms', 'Machine Learning', 'Distributed Systems', 'Cybersecurity', 'Graduate Research Seminar'] },
  { id: 'MATH', name: 'Mathematics', manager: 'Robert Hayes', courses: [
    ['Calculus I'], ['Calculus II'], ['Linear Algebra'], ['Statistics'], ['Calculus III'], ['Differential Equations'],
    ['Probability'], ['Abstract Algebra'], ['Real Analysis'], ['Numerical Methods'],
  ], grad: ['Advanced Linear Algebra', 'Topology', 'Stochastic Processes', 'Complex Analysis', 'Mathematical Modeling'] },
  { id: 'BIO', name: 'Biology', manager: 'Karen Diaz', courses: [
    ['General Biology I'], ['General Biology II'], ['Cell Biology'], ['Ecology'], ['Genetics'], ['Microbiology'],
    ['Human Anatomy'], ['Biochemistry'], ['Evolution'], ['Molecular Biology'],
  ], grad: ['Advanced Genetics', 'Bioinformatics', 'Immunology', 'Neurobiology', 'Research Methods in Biology'] },
  { id: 'BUS', name: 'Business Administration', manager: 'Thomas Reed', courses: [
    ['Principles of Management'], ['Financial Accounting'], ['Microeconomics'], ['Business Communication'], ['Marketing'],
    ['Managerial Accounting'], ['Business Law'], ['Corporate Finance'], ['Operations Management'], ['Strategic Management'],
  ], grad: ['Managerial Economics', 'Leadership', 'Business Analytics', 'International Business', 'MBA Capstone'] },
  { id: 'PSY', name: 'Psychology', manager: 'Angela Brooks', courses: [
    ['Introduction to Psychology'], ['Research Methods'], ['Developmental Psychology'], ['Social Psychology'],
    ['Cognitive Psychology'], ['Abnormal Psychology'], ['Biopsychology'], ['Personality'], ['Health Psychology'], ['Clinical Psychology'],
  ], grad: ['Advanced Statistics for Psychology', 'Psychopathology', 'Counseling Theories', 'Psychological Assessment', 'Thesis Seminar'] },
  { id: 'ENG', name: 'English', manager: 'Michelle Ortiz', courses: [
    ['College Writing'], ['Introduction to Literature'], ['American Literature'], ['British Literature'], ['Creative Writing'],
    ['Shakespeare'], ['Literary Theory'], ['World Literature'], ['Technical Writing'], ['Senior Seminar in English'],
  ], grad: ['Studies in the Novel', 'Postcolonial Literature', 'Rhetoric and Composition', 'Poetry Workshop', 'Graduate Writing Seminar'] },
];
const COURSE_COLS = ['course_ID','course_Name','dept_ID','course_Credits','course_Desc','course_Type'];
const courses = {}; // id -> {id, dept, credits, type, level, prereqs: []}
for (const d of DEPTS) {
  d.ug = []; d.gr = [];
  d.courses.forEach(([name], i) => {
    // 2 courses at 1000, 2 at 2000, 3 at 3000, 3 at 4000
    const level = i < 2 ? 1 : i < 4 ? 2 : i < 7 ? 3 : 4;
    const id = `${d.id}${level}${pad(10 + i * 5)}`;
    const credits = d.id === 'BIO' && level <= 2 ? 4 : 3;
    courses[id] = { id, dept: d.id, credits, type: 'Undergraduate', level, prereqs: [] };
    d.ug.push(id);
    insert('Course', COURSE_COLS, [id, name, d.id, credits, `${name} — ${level}000-level course offered by the ${d.name} department.`, 'Undergraduate']);
  });
  d.grad.forEach((name, i) => {
    const id = `${d.id}5${pad(10 + i * 10)}`;
    courses[id] = { id, dept: d.id, credits: 3, type: 'Graduate', level: 5, prereqs: [] };
    d.gr.push(id);
    insert('Course', COURSE_COLS, [id, name, d.id, 3, `${name} — graduate course offered by the ${d.name} department.`, 'Graduate']);
  });
  // Prerequisites: each upper course needs one or two lower courses in the same department.
  for (const id of d.ug) {
    const c = courses[id];
    if (c.level === 1) continue;
    const lower = d.ug.filter((x) => courses[x].level === c.level - 1);
    const reqs = shuffle(lower).slice(0, c.level >= 3 ? 2 : 1);
    for (const r of reqs) {
      c.prereqs.push(r);
      insert('Course_Prerequisite', ['course_ID','prerequisite_course_ID','min_Grade_Req'], [id, r, c.level === 4 ? 'C' : 'D']);
    }
  }
  const first = d.gr[0];
  for (const id of d.gr.slice(1, 3)) {
    courses[id].prereqs.push(first);
    insert('Course_Prerequisite', ['course_ID','prerequisite_course_ID','min_Grade_Req'], [id, first, 'B']);
  }
}

// ---------- faculty ----------
const RANKS = ['Professor', 'Associate Professor', 'Assistant Professor', 'Lecturer', 'Adjunct Professor'];
const SPECIALTY = {
  CS: ['Databases', 'Artificial Intelligence', 'Networks', 'Software Engineering', 'Cybersecurity'],
  MATH: ['Algebra', 'Analysis', 'Statistics', 'Topology', 'Applied Mathematics'],
  BIO: ['Genetics', 'Ecology', 'Microbiology', 'Neuroscience', 'Molecular Biology'],
  BUS: ['Finance', 'Marketing', 'Accounting', 'Management', 'Economics'],
  PSY: ['Clinical Psychology', 'Cognitive Science', 'Developmental Psychology', 'Social Psychology', 'Neuropsychology'],
  ENG: ['American Literature', 'Rhetoric', 'Creative Writing', 'British Literature', 'Linguistics'],
};
const FAC_PER_DEPT = 6;
const faculty = []; // {id, dept, type}
let facId = 200001;
for (const d of DEPTS) {
  d.faculty = [];
  for (let i = 0; i < FAC_PER_DEPT; i++) {
    const id = facId++;
    const isTest = id === 200001;
    const g = isTest ? 'Male' : pick(['Male', 'Female']);
    const p = isTest
      ? { first: 'Pavel', middle: null, last: 'Clarke', gender: 'Male', dob: '1984-08-17', street: '488 Pine St', city: 'Westbury', state: 'NY', zip: '11590' }
      : person(g, 1960, 1992);
    const rank = isTest ? 'Associate Professor' : RANKS[Math.min(i, RANKS.length - 1)];
    const type = rank === 'Adjunct Professor' || (rank === 'Lecturer' && chance(0.5)) ? 'Part-time' : 'Full-time';
    addUser(id, p, 'Faculty', isTest ? 'faculty@campus.edu' : null);
    const office = freeOffices.splice(int(0, freeOffices.length - 1), 1)[0];
    const f = { id, dept: d.id, type, office, specialty: SPECIALTY[d.id][i % 5], rank, classes: {} };
    faculty.push(f); d.faculty.push(f);
  }
}
// Department rows (chair = the senior professor), then faculty rows reference offices.
for (const d of DEPTS) {
  d.office = freeOffices.splice(0, 1)[0];
  insert('Department', ['dept_ID','dept_Name','chair_ID','email','phone','office_ID','dept_Manager'],
    [d.id, d.name, d.faculty[0].id, `${d.id.toLowerCase()}@campus.edu`, `(516) 876-${int(2000, 3999)}`, d.office, d.manager]);
}
// Two faculty members also hold a joint appointment in a second department.
const joint = [[DEPTS[0].faculty[2], 'MATH'], [DEPTS[4].faculty[3], 'BIO']];

// ---------- majors & minors ----------
const majors = {}; // id -> {id, dept, level}
const minors = {};
for (const d of DEPTS) {
  const ug = `${d.id}-BS`, gr = `${d.id}-MS`;
  const ugName = ['ENG', 'PSY'].includes(d.id) ? `B.A. in ${d.name}` : `B.S. in ${d.name}`;
  const grName = ['ENG', 'PSY'].includes(d.id) ? `M.A. in ${d.name}` : d.id === 'BUS' ? 'Master of Business Administration' : `M.S. in ${d.name}`;
  majors[ug] = { id: ug, dept: d.id, level: 'Undergraduate' };
  majors[gr] = { id: gr, dept: d.id, level: 'Graduate' };
  insert('Major', ['major_ID','dept_ID','major_Name','major_Level','credits_Required'], [ug, d.id, ugName, 'Undergraduate', 120]);
  insert('Major', ['major_ID','dept_ID','major_Name','major_Level','credits_Required'], [gr, d.id, grName, 'Graduate', d.id === 'BUS' ? 36 : 30]);
  for (const c of d.ug) insert('Major_Requirement', ['major_ID','course_ID'], [ug, c]);
  for (const c of d.gr) insert('Major_Requirement', ['major_ID','course_ID'], [gr, c]);
  const mn = `${d.id}-MIN`;
  minors[mn] = { id: mn, dept: d.id };
  insert('Minor', ['minor_ID','dept_ID','minor_Name','credits_Required'], [mn, d.id, `Minor in ${d.name}`, 18]);
  for (const c of d.ug.slice(0, 6)) insert('Minor_Requirement', ['minor_ID','course_ID'], [mn, c]);
}

// ---------- students ----------
const UG_COUNT = 180, GR_COUNT = 40;
const YEARS = ['Freshman', 'Sophomore', 'Junior', 'Senior'];
const students = []; // {id, type, dept, major, year, fullTime, program, startTerm, history: {course: grade}}
let stuId = 100001;
for (let i = 0; i < UG_COUNT + GR_COUNT; i++) {
  const id = stuId++;
  const isTest = id === 100001;
  const grad = i >= UG_COUNT;
  const d = isTest ? DEPTS[0] : pick(DEPTS);
  const g = chance(0.03) ? 'Other' : pick(['Male', 'Female']);
  const p = isTest
    ? { first: 'Julio', middle: null, last: 'Larrea', gender: 'Male', dob: '2004-03-14', street: '520 Lake View Rd', city: 'Hicksville', state: 'NY', zip: '11801' }
    : grad ? person(g, 1990, 2002) : person(g, 2003, 2008);
  const s = {
    id, grad, dept: d.id,
    major: grad ? `${d.id}-MS` : `${d.id}-BS`,
    year: isTest ? 'Junior' : grad ? pick(['First Year', 'Second Year']) : pick(YEARS),
    fullTime: isTest ? true : chance(grad ? 0.6 : 0.8),
    program: grad ? (chance(0.8) ? 'MA/MS' : 'PhD') : null,
    history: {}, credits: 0,
  };
  // Which semesters this student has attended (the current one is FA26).
  const terms = { Freshman: 1, Sophomore: 3, Junior: 5, Senior: 5, 'First Year': 1, 'Second Year': 3 }[s.year];
  s.terms = ['FA24', 'SP25', 'FA25', 'SP26', 'FA26'].slice(5 - terms);
  addUser(id, p, 'Student', isTest ? 'student@campus.edu' : null);
  students.push(s);
}

// ---------- admins & statistics department ----------
const STAFF = [
  [300001, { first: 'Muiz', middle: 'M.', last: 'Onifade', gender: 'Male', dob: '1990-05-22', street: '75 Post Ave', city: 'Westbury', state: 'NY', zip: '11590' }, 'Admin', 'admin@campus.edu', 'Read-write'],
  [300002, person('Female', 1975, 1995), 'Admin', null, 'Read-write'],
  [300003, person('Male', 1975, 1995), 'Admin', null, 'Read-only'],
  [400001, person('Female', 1970, 1995), 'StatDept', 'stat@campus.edu', 'Aggregate'],
  [400002, person('Male', 1970, 1995), 'StatDept', null, 'Detailed'],
];
for (const [id, p, type, email, level] of STAFF) {
  addUser(id, p, type, email);
  if (type === 'Admin') insert('Admin', ['admin_ID','security_Level'], [id, level]);
  else insert('Stat_Dept_Member', ['stat_dept_ID','access_Level'], [id, level]);
}

// ---------- calendar: days, periods, time slots, semesters ----------
const DAYS = [['M','Monday'],['T','Tuesday'],['W','Wednesday'],['R','Thursday'],['F','Friday']];
for (const d of DAYS) insert('Day', ['day_ID','week_Day'], d);
const PERIODS = [['08:00:00','09:20:00'],['09:30:00','10:50:00'],['11:00:00','12:20:00'],['13:00:00','14:20:00'],['14:30:00','15:50:00'],['16:00:00','17:20:00'],['18:00:00','19:20:00']];
PERIODS.forEach(([s, e], i) => insert('Period', ['period_ID','start_Time','end_Time'], [i + 1, s, e]));
const slots = []; // {id, days, period}
let slotId = 1;
for (const days of [['M','W'], ['T','R']]) {
  for (let p = 1; p <= PERIODS.length; p++) slots.push({ id: slotId++, days, period: p });
}
for (let p = 1; p <= 3; p++) slots.push({ id: slotId++, days: ['F'], period: p });
for (const s of slots) {
  insert('Time_Slot', ['time_Slot_ID'], [s.id]);
  for (const d of s.days) insert('Time_Slot_Day', ['time_Slot_ID','day_ID'], [s.id, d]);
  insert('Time_Slot_Period', ['time_Slot_ID','period_ID'], [s.id, s.period]);
}
const SEMESTERS = [
  { id: 'FA24', name: 'Fall 2024',   start: '2024-08-28', end: '2024-12-20' },
  { id: 'SP25', name: 'Spring 2025', start: '2025-01-27', end: '2025-05-16' },
  { id: 'FA25', name: 'Fall 2025',   start: '2025-08-27', end: '2025-12-19' },
  { id: 'SP26', name: 'Spring 2026', start: '2026-01-26', end: '2026-05-15' },
  { id: 'FA26', name: 'Fall 2026',   start: '2026-08-26', end: '2026-12-18' },
  { id: 'SP27', name: 'Spring 2027', start: '2027-01-25', end: '2027-05-14' },
];
for (const s of SEMESTERS) {
  insert('Semester', ['semester_ID','semester_Name','start_Date','end_Date','add_Start','add_End','drop_End','grade_Start','grade_End'],
    [s.id, s.name, s.start, s.end, addDays(s.start, -60), addDays(s.start, 7), addDays(s.start, 21), addDays(s.end, -7), addDays(s.end, 7)]);
}

// ---------- classes (sections) per semester ----------
// No faculty member or room is double-booked; part-time faculty teach at most 2 classes, full-time at most 4.
const classes = []; // {crn, course, section, faculty, slot, lab, sem, capacity, enrolled: []}
let crn = 10001;
const ACTIVE = SEMESTERS.map((s) => s.id);
for (const sem of ACTIVE) {
  const roomBusy = new Set(), facBusy = new Set();
  const load = {};
  for (const d of DEPTS) {
    // Lower-level and core courses get two sections, everything else one.
    const offerings = [];
    for (const c of d.ug) offerings.push(...Array(courses[c].level <= 2 ? 2 : 1).fill(c));
    for (const c of d.gr) offerings.push(c);
    for (const cid of offerings) {
      const section = pad(classes.filter((k) => k.course === cid && k.sem === sem).length + 1, 3);
      let placed = false;
      for (const slot of shuffle(slots)) {
        const prof = shuffle(d.faculty).find((f) => !facBusy.has(`${f.id}:${slot.id}`) && (load[f.id] || 0) < (f.type === 'Full-time' ? 4 : 2));
        const lab = shuffle(labs).find((l) => !roomBusy.has(`${l}:${slot.id}`));
        if (!prof || !lab) continue;
        facBusy.add(`${prof.id}:${slot.id}`); roomBusy.add(`${lab}:${slot.id}`);
        load[prof.id] = (load[prof.id] || 0) + 1;
        classes.push({ crn: crn++, course: cid, section, faculty: prof.id, slot, lab, sem, capacity: 10, enrolled: [] });
        placed = true;
        break;
      }
      if (!placed) { // No instructor free: offer the class as TBA staff.
        const slot = pick(slots);
        const lab = shuffle(labs).find((l) => !roomBusy.has(`${l}:${slot.id}`));
        roomBusy.add(`${lab}:${slot.id}`);
        classes.push({ crn: crn++, course: cid, section, faculty: null, slot, lab, sem, capacity: 10, enrolled: [] });
      }
    }
  }
  if (sem === 'FA26') for (const f of faculty) f.current = load[f.id] || 0;
}
// A couple of current-semester sections were cancelled for low demand.
const cancelled = new Set(shuffle(classes.filter((c) => c.sem === 'FA26' && c.section === '002')).slice(0, 3).map((c) => c.crn));

// ---------- enrollment, grades, history ----------
const GRADES = ['A','A','A-','A-','B+','B+','B','B','B','B-','C+','C','C','C-','D+','D','F'];
const GRADE_RANK = { A: 11, 'A-': 10, 'B+': 9, B: 8, 'B-': 7, 'C+': 6, C: 5, 'C-': 4, 'D+': 3, D: 2, F: 0 };
const passed = (s, cid, min = 'D') => s.history[cid] !== undefined && GRADE_RANK[s.history[cid]] >= GRADE_RANK[min];
const prereqOK = (s, cid) => courses[cid].prereqs.every((p) => passed(s, p, courses[cid].level === 4 ? 'C' : courses[cid].level === 5 ? 'B' : 'D'));

function enrollTerm(sem, graded) {
  const open = classes.filter((c) => c.sem === sem && !cancelled.has(c.crn));
  for (const s of shuffle(students)) {
    if (!s.terms.includes(sem)) continue;
    const target = s.grad ? (s.fullTime ? 3 : 2) : (s.fullTime ? 4 : 2);
    const busy = new Set();
    let n = 0;
    // Prefer courses in the student's own department, then electives elsewhere.
    const own = open.filter((c) => courses[c.course].dept === s.dept);
    const other = shuffle(open.filter((c) => courses[c.course].dept !== s.dept));
    // Students take the most advanced courses they qualify for first.
    const ordered = [...shuffle(own).sort((a, b) => courses[b.course].level - courses[a.course].level), ...other];
    for (const c of ordered) {
      if (n >= target) break;
      const course = courses[c.course];
      if ((course.type === 'Graduate') !== s.grad) continue;
      if (c.enrolled.length >= c.capacity || busy.has(c.slot.id)) continue;
      if (s.history[c.course] !== undefined && passed(s, c.course, 'C')) continue;
      if (s.enrolledNow?.has(c.course)) continue;
      if (!prereqOK(s, c.course)) continue;
      if (courses[c.course].dept !== s.dept && course.level > 2) continue;
      busy.add(c.slot.id);
      (s.enrolledNow ||= new Set()).add(c.course);
      const grade = graded ? pick(GRADES) : null;
      c.enrolled.push({ student: s.id, grade });
      n++;
    }
    s.enrolledNow = new Set();
    // Grades from finished semesters count toward history and credits.
    if (graded) {
      for (const c of open) {
        const e = c.enrolled.find((x) => x.student === s.id);
        if (!e) continue;
        s.history[c.course] = e.grade;
        if (e.grade !== 'F') s.credits += courses[c.course].credits;
      }
    }
  }
}
for (const sem of ['FA24', 'SP25', 'FA25', 'SP26']) enrollTerm(sem, true);
enrollTerm('FA26', false);

for (const c of classes) {
  const isCancelled = cancelled.has(c.crn);
  insert('Class', ['CRN','course_ID','section_No','faculty_ID','time_Slot_ID','lab_ID','semester_ID','capacity','available_Seats','status'],
    [c.crn, c.course, c.section, c.faculty, c.slot.id, c.lab, c.sem, c.capacity, c.capacity - c.enrolled.length, isCancelled ? 'Cancelled' : 'Open']);
}
const finished = new Set(['FA24', 'SP25', 'FA25', 'SP26']);
for (const c of classes) {
  for (const e of c.enrolled) {
    insert('Enrollment', ['student_ID','CRN','semester_ID','grade'], [e.student, c.crn, c.sem, e.grade]);
    if (finished.has(c.sem)) insert('Student_History', ['student_ID','CRN','course_ID','semester_ID','grade'], [e.student, c.crn, c.course, c.sem, e.grade]);
  }
  if (finished.has(c.sem) && c.faculty) insert('Faculty_History', ['faculty_ID','CRN','course_ID','semester_ID'], [c.faculty, c.crn, c.course, c.sem]);
}

// ---------- attendance: every class meeting of Fall 2026 so far (through Oct 2, 2026) ----------
const DAY_INDEX = { M: 1, T: 2, W: 3, R: 4, F: 5 };
const fa26 = SEMESTERS.find((s) => s.id === 'FA26');
for (const c of classes.filter((k) => k.sem === 'FA26' && !cancelled.has(k.crn))) {
  for (let day = fa26.start; day <= '2026-10-02'; day = addDays(day, 1)) {
    const dow = new Date(day + 'T00:00:00Z').getUTCDay();
    if (!c.slot.days.some((d) => DAY_INDEX[d] === dow)) continue;
    if (day === '2026-09-07') continue; // Labor Day
    for (const e of c.enrolled) {
      insert('Attendance', ['CRN','student_ID','course_ID','attendance_Date','attendance_Status'],
        [c.crn, e.student, c.course, day, chance(0.9) ? 'Present' : 'Absent']);
    }
  }
}

// ---------- faculty, student subtype rows ----------
for (const f of faculty) {
  insert('Faculty', ['faculty_ID','office_ID','specialty','`rank`','faculty_Type','no_of_Classes'], [f.id, f.office, f.specialty, f.rank, f.type, f.current]);
  insert('Faculty_Department', ['faculty_ID','dept_ID','percent_Time','date_Of_Appointment'], [f.id, f.dept, 100, date(int(2005, 2024), int(1, 12), 1)]);
}
for (const [f, dept] of joint) {
  tables.Faculty_Department.rows.find((r) => r[0] === f.id)[2] = 75;
  insert('Faculty_Department', ['faculty_ID','dept_ID','percent_Time','date_Of_Appointment'], [f.id, dept, 25, date(int(2015, 2024), 9, 1)]);
}

insert('Hold', ['hold_ID','hold_Type'], [1, 'Academic']);
insert('Hold', ['hold_ID','hold_Type'], [2, 'Financial']);
insert('Hold', ['hold_ID','hold_Type'], [3, 'Health']);
insert('Hold', ['hold_ID','hold_Type'], [4, 'Disciplinary']);

const startDate = { FA24: '2024-08-15', FA25: '2025-08-15', FA26: '2026-08-15' };
for (const s of students) {
  insert('Student', ['student_ID','major_ID','student_Year','student_Type'], [s.id, s.major, s.year, s.grad ? 'Graduate' : 'Undergraduate']);
  const type = s.fullTime ? 'Full-time' : 'Part-time';
  const gpaBad = Object.values(s.history).filter((g) => g === 'F').length >= 2;
  if (!s.grad) {
    insert('Undergraduate_Student', ['student_ID','dept_ID','undergraduate_Student_Type'], [s.id, s.dept, type]);
    insert(s.fullTime ? 'Full_Time_Undergraduate' : 'Part_Time_Undergraduate', ['student_ID','status','min_Credits','max_Credits','credits_Earned'],
      [s.id, gpaBad ? 'Academic Probation' : 'Good Standing', s.fullTime ? 12 : 1, s.fullTime ? 16 : 8, s.credits]);
  } else {
    insert('Graduate_Student', ['student_ID','dept_ID','program','graduate_Student_Type'], [s.id, s.dept, s.program, type]);
    const thesis = s.program === 'PhD' || (s.year === 'Second Year' && chance(0.5)) ? `Thesis research in ${pick(SPECIALTY[s.dept])}` : null;
    insert(s.fullTime ? 'Full_Time_Graduate_Student' : 'Part_Time_Graduate_Student', ['student_ID','year','credits_Earned','thesis'],
      [s.id, s.year === 'First Year' ? 1 : 2, s.credits, thesis]);
  }
  const chosen = startDate[s.terms[0]];
  insert('Student_Major', ['student_ID','major_ID','date_Of_Choice'], [s.id, s.major, chosen]);
  if (!s.grad && s.year !== 'Freshman' && chance(0.3)) {
    const mn = pick(Object.values(minors).filter((m) => m.dept !== s.dept));
    insert('Student_Minor', ['student_ID','minor_ID','date_Of_Choice'], [s.id, mn.id, '2026-02-01']);
  }
  const advisor = pick(DEPTS.find((d) => d.id === s.dept).faculty.filter((f) => f.type === 'Full-time'));
  insert('Advisor', ['faculty_ID','student_ID','date_Of_Appnt'], [advisor.id, s.id, chosen]);
  if (s.id !== 100001) {
    if (gpaBad) insert('Student_Hold', ['student_ID','hold_ID','hold_Date'], [s.id, 1, '2026-06-01']);
    if (chance(0.08)) insert('Student_Hold', ['student_ID','hold_ID','hold_Date'], [s.id, 2, date(2026, int(8, 9), int(1, 28))]);
    if (chance(0.02)) insert('Student_Hold', ['student_ID','hold_ID','hold_Date'], [s.id, 3, date(2026, 9, int(1, 28))]);
    if (chance(0.01)) insert('Student_Hold', ['student_ID','hold_ID','hold_Date'], [s.id, 4, date(2026, 9, int(1, 28))]);
  }
}

// ---------- write the SQL file in foreign-key order ----------
const ORDER = ['User','Login','Office','Lab','Faculty','Department','Faculty_Department','Major','Minor','Student',
  'Undergraduate_Student','Full_Time_Undergraduate','Part_Time_Undergraduate','Graduate_Student','Full_Time_Graduate_Student',
  'Part_Time_Graduate_Student','Admin','Stat_Dept_Member','Student_Major','Student_Minor','Advisor','Hold','Student_Hold',
  'Course','Course_Prerequisite','Major_Requirement','Minor_Requirement','Day','Period','Time_Slot','Time_Slot_Day',
  'Time_Slot_Period','Semester','Class','Enrollment','Student_History','Faculty_History','Attendance'];
const missing = ORDER.filter((t) => !tables[t]);
if (missing.length) throw new Error(`No rows generated for: ${missing.join(', ')}`);

const out = [
  '-- Campus Connect project data (generated by db/generate-data.js — do not edit by hand)',
  '-- Load after the schema:  mysql -u root -p campus_connect < db/Project_data.sql',
  `-- All ${tables.Login.rows.length} accounts use the password ${PASSWORD}`,
  'USE campus_connect;',
  'SET FOREIGN_KEY_CHECKS = 0;',
  '',
];
for (const t of ORDER) {
  const { cols, rows } = tables[t];
  out.push(`-- ${t}: ${rows.length} rows`);
  for (let i = 0; i < rows.length; i += 200) {
    out.push(`INSERT INTO ${t} (${cols.join(', ')}) VALUES`);
    out.push(rows.slice(i, i + 200).map((r) => `  (${r.map(sqlValue).join(', ')})`).join(',\n') + ';');
  }
  out.push('');
}
out.push('SET FOREIGN_KEY_CHECKS = 1;', '');
fs.writeFileSync(path.join(__dirname, 'Project_data.sql'), out.join('\n'));

const summary = ORDER.map((t) => `${t.padEnd(28)} ${tables[t].rows.length}`).join('\n');
console.log(`Wrote db/Project_data.sql\n\n${summary}`);
