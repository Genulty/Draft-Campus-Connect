PRAGMA foreign_keys = ON;

CREATE TABLE User (
  user_ID     INTEGER PRIMARY KEY,
  first_Name  TEXT NOT NULL,
  middle_Name TEXT,
  last_Name   TEXT NOT NULL,
  gender      TEXT CHECK (gender IN ('Male','Female','Other')),
  DOB         TEXT,
  street      TEXT,
  city        TEXT,
  state       TEXT,
  zip_Code    TEXT,
  user_Type   TEXT NOT NULL CHECK (user_Type IN ('Student','Faculty','Admin','StatDept'))
);

CREATE TABLE Login (
  user_ID       INTEGER PRIMARY KEY REFERENCES User(user_ID) ON DELETE CASCADE,
  user_Email    TEXT    NOT NULL UNIQUE,
  user_Password TEXT    NOT NULL,
  no_Of_Tries   INTEGER NOT NULL DEFAULT 0,
  lock_Var      INTEGER NOT NULL DEFAULT 0 CHECK (lock_Var IN (0,1)),
  user_Type     TEXT    NOT NULL
);

CREATE TABLE Office (
  office_ID TEXT PRIMARY KEY,
  bldg_Name TEXT NOT NULL,
  room_No   TEXT NOT NULL
);

CREATE TABLE Lab (
  lab_ID    TEXT PRIMARY KEY,
  bldg_Name TEXT NOT NULL,
  room_No   TEXT NOT NULL,
  capacity  INTEGER NOT NULL CHECK (capacity > 0),
  UNIQUE (bldg_Name, room_No)
);

CREATE TABLE Faculty (
  faculty_ID    INTEGER PRIMARY KEY REFERENCES User(user_ID) ON DELETE CASCADE,
  office_ID     TEXT REFERENCES Office(office_ID),
  specialty     TEXT,
  rank          TEXT,
  faculty_Type  TEXT NOT NULL CHECK (faculty_Type IN ('Full-time','Part-time')),
  no_of_Classes INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE Department (
  dept_ID      TEXT PRIMARY KEY,
  dept_Name    TEXT NOT NULL UNIQUE,
  chair_ID     INTEGER REFERENCES Faculty(faculty_ID) ON DELETE SET NULL,
  email        TEXT,
  phone        TEXT,
  office_ID    TEXT REFERENCES Office(office_ID),
  dept_Manager TEXT
);

CREATE TABLE Faculty_Department (
  faculty_ID          INTEGER NOT NULL REFERENCES Faculty(faculty_ID) ON DELETE CASCADE,
  dept_ID             TEXT    NOT NULL REFERENCES Department(dept_ID) ON DELETE CASCADE,
  percent_Time        INTEGER NOT NULL DEFAULT 100 CHECK (percent_Time BETWEEN 1 AND 100),
  date_Of_Appointment TEXT    NOT NULL,
  PRIMARY KEY (faculty_ID, dept_ID)
);

CREATE TABLE Major (
  major_ID         TEXT PRIMARY KEY,
  dept_ID          TEXT NOT NULL REFERENCES Department(dept_ID),
  major_Name       TEXT NOT NULL UNIQUE,
  major_Level      TEXT NOT NULL CHECK (major_Level IN ('Undergraduate','Graduate')),
  credits_Required INTEGER NOT NULL
);

CREATE TABLE Minor (
  minor_ID         TEXT PRIMARY KEY,
  dept_ID          TEXT NOT NULL REFERENCES Department(dept_ID),
  minor_Name       TEXT NOT NULL UNIQUE,
  credits_Required INTEGER NOT NULL
);

CREATE TABLE Student (
  student_ID   INTEGER PRIMARY KEY REFERENCES User(user_ID) ON DELETE CASCADE,
  major_ID     TEXT REFERENCES Major(major_ID),
  student_Year TEXT NOT NULL,
  student_Type TEXT NOT NULL CHECK (student_Type IN ('Undergraduate','Graduate'))
);

CREATE TABLE Undergraduate_Student (
  student_ID                 INTEGER PRIMARY KEY REFERENCES Student(student_ID) ON DELETE CASCADE,
  dept_ID                    TEXT REFERENCES Department(dept_ID),
  undergraduate_Student_Type TEXT NOT NULL CHECK (undergraduate_Student_Type IN ('Full-time','Part-time'))
);

CREATE TABLE Full_Time_Undergraduate (
  student_ID     INTEGER PRIMARY KEY REFERENCES Undergraduate_Student(student_ID) ON DELETE CASCADE,
  status         TEXT    NOT NULL DEFAULT 'Good Standing',
  min_Credits    INTEGER NOT NULL DEFAULT 12,
  max_Credits    INTEGER NOT NULL DEFAULT 16,
  credits_Earned INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE Part_Time_Undergraduate (
  student_ID     INTEGER PRIMARY KEY REFERENCES Undergraduate_Student(student_ID) ON DELETE CASCADE,
  status         TEXT    NOT NULL DEFAULT 'Good Standing',
  min_Credits    INTEGER NOT NULL DEFAULT 1,
  max_Credits    INTEGER NOT NULL DEFAULT 8,
  credits_Earned INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE Graduate_Student (
  student_ID            INTEGER PRIMARY KEY REFERENCES Student(student_ID) ON DELETE CASCADE,
  dept_ID               TEXT REFERENCES Department(dept_ID),
  program               TEXT NOT NULL CHECK (program IN ('MA/MS','PhD')),
  graduate_Student_Type TEXT NOT NULL CHECK (graduate_Student_Type IN ('Full-time','Part-time'))
);

CREATE TABLE Full_Time_Graduate_Student (
  student_ID     INTEGER PRIMARY KEY REFERENCES Graduate_Student(student_ID) ON DELETE CASCADE,
  year           INTEGER NOT NULL DEFAULT 1,
  credits_Earned INTEGER NOT NULL DEFAULT 0,
  thesis         TEXT
);

CREATE TABLE Part_Time_Graduate_Student (
  student_ID     INTEGER PRIMARY KEY REFERENCES Graduate_Student(student_ID) ON DELETE CASCADE,
  year           INTEGER NOT NULL DEFAULT 1,
  credits_Earned INTEGER NOT NULL DEFAULT 0,
  thesis         TEXT
);

CREATE TABLE Admin (
  admin_ID       INTEGER PRIMARY KEY REFERENCES User(user_ID) ON DELETE CASCADE,
  security_Level TEXT NOT NULL CHECK (security_Level IN ('Read-only','Read-write'))
);

CREATE TABLE Stat_Dept_Member (
  stat_dept_ID INTEGER PRIMARY KEY REFERENCES User(user_ID) ON DELETE CASCADE,
  access_Level TEXT NOT NULL DEFAULT 'Aggregate'
);

CREATE TABLE Student_Major (
  student_ID     INTEGER NOT NULL REFERENCES Student(student_ID) ON DELETE CASCADE,
  major_ID       TEXT    NOT NULL REFERENCES Major(major_ID),
  date_Of_Choice TEXT    NOT NULL,
  PRIMARY KEY (student_ID, major_ID)
);

CREATE TABLE Student_Minor (
  student_ID     INTEGER NOT NULL REFERENCES Student(student_ID) ON DELETE CASCADE,
  minor_ID       TEXT    NOT NULL REFERENCES Minor(minor_ID),
  date_Of_Choice TEXT    NOT NULL,
  PRIMARY KEY (student_ID, minor_ID)
);

CREATE TABLE Advisor (
  faculty_ID    INTEGER NOT NULL REFERENCES Faculty(faculty_ID) ON DELETE CASCADE,
  student_ID    INTEGER NOT NULL REFERENCES Student(student_ID) ON DELETE CASCADE,
  date_Of_Appnt TEXT    NOT NULL,
  PRIMARY KEY (faculty_ID, student_ID)
);

CREATE TABLE Hold (
  hold_ID   INTEGER PRIMARY KEY,
  hold_Type TEXT NOT NULL UNIQUE CHECK (hold_Type IN ('Academic','Financial','Health','Disciplinary'))
);

CREATE TABLE Student_Hold (
  student_ID INTEGER NOT NULL REFERENCES Student(student_ID) ON DELETE CASCADE,
  hold_ID    INTEGER NOT NULL REFERENCES Hold(hold_ID),
  hold_Date  TEXT    NOT NULL,
  PRIMARY KEY (student_ID, hold_ID)
);

CREATE TABLE Course (
  course_ID      TEXT PRIMARY KEY,
  course_Name    TEXT NOT NULL,
  dept_ID        TEXT NOT NULL REFERENCES Department(dept_ID),
  course_Credits INTEGER NOT NULL CHECK (course_Credits BETWEEN 1 AND 6),
  course_Desc    TEXT,
  course_Type    TEXT NOT NULL CHECK (course_Type IN ('Undergraduate','Graduate'))
);

CREATE TABLE Course_Prerequisite (
  course_ID              TEXT NOT NULL REFERENCES Course(course_ID) ON DELETE CASCADE,
  prerequisite_course_ID TEXT NOT NULL REFERENCES Course(course_ID) ON DELETE CASCADE,
  min_Grade_Req          TEXT NOT NULL DEFAULT 'D',
  PRIMARY KEY (course_ID, prerequisite_course_ID),
  CHECK (course_ID <> prerequisite_course_ID)
);

CREATE TABLE Major_Requirement (
  major_ID  TEXT NOT NULL REFERENCES Major(major_ID) ON DELETE CASCADE,
  course_ID TEXT NOT NULL REFERENCES Course(course_ID) ON DELETE CASCADE,
  PRIMARY KEY (major_ID, course_ID)
);

CREATE TABLE Minor_Requirement (
  minor_ID  TEXT NOT NULL REFERENCES Minor(minor_ID) ON DELETE CASCADE,
  course_ID TEXT NOT NULL REFERENCES Course(course_ID) ON DELETE CASCADE,
  PRIMARY KEY (minor_ID, course_ID)
);

CREATE TABLE Day (
  day_ID   TEXT PRIMARY KEY,
  week_Day TEXT NOT NULL UNIQUE
);

CREATE TABLE Period (
  period_ID  INTEGER PRIMARY KEY,
  start_Time TEXT NOT NULL,
  end_Time   TEXT NOT NULL,
  CHECK (start_Time < end_Time)
);

CREATE TABLE Time_Slot (
  time_Slot_ID INTEGER PRIMARY KEY
);

CREATE TABLE Time_Slot_Day (
  time_Slot_ID INTEGER NOT NULL REFERENCES Time_Slot(time_Slot_ID) ON DELETE CASCADE,
  day_ID       TEXT    NOT NULL REFERENCES Day(day_ID),
  PRIMARY KEY (time_Slot_ID, day_ID)
);

CREATE TABLE Time_Slot_Period (
  time_Slot_ID INTEGER NOT NULL REFERENCES Time_Slot(time_Slot_ID) ON DELETE CASCADE,
  period_ID    INTEGER NOT NULL REFERENCES Period(period_ID),
  PRIMARY KEY (time_Slot_ID, period_ID),
  UNIQUE (time_Slot_ID)
);

CREATE TABLE Semester (
  semester_ID   TEXT PRIMARY KEY,
  semester_Name TEXT NOT NULL,
  start_Date    TEXT NOT NULL,
  end_Date      TEXT NOT NULL,
  add_Start     TEXT NOT NULL,
  add_End       TEXT NOT NULL,
  drop_End      TEXT NOT NULL,
  grade_Start   TEXT NOT NULL,
  grade_End     TEXT NOT NULL
);

CREATE TABLE Class (
  CRN             INTEGER PRIMARY KEY,
  course_ID       TEXT    NOT NULL REFERENCES Course(course_ID),
  section_No      TEXT    NOT NULL,
  faculty_ID      INTEGER REFERENCES Faculty(faculty_ID),
  time_Slot_ID    INTEGER NOT NULL REFERENCES Time_Slot(time_Slot_ID),
  lab_ID          TEXT    NOT NULL REFERENCES Lab(lab_ID),
  semester_ID     TEXT    NOT NULL REFERENCES Semester(semester_ID),
  capacity        INTEGER NOT NULL DEFAULT 10 CHECK (capacity BETWEEN 1 AND 10),
  available_Seats INTEGER NOT NULL CHECK (available_Seats >= 0),
  status          TEXT    NOT NULL DEFAULT 'Open' CHECK (status IN ('Open','Cancelled')),
  UNIQUE (course_ID, section_No, semester_ID)
);

CREATE TABLE Enrollment (
  student_ID  INTEGER NOT NULL REFERENCES Student(student_ID) ON DELETE CASCADE,
  CRN         INTEGER NOT NULL REFERENCES Class(CRN) ON DELETE CASCADE,
  semester_ID TEXT    NOT NULL REFERENCES Semester(semester_ID),
  grade       TEXT,
  PRIMARY KEY (student_ID, CRN)
);

CREATE TABLE Student_History (
  student_ID  INTEGER NOT NULL REFERENCES Student(student_ID) ON DELETE CASCADE,
  CRN         INTEGER NOT NULL REFERENCES Class(CRN),
  course_ID   TEXT    NOT NULL REFERENCES Course(course_ID),
  semester_ID TEXT    NOT NULL REFERENCES Semester(semester_ID),
  grade       TEXT    NOT NULL,
  PRIMARY KEY (student_ID, CRN)
);

CREATE TABLE Faculty_History (
  faculty_ID  INTEGER NOT NULL REFERENCES Faculty(faculty_ID) ON DELETE CASCADE,
  CRN         INTEGER NOT NULL REFERENCES Class(CRN),
  course_ID   TEXT    NOT NULL REFERENCES Course(course_ID),
  semester_ID TEXT    NOT NULL REFERENCES Semester(semester_ID),
  PRIMARY KEY (faculty_ID, CRN)
);

CREATE TABLE Attendance (
  CRN               INTEGER NOT NULL REFERENCES Class(CRN) ON DELETE CASCADE,
  student_ID        INTEGER NOT NULL REFERENCES Student(student_ID) ON DELETE CASCADE,
  course_ID         TEXT    NOT NULL REFERENCES Course(course_ID),
  attendance_Date   TEXT    NOT NULL,
  attendance_Status TEXT    NOT NULL CHECK (attendance_Status IN ('Present','Absent')),
  PRIMARY KEY (student_ID, CRN, attendance_Date)
);
