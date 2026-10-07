// Generates db/Project_data.sql: realistic, consistent data for every table in
// db/Database_schema.sql, following the rules in the System Manual's SRS.
// The output is the same every run (seeded random).
//   node db/generate-data.js
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

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

// Every account uses Campus123!, stored as a bcrypt hash made by PHP's password_hash(),
// the same function the website (api/accounts.php) uses. PHP must be installed to run this script.
// The hashes are made in one batch, the first time they are needed.
const PASSWORD = 'Campus123!';
const bcryptHashes = [];
function hashPassword() {
  // Draw the same random numbers the old salt did, so the rest of the generated data stays identical.
  Array.from({ length: 16 }, () => int(0, 255));
  if (!bcryptHashes.length) {
    const out = execFileSync('php', ['-r', 'for ($i = 0; $i < 500; $i++) echo password_hash($argv[1], PASSWORD_BCRYPT), "\\n";', '--', PASSWORD]);
    bcryptHashes.push(...out.toString().trim().split('\n'));
  }
  return bcryptHashes.pop();
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

// ---------- the rules from the System Manual ----------
const SECTION_SEATS = 10;        // A-R53: at most 10 students per section
const MIN_TO_RUN = 5;            // A-R45/46: sections under 5 students are cancelled before the semester starts
const TEACH_LOAD = { 'Full-time': 2, 'Part-time': 1 }; // F-R6/F-R7
const MAX_ADVISEES = 15;         // F-F9 (part-time faculty do not advise: F-F10)
const MAX_CREDITS = { 'Full-time': 16, 'Part-time': 8 }; // S-F7/S-F8

// ---------- names and addresses ----------
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
const newStreet = () => `${int(10, 999)} ${pick(STREETS)}`;

const usedEmails = new Set();
function person(gender, yearFrom, yearTo) {
  const first = gender === 'Female' ? pick(FIRST_F) : pick(FIRST_M);
  const [city, zip] = pick(TOWNS);
  return {
    first, middle: pick(MIDDLE), last: pick(LAST), gender,
    dob: date(int(yearFrom, yearTo), int(1, 12), int(1, 28)),
    street: newStreet(), city, state: 'NY', zip,
  };
}
function emailFor(first, last) {
  const base = `${first}.${last}`.toLowerCase().replace(/[^a-z.]/g, '');
  let email = `${base}@campus.edu`, n = 2;
  while (usedEmails.has(email)) email = `${base}${n++}@campus.edu`;
  return email;
}
const users = {}; // id -> person
function addUser(id, p, type, email) {
  users[id] = p;
  insert('User', ['user_ID','first_Name','middle_Name','last_Name','gender','DOB','street','city','state','zip_Code','user_Type'],
    [id, p.first, p.middle, p.last, p.gender, p.dob, p.street, p.city, p.state, p.zip, type]);
  email = email || emailFor(p.first, p.last);
  usedEmails.add(email);
  insert('Login', ['user_ID','user_Email','user_Password','no_Of_Tries','lock_Var'], [id, email, hashPassword(), 0, 0]);
}

// ---------- buildings, offices, rooms ----------
const BUILDINGS = [['ACB', 'Academic Building'], ['NSC', 'Natural Sciences'], ['CCT', 'Campus Center'], ['LIB', 'Library Hall'], ['BUS', 'Business Center']];
const freeOffices = [];
const rooms = [];
for (const [b, name] of BUILDINGS) {
  insert('Building', ['building_ID','building_Name'], [b, name]);
  for (let r = 1; r <= 14; r++) {
    const id = `${b}-${200 + r}`;
    freeOffices.push(id);
    insert('Office', ['office_ID','building_ID','room_No'], [id, b, String(200 + r)]);
  }
  for (let r = 1; r <= 6; r++) {
    const lab = r > 4;
    const id = `${b}-${100 + r}`;
    rooms.push(id);
    insert('Room', ['room_ID','building_ID','room_No','room_Type','capacity'], [id, b, String(100 + r), lab ? 'Lab' : 'Lecture', lab ? pick([20, 24]) : pick([30, 35, 40])]);
  }
}
const takeOffice = () => freeOffices.splice(int(0, freeOffices.length - 1), 1)[0];

// ---------- departments and courses ----------
const DEPTS = [
  { id: 'CS', name: 'Computer Science', manager: 'Linda Park',
    ug: ['Introduction to Programming', 'Data Structures', 'Discrete Mathematics', 'Computer Organization', 'Algorithms', 'Database Systems', 'Operating Systems', 'Software Engineering', 'Computer Networks', 'System Design and Implementation'],
    gr: ['Advanced Algorithms', 'Machine Learning', 'Distributed Systems', 'Cybersecurity', 'Graduate Research Seminar'],
    specialty: ['Databases', 'Artificial Intelligence', 'Networks', 'Software Engineering', 'Cybersecurity'] },
  { id: 'MATH', name: 'Mathematics', manager: 'Robert Hayes',
    ug: ['Calculus I', 'Calculus II', 'Linear Algebra', 'Statistics', 'Calculus III', 'Differential Equations', 'Probability', 'Abstract Algebra', 'Real Analysis', 'Numerical Methods'],
    gr: ['Advanced Linear Algebra', 'Topology', 'Stochastic Processes', 'Complex Analysis', 'Mathematical Modeling'],
    specialty: ['Algebra', 'Analysis', 'Statistics', 'Topology', 'Applied Mathematics'] },
  { id: 'BIO', name: 'Biology', manager: 'Karen Diaz',
    ug: ['General Biology I', 'General Biology II', 'Cell Biology', 'Ecology', 'Genetics', 'Microbiology', 'Human Anatomy', 'Biochemistry', 'Evolution', 'Molecular Biology'],
    gr: ['Advanced Genetics', 'Bioinformatics', 'Immunology', 'Neurobiology', 'Research Methods in Biology'],
    specialty: ['Genetics', 'Ecology', 'Microbiology', 'Neuroscience', 'Molecular Biology'] },
  { id: 'BUS', name: 'Business Administration', manager: 'Thomas Reed',
    ug: ['Principles of Management', 'Financial Accounting', 'Microeconomics', 'Business Communication', 'Marketing', 'Managerial Accounting', 'Business Law', 'Corporate Finance', 'Operations Management', 'Strategic Management'],
    gr: ['Managerial Economics', 'Leadership', 'Business Analytics', 'International Business', 'MBA Capstone'],
    specialty: ['Finance', 'Marketing', 'Accounting', 'Management', 'Economics'] },
  { id: 'PSY', name: 'Psychology', manager: 'Angela Brooks',
    ug: ['Introduction to Psychology', 'Research Methods', 'Developmental Psychology', 'Social Psychology', 'Cognitive Psychology', 'Abnormal Psychology', 'Biopsychology', 'Personality', 'Health Psychology', 'Clinical Psychology'],
    gr: ['Advanced Statistics for Psychology', 'Psychopathology', 'Counseling Theories', 'Psychological Assessment', 'Thesis Seminar'],
    specialty: ['Clinical Psychology', 'Cognitive Science', 'Developmental Psychology', 'Social Psychology', 'Neuropsychology'] },
  { id: 'ENG', name: 'English', manager: 'Michelle Ortiz',
    ug: ['College Writing', 'Introduction to Literature', 'American Literature', 'British Literature', 'Creative Writing', 'Shakespeare', 'Literary Theory', 'World Literature', 'Technical Writing', 'Senior Seminar in English'],
    gr: ['Studies in the Novel', 'Postcolonial Literature', 'Rhetoric and Composition', 'Poetry Workshop', 'Graduate Writing Seminar'],
    specialty: ['American Literature', 'Rhetoric', 'Creative Writing', 'British Literature', 'Linguistics'] },
];
const deptById = Object.fromEntries(DEPTS.map((d) => [d.id, d]));
const courses = {}; // id -> {id, dept, credits, grad, level, prereqs: [{id, min}]}
const COURSE_COLS = ['course_ID','course_Name','dept_ID','course_Credits','course_Desc','course_Type'];
for (const d of DEPTS) {
  d.ugIds = d.ug.map((name, i) => {
    const level = i < 2 ? 1 : i < 4 ? 2 : i < 7 ? 3 : 4; // 2 intro, 2 sophomore, 3 junior, 3 senior courses
    const id = `${d.id}${level}${pad(10 + i * 5)}`;
    const credits = d.id === 'BIO' && level <= 2 ? 4 : 3;
    courses[id] = { id, dept: d.id, credits, grad: false, level, prereqs: [] };
    insert('Course', COURSE_COLS, [id, name, d.id, credits, `${name}: a ${level}000-level course in ${d.name}.`, 'Undergraduate']);
    return id;
  });
  d.grIds = d.gr.map((name, i) => {
    const id = `${d.id}5${pad(10 + i * 10)}`;
    courses[id] = { id, dept: d.id, credits: 3, grad: true, level: 5, prereqs: [] };
    insert('Course', COURSE_COLS, [id, name, d.id, 3, `${name}: a graduate course in ${d.name}.`, 'Graduate']);
    return id;
  });
  // Each upper-level course requires one or two courses from the level below it.
  for (const id of d.ugIds) {
    const c = courses[id];
    if (c.level === 1) continue;
    const lower = d.ugIds.filter((x) => courses[x].level === c.level - 1);
    for (const p of shuffle(lower).slice(0, c.level >= 3 ? 2 : 1)) c.prereqs.push({ id: p, min: c.level === 4 ? 'C' : 'D' });
  }
  for (const id of d.grIds.slice(1, 3)) courses[id].prereqs.push({ id: d.grIds[0], min: 'B' });
}
for (const c of Object.values(courses)) {
  for (const p of c.prereqs) insert('Course_Prerequisite', ['course_ID','prerequisite_Course_ID','min_Grade_Req'], [c.id, p.id, p.min]);
}

// ---------- faculty: 8 full-time and 2 part-time per department ----------
const RANKS = ['Professor', 'Professor', 'Associate Professor', 'Associate Professor', 'Associate Professor',
  'Assistant Professor', 'Assistant Professor', 'Assistant Professor', 'Lecturer', 'Adjunct Professor'];
const faculty = {}; // id -> {id, dept, type, depts}
let nextFac = 200001;
for (const d of DEPTS) {
  d.faculty = [];
  RANKS.forEach((rank, i) => {
    const id = nextFac++;
    const type = i >= 8 ? 'Part-time' : 'Full-time';
    const isTest = id === 200001;
    const p = isTest
      ? { first: 'Pavel', middle: null, last: 'Clarke', gender: 'Male', dob: '1984-08-17', street: '488 Pine St', city: 'Westbury', state: 'NY', zip: '11590' }
      : person(pick(['Male', 'Female']), 1958, 1994);
    addUser(id, p, 'Faculty', isTest ? 'faculty@campus.edu' : null);
    const f = { id, dept: d.id, type, rank: isTest ? 'Associate Professor' : rank, office: takeOffice(), specialty: d.specialty[i % 5], depts: [d.id] };
    faculty[id] = f; d.faculty.push(f);
  });
}
for (const f of Object.values(faculty)) {
  insert('Faculty', ['faculty_ID','office_ID','specialty','`rank`','faculty_Type'], [f.id, f.office, f.specialty, f.rank, f.type]);
}
for (const d of DEPTS) {
  insert('Department', ['dept_ID','dept_Name','chair_ID','email','phone_No','office_ID','dept_Manager'],
    [d.id, d.name, d.faculty[0].id, `${d.id.toLowerCase()}@campus.edu`, `(516) 876-${int(2000, 3999)}`, takeOffice(), d.manager]);
}
// Department membership: part-time faculty belong to exactly one department (F-F7);
// a few full-time faculty hold joint appointments, never more than three departments (F-F6).
const JOINT = [[deptById.CS.faculty[3], ['MATH']], [deptById.PSY.faculty[4], ['BIO']], [deptById.BUS.faculty[2], ['MATH', 'ENG']]];
for (const f of Object.values(faculty)) {
  const joint = JOINT.find(([jf]) => jf === f);
  const others = joint ? joint[1] : [];
  f.depts.push(...others);
  const share = others.length === 0 ? 100 : others.length === 1 ? 75 : 60;
  insert('Faculty_Department', ['faculty_ID','dept_ID','percent_Time','date_Of_Appointment'], [f.id, f.dept, share, date(int(2005, 2022), pick([1, 8]), 15)]);
  others.forEach((o, i) => insert('Faculty_Department', ['faculty_ID','dept_ID','percent_Time','date_Of_Appointment'],
    [f.id, o, others.length === 1 ? 25 : 20, date(2023 + i, 8, 15)]));
}

// ---------- majors and minors ----------
for (const d of DEPTS) {
  const arts = ['ENG', 'PSY'].includes(d.id);
  d.major = `${d.id}-BS`; d.gradMajor = `${d.id}-MS`; d.minor = `${d.id}-MIN`;
  insert('Major', ['major_ID','dept_ID','major_Name'], [d.major, d.id, `${arts ? 'B.A.' : 'B.S.'} in ${d.name}`]);
  insert('Major', ['major_ID','dept_ID','major_Name'], [d.gradMajor, d.id, d.id === 'BUS' ? 'Master of Business Administration' : `${arts ? 'M.A.' : 'M.S.'} in ${d.name}`]);
  insert('Minor', ['minor_ID','dept_ID','minor_Name'], [d.minor, d.id, `Minor in ${d.name}`]);
  for (const c of d.ugIds) insert('Major_Course_Requirement', ['major_ID','course_ID'], [d.major, c]);
  for (const c of d.grIds) insert('Major_Course_Requirement', ['major_ID','course_ID'], [d.gradMajor, c]);
  for (const c of d.ugIds.slice(0, 6)) insert('Minor_Course_Requirement', ['minor_ID','course_ID'], [d.minor, c]);
}

// ---------- calendar ----------
const DAYS = [['M','Monday'],['T','Tuesday'],['W','Wednesday'],['R','Thursday'],['F','Friday']];
for (const d of DAYS) insert('Day', ['day_ID','week_Day'], d);
const PERIODS = [['08:00:00','09:20:00'],['09:30:00','10:50:00'],['11:00:00','12:20:00'],['13:00:00','14:20:00'],['14:30:00','15:50:00'],['16:00:00','17:20:00'],['18:00:00','19:20:00']];
PERIODS.forEach(([s, e], i) => insert('Period', ['period_ID','start_Time','end_Time'], [i + 1, s, e]));
const slots = []; // {id, days, period}
for (const days of [['M','W'], ['T','R']]) {
  for (let p = 1; p <= PERIODS.length; p++) slots.push({ id: slots.length + 1, days, period: p });
}
for (let p = 1; p <= 3; p++) slots.push({ id: slots.length + 1, days: ['F'], period: p });
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
  { id: 'FA26', name: 'Fall 2026',   start: '2026-08-26', end: '2026-12-18' }, // current semester
  // Next semester. Registration opened Oct 1, 2026 (A-R25), so students can add and drop Spring 2027 sections now.
  { id: 'SP27', name: 'Spring 2027', start: '2027-01-25', end: '2027-05-14', addStart: '2026-10-01' },
];
for (const s of SEMESTERS) {
  const addStart = s.addStart || addDays(s.start, -60);
  insert('Semester', ['semester_ID','semester_Name','start_Date','end_Date','add_Start','add_End','drop_Start','drop_End','grade_Start','grade_End'],
    [s.id, s.name, s.start, s.end, addStart, addDays(s.start, 7), addStart, addDays(s.start, 21), addDays(s.end, -7), addDays(s.end, 7)]);
}
const PAST = ['FA24', 'SP25', 'FA25', 'SP26'];

// ---------- students: 180 undergraduate, 60 graduate ----------
const students = [];
let nextStu = 100001;
function addStudent(grad, d, opts = {}) {
  const id = nextStu++;
  const gender = chance(0.03) ? 'Other' : pick(['Male', 'Female']);
  const p = opts.person || (grad ? person(gender, 1990, 2002) : person(gender, 2003, 2008));
  addUser(id, p, 'Student', opts.email);
  const year = opts.year || (grad ? pick(['First Year', 'Second Year']) : pick(['Freshman', 'Sophomore', 'Junior', 'Senior']));
  const termsAttended = { Freshman: 1, Sophomore: 3, Junior: 5, Senior: 5, 'First Year': 1, 'Second Year': 3 }[year];
  const s = {
    id, grad, dept: d.id, year,
    type: opts.type || (chance(grad ? 0.65 : 0.8) ? 'Full-time' : 'Part-time'),
    program: grad ? (chance(0.8) ? 'MA/MS' : 'PhD') : null,
    terms: ['FA24', 'SP25', 'FA25', 'SP26', 'FA26'].slice(5 - termsAttended),
    history: {}, credits: 0, majors: [grad ? d.gradMajor : d.major], minors: [],
  };
  students.push(s);
  return s;
}
addStudent(false, deptById.CS, {
  year: 'Junior', type: 'Full-time', email: 'student@campus.edu',
  person: { first: 'Julio', middle: null, last: 'Larrea', gender: 'Male', dob: '2004-03-14', street: '520 Lake View Rd', city: 'Hicksville', state: 'NY', zip: '11801' },
});
for (let i = 1; i < 180; i++) addStudent(false, DEPTS[i % DEPTS.length]);
for (let i = 0; i < 60; i++) addStudent(true, DEPTS[i % DEPTS.length]);
// Some upperclassmen have a minor or a second major in another department (S-R26, S-R28).
for (const s of students.filter((x) => !x.grad && x.year !== 'Freshman' && x.id !== 100001)) {
  const other = pick(DEPTS.filter((d) => d.id !== s.dept));
  if (chance(0.25)) s.minors.push(other.minor);
  else if (chance(0.05)) s.majors.push(other.major);
}

// ---------- course sections ----------
// Each department offers 18 sections a semester: exactly its teaching capacity
// (8 full-time x 2 + 2 part-time x 1). No faculty member or room is double-booked.
const sections = []; // {crn, course, sectionNo, faculty, slot, room, sem, students: [{id, grade}]}
let nextCRN = 10001;
SEMESTERS.forEach((sem, si) => {
  const roomBusy = new Set();
  for (const d of DEPTS) {
    const intro = d.ugIds.filter((c) => courses[c].level <= 2);
    const gradOffer = [d.grIds[0], d.grIds[1 + (si % 4)]];
    // Spring 2027 is still being scheduled: 14 sections per department, which leaves teaching
    // capacity free so an admin can create more sections (A-R25, A-R26).
    const extra = sem.id === 'SP27' ? [] : d.ugIds.filter((c) => courses[c].level === 3).slice(0, 2);
    const offerings = [...d.ugIds, ...intro.slice(0, sem.id === 'SP27' ? 2 : 4), ...gradOffer, ...extra];
    // Part-time faculty first so each gets exactly one section.
    const teachers = [...d.faculty.filter((f) => f.type === 'Part-time'), ...shuffle(d.faculty.filter((f) => f.type === 'Full-time'))];
    const load = new Map(teachers.map((f) => [f.id, 0]));
    const busy = new Set();
    for (const cid of offerings) {
      const sectionNo = pad(sections.filter((k) => k.course === cid && k.sem === sem.id).length + 1, 3);
      let placed = null;
      for (const f of teachers) {
        if (load.get(f.id) >= TEACH_LOAD[f.type]) continue;
        for (const slot of shuffle(slots)) {
          if (busy.has(`${f.id}:${slot.id}`)) continue;
          const room = shuffle(rooms).find((r) => !roomBusy.has(`${r}:${slot.id}`));
          if (!room) continue;
          placed = { f, slot, room };
          break;
        }
        if (placed) break;
      }
      if (!placed) throw new Error(`Could not schedule ${cid} in ${sem.id}`);
      load.set(placed.f.id, load.get(placed.f.id) + 1);
      busy.add(`${placed.f.id}:${placed.slot.id}`);
      roomBusy.add(`${placed.room}:${placed.slot.id}`);
      sections.push({ crn: nextCRN++, course: cid, sectionNo, faculty: placed.f.id, slot: placed.slot, room: placed.room, sem: sem.id, students: [] });
    }
  }
});

// ---------- registration ----------
const GRADES = ['A','A','A-','A-','B+','B+','B','B','B','B-','C+','C','C','C-','D+','D','F'];
const GRADE_RANK = { A: 11, 'A-': 10, 'B+': 9, B: 8, 'B-': 7, 'C+': 6, C: 5, 'C-': 4, 'D+': 3, D: 2, F: 0 };
const passed = (s, cid, min = 'D') => s.history[cid] !== undefined && GRADE_RANK[s.history[cid]] >= GRADE_RANK[min];

// Registers every attending student for one semester, enforcing the SRS registration rules:
// no grad/undergrad mixing (S-F5/6), prerequisites (S-F4), seats (S-F9), credit limits (S-F7/8),
// not retaking a passed course (S-F10), no time clashes (S-F11).
function register(semId, open) {
  for (const sec of open) sec.students = [];
  // The demo student (student@campus.edu) registers first so the demo account has a full schedule.
  for (const s of [students[0], ...shuffle(students.slice(1))]) {
    if (!s.terms.includes(semId)) continue;
    const target = s.grad ? (s.type === 'Full-time' ? 3 : 2) : (s.type === 'Full-time' ? 4 : 2);
    let credits = 0, count = 0;
    const slotsTaken = new Set(), coursesTaken = new Set();
    const ownDepts = new Set([...s.majors, ...s.minors].map((m) => m.split('-')[0]));
    const own = shuffle(open.filter((c) => ownDepts.has(courses[c.course].dept))).sort((a, b) => courses[b.course].level - courses[a.course].level);
    const electives = shuffle(open.filter((c) => !ownDepts.has(courses[c.course].dept) && courses[c.course].level <= 2));
    for (const sec of [...own, ...electives]) {
      if (count >= target) break;
      const c = courses[sec.course];
      if (c.grad !== s.grad) continue;
      if (sec.students.length >= SECTION_SEATS) continue;
      if (credits + c.credits > MAX_CREDITS[s.type]) continue;
      if (slotsTaken.has(sec.slot.id) || coursesTaken.has(c.id)) continue;
      if (passed(s, c.id)) continue;
      if (!c.prereqs.every((p) => passed(s, p.id, p.min))) continue;
      sec.students.push({ id: s.id, grade: null });
      slotsTaken.add(sec.slot.id); coursesTaken.add(c.id);
      credits += c.credits; count++;
    }
  }
}

const studentById = Object.fromEntries(students.map((s) => [s.id, s]));
const cancelled = new Set();
for (const sem of ['FA24', 'SP25', 'FA25', 'SP26', 'FA26']) {
  let open = sections.filter((k) => k.sem === sem);
  // Register, cancel sections under 5 students (A-R46), and re-register until every section runs.
  for (;;) {
    register(sem, open);
    const small = open.filter((k) => k.students.length < MIN_TO_RUN);
    if (!small.length) break;
    for (const k of small) { cancelled.add(k); k.students = []; }
    open = open.filter((k) => !cancelled.has(k));
  }
  if (PAST.includes(sem)) {
    for (const sec of open) {
      for (const e of sec.students) {
        e.grade = e.id === 100001 ? pick(['A', 'A-', 'B+', 'B']) : pick(GRADES); // the demo student is a good student
        const s = studentById[e.id];
        s.history[sec.course] = e.grade;
        if (e.grade !== 'F') s.credits += courses[sec.course].credits;
      }
    }
  }
}
const running = sections.filter((k) => !cancelled.has(k));

// A part-time instructor whose section was cancelled takes over a section from a
// full-time colleague, so every part-time faculty member teaches exactly one (F-R7).
for (const sem of SEMESTERS) {
  for (const d of DEPTS) {
    const secs = running.filter((k) => k.sem === sem.id && courses[k.course].dept === d.id);
    const loadOf = (fid) => secs.filter((k) => k.faculty === fid).length;
    for (const pt of d.faculty.filter((f) => f.type === 'Part-time')) {
      if (loadOf(pt.id) === 1) continue;
      const take = secs.find((k) => faculty[k.faculty].type === 'Full-time' && loadOf(k.faculty) === 2)
                || secs.find((k) => faculty[k.faculty].type === 'Full-time');
      if (!take) throw new Error(`No section for part-time faculty ${pt.id} in ${sem.id}`);
      take.faculty = pt.id;
    }
  }
}

for (const k of running) {
  insert('Course_Section', ['CRN','course_ID','section_No','faculty_ID','time_Slot_ID','room_ID','semester_ID','max_Seats'],
    [k.crn, k.course, k.sectionNo, k.faculty, k.slot.id, k.room, k.sem, SECTION_SEATS]);
}
for (const k of running) {
  for (const e of k.students) {
    insert('Enrollment', ['student_ID','CRN','grade'], [e.id, k.crn, e.grade]);
    if (PAST.includes(k.sem)) insert('Student_History', ['student_ID','CRN','semester_ID','grade'], [e.id, k.crn, k.sem, e.grade]);
  }
  if (PAST.includes(k.sem)) insert('Faculty_History', ['faculty_ID','CRN','semester_ID'], [k.faculty, k.crn, k.sem]);
}

// ---------- attendance: every Fall 2026 class meeting through Friday, Oct 2 ----------
const DOW = { M: 1, T: 2, W: 3, R: 4, F: 5 };
const fa26 = SEMESTERS.find((s) => s.id === 'FA26');
for (const k of running.filter((x) => x.sem === 'FA26')) {
  for (let day = fa26.start; day <= '2026-10-02'; day = addDays(day, 1)) {
    const dow = new Date(day + 'T00:00:00Z').getUTCDay();
    if (!k.slot.days.some((d) => DOW[d] === dow) || day === '2026-09-07') continue; // Labor Day
    for (const e of k.students) {
      insert('Attendance', ['student_ID','CRN','attendance_Date','attendance_Status'], [e.id, k.crn, day, chance(0.9) ? 'Present' : 'Absent']);
    }
  }
}

// ---------- student records ----------
const entryDate = { FA24: '2024-08-15', FA25: '2025-08-15', FA26: '2026-08-15' };
for (const s of students) {
  insert('Student', ['student_ID','student_Year','student_Type'], [s.id, s.year, s.grad ? 'Graduate' : 'Undergraduate']);
  s.fails = Object.values(s.history).filter((g) => g === 'F').length;
  if (!s.grad) {
    insert('Undergraduate_Student', ['student_ID','dept_ID','undergraduate_Student_Type'], [s.id, s.dept, s.type]);
    insert(s.type === 'Full-time' ? 'Full_Time_Undergraduate' : 'Part_Time_Undergraduate',
      ['student_ID','status','min_Credits','max_Credits','credits_Earned'],
      [s.id, s.fails >= 2 ? 'Academic Probation' : 'Good Standing', s.type === 'Full-time' ? 12 : 1, MAX_CREDITS[s.type], s.credits]);
  } else {
    insert('Graduate_Student', ['student_ID','dept_ID','program','graduate_Student_Type'], [s.id, s.dept, s.program, s.type]);
    const thesis = s.program === 'PhD' ? 'Dissertation' : chance(0.4) ? 'Thesis' : 'Non-Thesis';
    insert(s.type === 'Full-time' ? 'Full_Time_Graduate_Student' : 'Part_Time_Graduate_Student',
      ['student_ID','year','credits_Earned','thesis_Type'], [s.id, s.year === 'First Year' ? 1 : 2, s.credits, thesis]);
  }
  const chosen = entryDate[s.terms[0]];
  s.majors.forEach((m, i) => insert('Student_Major', ['student_ID','major_ID','date_Of_Choice'], [s.id, m, i === 0 ? chosen : '2026-02-01']));
  for (const m of s.minors) insert('Student_Minor', ['student_ID','minor_ID','date_Of_Choice'], [s.id, m, '2026-02-01']);
}

// Advisors: full-time faculty only (F-F10), at most 15 advisees each (F-F9),
// one advisor in the student's department plus a second for a second major or a minor (S-R11, A-F12).
const advisees = new Map();
function assignAdvisor(s, deptId, when) {
  const options = deptById[deptId].faculty.filter((f) => f.type === 'Full-time' && (advisees.get(f.id) || 0) < MAX_ADVISEES);
  const f = options.sort((a, b) => (advisees.get(a.id) || 0) - (advisees.get(b.id) || 0))[0];
  advisees.set(f.id, (advisees.get(f.id) || 0) + 1);
  insert('Advisor', ['faculty_ID','student_ID','date_Of_Appointment'], [f.id, s.id, when]);
}
for (const s of students) {
  assignAdvisor(s, s.dept, entryDate[s.terms[0]]);
  const second = s.majors[1] || s.minors[0];
  if (second) assignAdvisor(s, second.split('-')[0], '2026-02-01');
}

// Holds. Every hold was placed after Fall 2026 registration closed (Sept 2), so no student
// registered for a section while a hold was active (S-F3).
for (const [i, t] of ['Academic', 'Financial', 'Health', 'Disciplinary'].entries()) insert('Hold', ['hold_ID','hold_Type'], [i + 1, t]);
const holdDate = () => addDays('2026-09-03', int(0, 32));
for (const s of students.filter((x) => x.id !== 100001)) {
  if (s.fails >= 2) insert('Student_Hold', ['student_ID','hold_ID','hold_Date'], [s.id, 1, holdDate()]);
  if (chance(0.08)) insert('Student_Hold', ['student_ID','hold_ID','hold_Date'], [s.id, 2, holdDate()]);
  if (chance(0.02)) insert('Student_Hold', ['student_ID','hold_ID','hold_Date'], [s.id, 3, holdDate()]);
  if (chance(0.01)) insert('Student_Hold', ['student_ID','hold_ID','hold_Date'], [s.id, 4, holdDate()]);
}

// ---------- admins, statistics department, audit log ----------
const STAFF = [
  [300001, { first: 'Muiz', middle: 'M.', last: 'Onifade', gender: 'Male', dob: '1990-05-22', street: '75 Post Ave', city: 'Westbury', state: 'NY', zip: '11590' }, 'Admin', 'admin@campus.edu', 'Read-Write'],
  [300002, person('Female', 1975, 1995), 'Admin', null, 'Read-Write'],
  [300003, person('Male', 1975, 1995), 'Admin', null, 'Read-Only'],
  [400001, person('Female', 1970, 1995), 'StatDept', 'stat@campus.edu', 'Aggregate'],
  [400002, person('Male', 1970, 1995), 'StatDept', null, 'Aggregate'],
];
for (const [id, p, type, email, level] of STAFF) {
  addUser(id, p, type, email);
  if (type === 'Admin') insert('Admin', ['admin_ID','security_Level'], [id, level]);
  else insert('Stat_Dept_Member', ['stat_dept_ID','access_Level'], [id, level]);
}
// Updates made by Read-Write admins only (A-R43/A-R44, A-F2). Each address change's
// new_Value is the user's current street address.
const AUDIT_COLS = ['log_ID','admin_ID','user_ID','action_Type','field_Name','old_Value','new_Value','date_Time'];
const audit = [];
const auditedUsers = shuffle(students.map((s) => s.id).filter((id) => id !== 100001)).slice(0, 20)
  .concat(shuffle(Object.keys(faculty).map(Number)).slice(0, 5));
for (const uid of auditedUsers) {
  const when = `${addDays('2025-09-01', int(0, 390))} ${pad(int(9, 16))}:${pad(int(0, 59))}:00`;
  audit.push([0, pick([300001, 300002]), uid, 'Update', 'street', newStreet(), users[uid].street, when]);
}
for (const s of students.filter((x) => x.terms[0] === 'FA26').slice(0, 5)) {
  audit.push([0, 300001, s.id, 'Create', 'user_ID', null, String(s.id), `2026-07-${pad(int(1, 31))} 10:${pad(int(0, 59))}:00`]);
}
audit.sort((a, b) => a[7].localeCompare(b[7])).forEach((r, i) => { r[0] = i + 1; insert('Audit_Log', AUDIT_COLS, r); });

// ---------- write the SQL file in foreign-key order ----------
const ORDER = ['User','Login','Building','Office','Room','Faculty','Admin','Stat_Dept_Member','Department','Faculty_Department',
  'Major','Minor','Course','Course_Prerequisite','Major_Course_Requirement','Minor_Course_Requirement',
  'Student','Undergraduate_Student','Full_Time_Undergraduate','Part_Time_Undergraduate','Graduate_Student',
  'Full_Time_Graduate_Student','Part_Time_Graduate_Student','Student_Major','Student_Minor','Advisor','Hold','Student_Hold',
  'Semester','Day','Period','Time_Slot','Time_Slot_Day','Time_Slot_Period','Course_Section',
  'Enrollment','Attendance','Student_History','Faculty_History','Audit_Log'];
const missing = ORDER.filter((t) => !tables[t]);
if (missing.length) throw new Error(`No rows generated for: ${missing.join(', ')}`);
if (Object.keys(tables).length !== ORDER.length) throw new Error('A generated table is missing from ORDER');

const out = [
  '-- Campus Connect project data (generated by db/generate-data.js; do not edit by hand)',
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

console.log(`Wrote db/Project_data.sql (${cancelled.size} under-enrolled sections were cancelled)\n`);
console.log(ORDER.map((t) => `${t.padEnd(28)} ${tables[t].rows.length}`).join('\n'));
