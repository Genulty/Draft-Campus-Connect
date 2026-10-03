-- #####################################################################
--
--   CAMPUS CONNECT SYSTEM  —  DATABASE SCHEMA
--   CS5910 System Design and Implementation
--   Julio Larrea, Muiz M. Onifade, Pavel Clarke
--
--   This file creates every table the website uses (database: SQLite).
--   Run it on an empty database and you get the full structure, no data.
--   The sample data is loaded afterwards by scripts/load-data.js.
--
-- #####################################################################
--
-- HOW TO READ THIS FILE (quick SQL cheat sheet)
-- ---------------------------------------------------------------------
--   --                 A comment. SQL ignores everything after "--".
--   CREATE TABLE X     Makes a new table named X. Each line inside is a column.
--   INTEGER / TEXT     The column's data type (a whole number / any text).
--                      Dates are stored as TEXT like '2026-09-02', times as '09:30'.
--   PRIMARY KEY        The column(s) that uniquely identify one row, like an ID card.
--                      No two rows can have the same primary key.
--   REFERENCES T(c)    A FOREIGN KEY: the value must already exist in column c of
--                      table T. Example: a Class's faculty_ID must be a real faculty member.
--   ON DELETE CASCADE  If the row being pointed at is deleted, delete this row too.
--   ON DELETE SET NULL If the row being pointed at is deleted, just blank this value.
--   NOT NULL           The column can't be left empty.
--   UNIQUE             No two rows can share this value.
--   DEFAULT x          The value used when none is given.
--   CHECK (...)        A rule every row must follow, e.g. only 'Male','Female','Other'.
--   CREATE INDEX       A lookup shortcut that makes searches faster. Changes no data.
--   CREATE VIEW        A saved query that looks like a table (see Part 11).
--
-- WORDS USED BELOW
-- ---------------------------------------------------------------------
--   Super-entity  A general table that more specific tables build on (User).
--   Sub-entity    A more specific kind of the super-entity (Student is a kind of User).
--                 It shares the same ID, so Student 100001 is also User 100001.
--   Junction table  A table whose only job is to link two other tables when the
--                 relationship is many-to-many (e.g. a student can have many
--                 majors, and a major has many students -> Student_Major).
--   [EXTENSION]   Not in the client's schema list, but needed so the website can
--                 follow the SRS rules / use cases in the System Manual.
--
-- TABLE OF CONTENTS
-- ---------------------------------------------------------------------
--   Part 1   Users and logins ............ User, Login
--   Part 2   Buildings ................... Office, Lab
--   Part 3   Faculty and departments ..... Faculty, Department, Faculty_Department
--   Part 4   Majors and minors ........... Major, Minor
--   Part 5   Students .................... Student + 6 sub-tables, Student_Major,
--                                          Student_Minor, Advisor
--   Part 6   Other user types ............ Admin, Stat_Dept_Member
--   Part 7   Holds ....................... Hold, Student_Hold
--   Part 8   Courses ..................... Course, Course_Prerequisite,
--                                          Major_Requirement, Minor_Requirement
--   Part 9   Time and classes ............ Day, Period, Time_Slot, Time_Slot_Day,
--                                          Time_Slot_Period, Semester, Class
--   Part 10  Registration and history .... Enrollment, Student_History,
--                                          Faculty_History, Attendance
--   Part 11  Website support [EXTENSION]  Change_Request, Audit_Log, Notification,
--                                          Summary_Report, System_Setting
--   Part 12  Indexes (speed only)
--   Part 13  Views (saved queries)
--
-- ORDER MATTERS: a table must be created before any table that points to it
-- with REFERENCES, which is why the parts go in this order.
-- #####################################################################

-- Tell SQLite to actually enforce the REFERENCES (foreign key) rules.
-- (SQLite ignores them unless this is switched on.)
PRAGMA foreign_keys = ON;


-- =====================================================================
-- PART 1 — USERS AND LOGINS
-- Everyone who can sign in (students, faculty, admins, stat-dept members)
-- has exactly one row in User and one row in Login.
-- =====================================================================

-- User: the SUPER-ENTITY. Personal info shared by every kind of user.
CREATE TABLE User (
  user_ID     INTEGER PRIMARY KEY,           -- unique ID, e.g. 100001
  first_Name  TEXT NOT NULL,
  middle_Name TEXT,                          -- optional (may be empty)
  last_Name   TEXT NOT NULL,
  gender      TEXT CHECK (gender IN ('Male','Female','Other')),
  DOB         TEXT,                                   -- date of birth, YYYY-MM-DD
  street      TEXT,                          -- address is split into 4 parts
  city        TEXT,
  state       TEXT,                          -- 2-letter code, e.g. NY
  zip_Code    TEXT,
  user_Type   TEXT NOT NULL CHECK (user_Type IN ('Student','Faculty','Admin','StatDept'))
                                             -- which sub-table has this user's details
);

-- Login: sign-in details for each user (one-to-one with User).
CREATE TABLE Login (
  user_ID       INTEGER PRIMARY KEY REFERENCES User(user_ID) ON DELETE CASCADE,
  user_Email    TEXT    NOT NULL UNIQUE,     -- the email used to sign in
  user_Password TEXT    NOT NULL,                     -- NEVER the real password: a scrambled "salt:hash"
  no_Of_Tries   INTEGER NOT NULL DEFAULT 0,  -- failed sign-in attempts in a row
  lock_Var      INTEGER NOT NULL DEFAULT 0 CHECK (lock_Var IN (0,1)),
                                             -- 1 = locked (after 5 failed tries), 0 = OK
  user_Type     TEXT    NOT NULL                      -- copy of User.user_Type (client schema); set on account creation
);


-- =====================================================================
-- PART 2 — BUILDINGS
-- =====================================================================

-- Office: faculty and department offices.
CREATE TABLE Office (
  office_ID TEXT PRIMARY KEY,                -- e.g. OF-TH-401
  bldg_Name TEXT NOT NULL,                   -- e.g. Tech Hall
  room_No   TEXT NOT NULL
);

-- Lab: classrooms/labs where classes meet. ("Lab / Room" in the manual.)
CREATE TABLE Lab (                                    -- Lab / Room
  lab_ID    TEXT PRIMARY KEY,                -- e.g. TH-101
  bldg_Name TEXT NOT NULL,
  room_No   TEXT NOT NULL,
  capacity  INTEGER NOT NULL CHECK (capacity > 0),   -- number of seats in the room
  UNIQUE (bldg_Name, room_No)                -- no duplicate rooms in one building
);


-- =====================================================================
-- PART 3 — FACULTY AND DEPARTMENTS
-- =====================================================================

-- Faculty: a SUB-ENTITY of User (faculty_ID = the faculty member's user_ID).
CREATE TABLE Faculty (
  faculty_ID    INTEGER PRIMARY KEY REFERENCES User(user_ID) ON DELETE CASCADE,
  office_ID     TEXT REFERENCES Office(office_ID),
  specialty     TEXT,                        -- e.g. Databases
  rank          TEXT,                        -- e.g. Associate Professor
  faculty_Type  TEXT NOT NULL CHECK (faculty_Type IN ('Full-time','Part-time')),
                                             -- SRS: full-time teaches 0-2 sections,
                                             --      part-time teaches exactly 1
  no_of_Classes INTEGER NOT NULL DEFAULT 0            -- sections taught in the current semester
);

-- Department: an academic department (Computer Science, Biology, ...).
CREATE TABLE Department (
  dept_ID      TEXT PRIMARY KEY,             -- short code, e.g. CS
  dept_Name    TEXT NOT NULL UNIQUE,         -- e.g. Computer Science
  chair_ID     INTEGER REFERENCES Faculty(faculty_ID) ON DELETE SET NULL,
                                             -- the faculty member who chairs it
  email        TEXT,
  phone        TEXT,
  office_ID    TEXT REFERENCES Office(office_ID),
  dept_Manager TEXT
);

-- Faculty_Department: JUNCTION TABLE — which faculty belong to which departments.
-- A faculty member can be in several departments (full-time: up to 3,
-- part-time: only 1), and a department has many faculty.
CREATE TABLE Faculty_Department (
  faculty_ID          INTEGER NOT NULL REFERENCES Faculty(faculty_ID) ON DELETE CASCADE,
  dept_ID             TEXT    NOT NULL REFERENCES Department(dept_ID) ON DELETE CASCADE,
  percent_Time        INTEGER NOT NULL DEFAULT 100 CHECK (percent_Time BETWEEN 1 AND 100),
                                             -- share of their time in this department
                                             -- (the website keeps each person's total <= 100)
  date_Of_Appointment TEXT    NOT NULL,      -- date they joined the department
  PRIMARY KEY (faculty_ID, dept_ID)          -- the PAIR must be unique (no duplicates)
);


-- =====================================================================
-- PART 4 — MAJORS AND MINORS
-- =====================================================================

-- Major: a degree program, e.g. B.S. Computer Science.
CREATE TABLE Major (
  major_ID         TEXT PRIMARY KEY,         -- e.g. BS-CS
  dept_ID          TEXT NOT NULL REFERENCES Department(dept_ID),
  major_Name       TEXT NOT NULL UNIQUE,
  major_Level      TEXT NOT NULL CHECK (major_Level IN ('Undergraduate','Graduate')),
  credits_Required INTEGER NOT NULL          -- credits needed to graduate, e.g. 120
);

-- Minor: a smaller program, e.g. Mathematics Minor.
CREATE TABLE Minor (
  minor_ID         TEXT PRIMARY KEY,         -- e.g. MIN-MATH
  dept_ID          TEXT NOT NULL REFERENCES Department(dept_ID),
  minor_Name       TEXT NOT NULL UNIQUE,
  credits_Required INTEGER NOT NULL
);


-- =====================================================================
-- PART 5 — STUDENTS
--
-- Students are stored in THREE LEVELS so each kind of student only has
-- the columns that apply to it:
--
--   User                                   (Part 1: name, address, ...)
--    └── Student                           (every student)
--         ├── Undergraduate_Student        (undergrads only)
--         │     ├── Full_Time_Undergraduate
--         │     └── Part_Time_Undergraduate
--         └── Graduate_Student             (grad students only)
--               ├── Full_Time_Graduate_Student
--               └── Part_Time_Graduate_Student
--
-- Example: full-time undergrad 100001 has one row in User, Student,
-- Undergraduate_Student and Full_Time_Undergraduate — all with ID 100001.
-- The Student_Info view (Part 13) puts these back together into one row.
-- =====================================================================

-- Student: SUB-ENTITY of User. Facts true for every student.
CREATE TABLE Student (
  student_ID   INTEGER PRIMARY KEY REFERENCES User(user_ID) ON DELETE CASCADE,
  major_ID     TEXT REFERENCES Major(major_ID),        -- primary (first) major
  student_Year TEXT NOT NULL,                           -- Freshman / Sophomore / Junior / Senior / Graduate
  student_Type TEXT NOT NULL CHECK (student_Type IN ('Undergraduate','Graduate'))
                                             -- decides which sub-table below they're in
);

-- Undergraduate_Student: SUB-ENTITY of Student.
CREATE TABLE Undergraduate_Student (
  student_ID                 INTEGER PRIMARY KEY REFERENCES Student(student_ID) ON DELETE CASCADE,
  dept_ID                    TEXT REFERENCES Department(dept_ID),   -- department of their major
  undergraduate_Student_Type TEXT NOT NULL CHECK (undergraduate_Student_Type IN ('Full-time','Part-time'))
);

-- Full_Time_Undergraduate: SUB-SUB-ENTITY. Only full-time undergrads.
CREATE TABLE Full_Time_Undergraduate (
  student_ID     INTEGER PRIMARY KEY REFERENCES Undergraduate_Student(student_ID) ON DELETE CASCADE,
  status         TEXT    NOT NULL DEFAULT 'Good Standing',  -- academic standing (e.g. Academic Probation)
  min_Credits    INTEGER NOT NULL DEFAULT 12,               -- per semester
  max_Credits    INTEGER NOT NULL DEFAULT 16,               -- SRS: full-time max is 16
  credits_Earned INTEGER NOT NULL DEFAULT 0                 -- total credits passed so far
);

-- Part_Time_Undergraduate: SUB-SUB-ENTITY. Only part-time undergrads.
CREATE TABLE Part_Time_Undergraduate (
  student_ID     INTEGER PRIMARY KEY REFERENCES Undergraduate_Student(student_ID) ON DELETE CASCADE,
  status         TEXT    NOT NULL DEFAULT 'Good Standing',
  min_Credits    INTEGER NOT NULL DEFAULT 1,
  max_Credits    INTEGER NOT NULL DEFAULT 8,                -- SRS: part-time max is 8
  credits_Earned INTEGER NOT NULL DEFAULT 0
);

-- Graduate_Student: SUB-ENTITY of Student.
CREATE TABLE Graduate_Student (
  student_ID            INTEGER PRIMARY KEY REFERENCES Student(student_ID) ON DELETE CASCADE,
  dept_ID               TEXT REFERENCES Department(dept_ID),
  program               TEXT NOT NULL CHECK (program IN ('MA/MS','PhD')),
  graduate_Student_Type TEXT NOT NULL CHECK (graduate_Student_Type IN ('Full-time','Part-time'))
);

-- Full_Time_Graduate_Student: SUB-SUB-ENTITY. Only full-time grad students.
CREATE TABLE Full_Time_Graduate_Student (
  student_ID     INTEGER PRIMARY KEY REFERENCES Graduate_Student(student_ID) ON DELETE CASCADE,
  year           INTEGER NOT NULL DEFAULT 1,               -- year in the program (1, 2, ...)
  credits_Earned INTEGER NOT NULL DEFAULT 0,
  thesis         TEXT                                      -- thesis title (MA/MS thesis track or PhD); NULL = none yet
);

-- Part_Time_Graduate_Student: SUB-SUB-ENTITY. Only part-time grad students.
CREATE TABLE Part_Time_Graduate_Student (
  student_ID     INTEGER PRIMARY KEY REFERENCES Graduate_Student(student_ID) ON DELETE CASCADE,
  year           INTEGER NOT NULL DEFAULT 1,
  credits_Earned INTEGER NOT NULL DEFAULT 0,
  thesis         TEXT
);


-- =====================================================================
-- PART 6 — OTHER USER TYPES (both are SUB-ENTITIES of User)
-- =====================================================================

-- Admin: university administrators.
CREATE TABLE Admin (
  admin_ID       INTEGER PRIMARY KEY REFERENCES User(user_ID) ON DELETE CASCADE,
  security_Level TEXT NOT NULL CHECK (security_Level IN ('Read-only','Read-write'))
                                             -- SRS: read-only admins can't change records
);

-- Stat_Dept_Member: Statistics Department staff (always full-time per the SRS).
CREATE TABLE Stat_Dept_Member (
  stat_dept_ID INTEGER PRIMARY KEY REFERENCES User(user_ID) ON DELETE CASCADE,
  access_Level TEXT NOT NULL DEFAULT 'Aggregate'   -- they only see anonymous totals
);


-- ---------------------------------------------------------------------
-- Student relationships (these point to Student, so they come after it)
-- ---------------------------------------------------------------------

-- Student_Major: JUNCTION TABLE — which majors each student has declared.
-- A student may have up to 2 majors (website rule).
CREATE TABLE Student_Major (
  student_ID     INTEGER NOT NULL REFERENCES Student(student_ID) ON DELETE CASCADE,
  major_ID       TEXT    NOT NULL REFERENCES Major(major_ID),
  date_Of_Choice TEXT    NOT NULL,           -- date the major was declared
  PRIMARY KEY (student_ID, major_ID)
);

-- Student_Minor: JUNCTION TABLE — which minors each student has declared.
CREATE TABLE Student_Minor (
  student_ID     INTEGER NOT NULL REFERENCES Student(student_ID) ON DELETE CASCADE,
  minor_ID       TEXT    NOT NULL REFERENCES Minor(minor_ID),
  date_Of_Choice TEXT    NOT NULL,
  PRIMARY KEY (student_ID, minor_ID)
);

-- Advisor: JUNCTION TABLE — which faculty member advises which student.
-- SRS: a student has at most 2 advisors; a full-time faculty member at most
-- 15 advisees; part-time faculty never advise. (The website enforces these.)
CREATE TABLE Advisor (
  faculty_ID    INTEGER NOT NULL REFERENCES Faculty(faculty_ID) ON DELETE CASCADE,
  student_ID    INTEGER NOT NULL REFERENCES Student(student_ID) ON DELETE CASCADE,
  date_Of_Appnt TEXT    NOT NULL,            -- date the advisor was assigned
  PRIMARY KEY (faculty_ID, student_ID)
);


-- =====================================================================
-- PART 7 — HOLDS
-- A hold blocks a student from registering (SRS).
-- =====================================================================

-- Hold: the 4 kinds of holds.
CREATE TABLE Hold (
  hold_ID   INTEGER PRIMARY KEY,
  hold_Type TEXT NOT NULL UNIQUE CHECK (hold_Type IN ('Academic','Financial','Health','Disciplinary'))
);

-- Student_Hold: JUNCTION TABLE — which students currently have which holds.
CREATE TABLE Student_Hold (
  student_ID INTEGER NOT NULL REFERENCES Student(student_ID) ON DELETE CASCADE,
  hold_ID    INTEGER NOT NULL REFERENCES Hold(hold_ID),
  hold_Date  TEXT    NOT NULL,               -- date the hold was placed
  PRIMARY KEY (student_ID, hold_ID)
);


-- =====================================================================
-- PART 8 — COURSES
-- A Course is the subject in the catalog (CS320 Database Systems).
-- A Class (Part 9) is one scheduled offering of it in a semester.
-- =====================================================================

-- Course: every course in the catalog.
CREATE TABLE Course (
  course_ID      TEXT PRIMARY KEY,           -- e.g. CS320
  course_Name    TEXT NOT NULL,              -- e.g. Database Systems
  dept_ID        TEXT NOT NULL REFERENCES Department(dept_ID),
  course_Credits INTEGER NOT NULL CHECK (course_Credits BETWEEN 1 AND 6),
  course_Desc    TEXT,                       -- short description
  course_Type    TEXT NOT NULL CHECK (course_Type IN ('Undergraduate','Graduate'))
                                             -- SRS: students can only take their own level
);

-- Course_Prerequisite: which courses must be passed before taking another.
-- Both columns point to Course, so a course "links to itself".
-- Example row: ('CS320', 'CS102', 'C') = "to take CS320 you need CS102 with a C or better".
CREATE TABLE Course_Prerequisite (
  course_ID              TEXT NOT NULL REFERENCES Course(course_ID) ON DELETE CASCADE,
  prerequisite_course_ID TEXT NOT NULL REFERENCES Course(course_ID) ON DELETE CASCADE,
  min_Grade_Req          TEXT NOT NULL DEFAULT 'D',   -- lowest grade that counts
  PRIMARY KEY (course_ID, prerequisite_course_ID),
  CHECK (course_ID <> prerequisite_course_ID)         -- a course can't require itself
);

-- Major_Requirement: [EXTENSION] courses required for each major (used by the degree audit).
CREATE TABLE Major_Requirement (
  major_ID  TEXT NOT NULL REFERENCES Major(major_ID) ON DELETE CASCADE,
  course_ID TEXT NOT NULL REFERENCES Course(course_ID) ON DELETE CASCADE,
  PRIMARY KEY (major_ID, course_ID)
);

-- Minor_Requirement: [EXTENSION] courses required for each minor.
CREATE TABLE Minor_Requirement (
  minor_ID  TEXT NOT NULL REFERENCES Minor(minor_ID) ON DELETE CASCADE,
  course_ID TEXT NOT NULL REFERENCES Course(course_ID) ON DELETE CASCADE,
  PRIMARY KEY (minor_ID, course_ID)
);


-- =====================================================================
-- PART 9 — TIME AND CLASSES
--
-- A time slot = which DAYS + which PERIOD (start/end time). Example:
--   Time_Slot 2  ->  days M and W  +  period 09:30–10:45   ("MW 9:30am")
-- Days and periods are stored once, and linked by two junction tables.
-- =====================================================================

-- Day: the days classes can meet.
CREATE TABLE Day (
  day_ID   TEXT PRIMARY KEY,                           -- M, T, W, R (Thursday), F
  week_Day TEXT NOT NULL UNIQUE                        -- Monday, Tuesday, ...
);

-- Period: a start and end time.
CREATE TABLE Period (
  period_ID  INTEGER PRIMARY KEY,
  start_Time TEXT NOT NULL,                            -- HH:MM, 24-hour clock (e.g. 13:00 = 1pm)
  end_Time   TEXT NOT NULL,
  CHECK (start_Time < end_Time)              -- must end after it starts
);

-- Time_Slot: just an ID; its days and period are in the two tables below.
CREATE TABLE Time_Slot (
  time_Slot_ID INTEGER PRIMARY KEY
);

-- Time_Slot_Day: JUNCTION TABLE — the days a time slot meets (a slot can have several).
CREATE TABLE Time_Slot_Day (
  time_Slot_ID INTEGER NOT NULL REFERENCES Time_Slot(time_Slot_ID) ON DELETE CASCADE,
  day_ID       TEXT    NOT NULL REFERENCES Day(day_ID),
  PRIMARY KEY (time_Slot_ID, day_ID)
);

-- Time_Slot_Period: JUNCTION TABLE — the period (times) of a time slot.
CREATE TABLE Time_Slot_Period (
  time_Slot_ID INTEGER NOT NULL REFERENCES Time_Slot(time_Slot_ID) ON DELETE CASCADE,
  period_ID    INTEGER NOT NULL REFERENCES Period(period_ID),
  PRIMARY KEY (time_Slot_ID, period_ID),
  UNIQUE (time_Slot_ID)                                -- each slot meets during exactly one period
);

-- Semester: each term, plus the date windows the SRS rules depend on.
CREATE TABLE Semester (
  semester_ID   TEXT PRIMARY KEY,                      -- e.g. FA26
  semester_Name TEXT NOT NULL,               -- e.g. Fall 2026
  start_Date    TEXT NOT NULL,               -- first day of classes
  end_Date      TEXT NOT NULL,               -- last day of classes
  -- [EXTENSION] designated time windows from the SRS
  add_Start     TEXT NOT NULL,               -- students can add classes from ...
  add_End       TEXT NOT NULL,               -- ... until this date
  drop_End      TEXT NOT NULL,               -- last day to drop a class
  grade_Start   TEXT NOT NULL,               -- faculty can submit grades from ...
  grade_End     TEXT NOT NULL                -- ... until this date
);

-- Class: one scheduled section of a course in a semester ("Course Section").
-- Example: CRN 10145 = CS320 section 001, Fall 2026, MW 9:30, Tech Hall 101, Dr. Chen.
CREATE TABLE Class (                                   -- Course Section
  CRN             INTEGER PRIMARY KEY,       -- Course Reference Number (unique class ID)
  course_ID       TEXT    NOT NULL REFERENCES Course(course_ID),
  section_No      TEXT    NOT NULL,          -- e.g. 001
  faculty_ID      INTEGER REFERENCES Faculty(faculty_ID),   -- empty = instructor TBA
  time_Slot_ID    INTEGER NOT NULL REFERENCES Time_Slot(time_Slot_ID),
  lab_ID          TEXT    NOT NULL REFERENCES Lab(lab_ID),  -- the room
  semester_ID     TEXT    NOT NULL REFERENCES Semester(semester_ID),
  capacity        INTEGER NOT NULL DEFAULT 10 CHECK (capacity BETWEEN 1 AND 10),   -- [EXTENSION] max 10 per SRS
  available_Seats INTEGER NOT NULL CHECK (available_Seats >= 0),   -- seats still open
  status          TEXT    NOT NULL DEFAULT 'Open' CHECK (status IN ('Open','Cancelled')),  -- [EXTENSION] cancelled if < 5 students
  UNIQUE (course_ID, section_No, semester_ID)          -- no duplicate section numbers
);


-- =====================================================================
-- PART 10 — REGISTRATION AND HISTORY
--
--   Enrollment       = classes a student is taking NOW or NEXT semester.
--   Student_History  = classes a student finished in PAST semesters (with a grade).
--   At the end of a term the website moves graded Enrollment rows into
--   Student_History ("Archive" on the Rooms & Semesters admin page).
--   Faculty_History does the same for the classes each faculty member taught.
-- =====================================================================

-- Enrollment: JUNCTION TABLE between Student and Class (current/next semester).
CREATE TABLE Enrollment (
  student_ID  INTEGER NOT NULL REFERENCES Student(student_ID) ON DELETE CASCADE,
  CRN         INTEGER NOT NULL REFERENCES Class(CRN) ON DELETE CASCADE,
  semester_ID TEXT    NOT NULL REFERENCES Semester(semester_ID),
  grade       TEXT,                                    -- empty (NULL) = class still in progress
  PRIMARY KEY (student_ID, CRN)              -- a student can't register for the same class twice
);

-- Student_History: completed classes from past semesters.
CREATE TABLE Student_History (
  student_ID  INTEGER NOT NULL REFERENCES Student(student_ID) ON DELETE CASCADE,
  CRN         INTEGER NOT NULL REFERENCES Class(CRN),
  course_ID   TEXT    NOT NULL REFERENCES Course(course_ID),   -- copied from the Class for easy lookup
  semester_ID TEXT    NOT NULL REFERENCES Semester(semester_ID),
  grade       TEXT    NOT NULL,              -- final grade: A, A-, B+, ... F, or W (withdrew)
  PRIMARY KEY (student_ID, CRN)
);

-- Faculty_History: classes each faculty member taught in past semesters.
CREATE TABLE Faculty_History (
  faculty_ID  INTEGER NOT NULL REFERENCES Faculty(faculty_ID) ON DELETE CASCADE,
  CRN         INTEGER NOT NULL REFERENCES Class(CRN),
  course_ID   TEXT    NOT NULL REFERENCES Course(course_ID),
  semester_ID TEXT    NOT NULL REFERENCES Semester(semester_ID),
  PRIMARY KEY (faculty_ID, CRN)
);

-- Attendance: one row per student, per class, per class meeting (date).
CREATE TABLE Attendance (
  CRN               INTEGER NOT NULL REFERENCES Class(CRN) ON DELETE CASCADE,
  student_ID        INTEGER NOT NULL REFERENCES Student(student_ID) ON DELETE CASCADE,
  course_ID         TEXT    NOT NULL REFERENCES Course(course_ID),
  attendance_Date   TEXT    NOT NULL,        -- the day the class met
  attendance_Status TEXT    NOT NULL CHECK (attendance_Status IN ('Present','Absent')),
  PRIMARY KEY (student_ID, CRN, attendance_Date)       -- date added: one record per class meeting
);


-- =====================================================================
-- PART 11 — WEBSITE SUPPORT TABLES [EXTENSION]
-- Not part of the client's data model; the website needs them to follow
-- specific SRS rules.
-- =====================================================================

-- Change_Request: SRS says an admin "cannot change any user's information
-- without a request from that user". Users submit requests here; an admin
-- applies or rejects them.
CREATE TABLE Change_Request (                          -- "no change without a request from that user"
  request_ID      INTEGER PRIMARY KEY,
  user_ID         INTEGER NOT NULL REFERENCES User(user_ID) ON DELETE CASCADE,  -- who asked
  field           TEXT    NOT NULL,          -- what to change, e.g. first_Name
  requested_Value TEXT    NOT NULL,          -- the new value they want
  reason          TEXT,
  status          TEXT    NOT NULL DEFAULT 'Pending' CHECK (status IN ('Pending','Completed','Rejected')),
  created_At      TEXT    NOT NULL,          -- when it was submitted
  resolved_At     TEXT,                      -- when an admin handled it
  resolved_By     INTEGER REFERENCES Admin(admin_ID)   -- which admin handled it
);

-- Audit_Log: SRS says admin "must keep a log of all updates made to user
-- information". Every admin change adds a row here.
CREATE TABLE Audit_Log (                               -- "keep a log of all updates made to user information"
  log_ID         INTEGER PRIMARY KEY,
  admin_ID       INTEGER REFERENCES Admin(admin_ID),   -- who made the change
  target_user_ID INTEGER,                    -- whose record changed (if any)
  action         TEXT NOT NULL,              -- e.g. 'Updated user info'
  details        TEXT,                       -- e.g. 'first_Name: "Alex" → "Alexander"'
  request_ID     INTEGER,                    -- the Change_Request it came from (if any)
  logged_At      TEXT NOT NULL
);

-- Notification: a pretend email inbox (e.g. the high-importance email sent
-- when a class is cancelled for having fewer than 5 students).
CREATE TABLE Notification (                            -- simulated email inbox
  notification_ID INTEGER PRIMARY KEY,
  user_ID         INTEGER NOT NULL REFERENCES User(user_ID) ON DELETE CASCADE,  -- recipient
  subject         TEXT    NOT NULL,
  body            TEXT    NOT NULL,
  importance      TEXT    NOT NULL DEFAULT 'Normal' CHECK (importance IN ('Normal','High')),
  created_At      TEXT    NOT NULL,
  is_Read         INTEGER NOT NULL DEFAULT 0 -- 1 = opened, 0 = unread
);

-- Summary_Report: SRS says stat-dept members see "summary data as provided
-- by admin". Admins publish these; stat-dept members read them.
CREATE TABLE Summary_Report (                          -- "summary data as provided by admin"
  report_ID  INTEGER PRIMARY KEY,
  title      TEXT NOT NULL,
  body       TEXT NOT NULL,
  created_By INTEGER REFERENCES Admin(admin_ID),
  created_At TEXT NOT NULL
);

-- System_Setting: simple name/value settings for the website, e.g.
--   current_semester = FA26, next_semester = SP27,
--   clock_override   = 2026-09-02T09:45  (the "pretend" date used for demos)
CREATE TABLE System_Setting (
  key   TEXT PRIMARY KEY,
  value TEXT
);


-- =====================================================================
-- PART 12 — INDEXES
-- An index is like the index at the back of a book: it lets the database
-- find rows faster. Indexes don't change any data and can be ignored when
-- studying the design.
-- =====================================================================
CREATE INDEX idx_class_semester     ON Class(semester_ID);
CREATE INDEX idx_class_faculty      ON Class(faculty_ID);
CREATE INDEX idx_enrollment_crn     ON Enrollment(CRN);
CREATE INDEX idx_history_crn        ON Student_History(CRN);
CREATE INDEX idx_course_dept        ON Course(dept_ID);


-- =====================================================================
-- PART 13 — VIEWS (saved queries)
--
-- A view stores NO data of its own. It's a saved SELECT query that you can
-- use like a table: SELECT * FROM Person;  Views here just combine tables
-- that were split apart above, so the website's code stays simple.
-- =====================================================================

-- Person: User with a ready-made full name and one-line address.
--   user_Name = 'Alex Rivera'
--   address   = '520 Lake View Rd, Hicksville, NY 11801'
-- ( || joins text together; COALESCE(a, b) means "a, or b if a is empty".)
CREATE VIEW Person AS
SELECT user_ID,
       first_Name || ' ' || COALESCE(middle_Name || ' ', '') || last_Name AS user_Name,
       first_Name, middle_Name, last_Name,
       street || ', ' || city || ', ' || state || ' ' || zip_Code AS address,
       street, city, state, zip_Code, gender, DOB, user_Type
FROM User;

-- Student_Info: the 3-level student hierarchy (Part 5) glued back into ONE
-- row per student. LEFT JOIN keeps the student even when a sub-table has no
-- row for them (e.g. an undergrad has no Graduate_Student row), and
-- COALESCE picks whichever sub-table actually has the value.
CREATE VIEW Student_Info AS
SELECT s.student_ID, s.major_ID, s.student_Year,
       s.student_Type AS student_Level,
       COALESCE(u.dept_ID, g.dept_ID) AS dept_ID,
       COALESCE(u.undergraduate_Student_Type, g.graduate_Student_Type) AS enrollment_Status,
       COALESCE(ftu.credits_Earned, ptu.credits_Earned, ftg.credits_Earned, ptg.credits_Earned, 0) AS credits_Earned,
       COALESCE(ftu.max_Credits, ptu.max_Credits,
                CASE g.graduate_Student_Type WHEN 'Full-time' THEN 16 ELSE 8 END) AS max_Credits,
       COALESCE(ftu.min_Credits, ptu.min_Credits) AS min_Credits,
       COALESCE(ftu.status, ptu.status) AS academic_Status,
       g.program,
       COALESCE(ftg.year, ptg.year) AS program_Year,
       COALESCE(ftg.thesis, ptg.thesis) AS thesis
FROM Student s
LEFT JOIN Undergraduate_Student u        ON u.student_ID = s.student_ID
LEFT JOIN Full_Time_Undergraduate ftu    ON ftu.student_ID = s.student_ID
LEFT JOIN Part_Time_Undergraduate ptu    ON ptu.student_ID = s.student_ID
LEFT JOIN Graduate_Student g             ON g.student_ID = s.student_ID
LEFT JOIN Full_Time_Graduate_Student ftg ON ftg.student_ID = s.student_ID
LEFT JOIN Part_Time_Graduate_Student ptg ON ptg.student_ID = s.student_ID;

-- Time_Slot_Info: each time slot as one readable row, e.g.
--   time_Slot_ID 2 | day_Of_Week 'MW' | start_Time '09:30' | end_Time '10:45'
-- (The inner query lists the slot's days in Mon→Fri order and joins them into 'MW'.)
CREATE VIEW Time_Slot_Info AS
SELECT ts.time_Slot_ID,
       (SELECT GROUP_CONCAT(day_ID, '') FROM (
          SELECT tsd.day_ID FROM Time_Slot_Day tsd WHERE tsd.time_Slot_ID = ts.time_Slot_ID
          ORDER BY CASE tsd.day_ID WHEN 'M' THEN 1 WHEN 'T' THEN 2 WHEN 'W' THEN 3 WHEN 'R' THEN 4 WHEN 'F' THEN 5 WHEN 'S' THEN 6 ELSE 7 END)) AS day_Of_Week,
       p.period_ID, p.start_Time, p.end_Time
FROM Time_Slot ts
JOIN Time_Slot_Period tsp ON tsp.time_Slot_ID = ts.time_Slot_ID
JOIN Period p             ON p.period_ID = tsp.period_ID;

-- All_Enrollment: every class a student has EVER taken or is taking —
-- past classes (Student_History) stacked on top of current ones (Enrollment).
-- UNION ALL = put the results of two SELECTs into one list.
-- Used for transcripts, GPA and prerequisite checks.
CREATE VIEW All_Enrollment AS
SELECT student_ID, CRN, semester_ID, grade FROM Student_History
UNION ALL
SELECT student_ID, CRN, semester_ID, grade FROM Enrollment;
