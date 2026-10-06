-- Campus Connect database schema (MySQL 8.0+)
-- Matches "Design of the Database — e. Database Schema (Set of Relations)" in the System Manual.
-- Load with:  mysql -u root -p < db/Database_schema.sql
DROP DATABASE IF EXISTS campus_connect;
CREATE DATABASE campus_connect CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE campus_connect;

-- ---------- Users and their sub-entities ----------

CREATE TABLE User (
  user_ID     INT          PRIMARY KEY,
  first_Name  VARCHAR(50)  NOT NULL,
  middle_Name VARCHAR(50),
  last_Name   VARCHAR(50)  NOT NULL,
  gender      ENUM('Male','Female','Other'),
  DOB         DATE,
  street      VARCHAR(100),
  city        VARCHAR(50),
  state       CHAR(2),
  zip_Code    VARCHAR(10),
  user_Type   ENUM('Student','Faculty','Admin','StatDept') NOT NULL
) ENGINE=InnoDB;

CREATE TABLE Login (
  user_ID       INT          PRIMARY KEY,
  user_Email    VARCHAR(100) NOT NULL UNIQUE,
  user_Password VARCHAR(255) NOT NULL,
  no_Of_Tries   INT          NOT NULL DEFAULT 0,
  lock_Var      TINYINT(1)   NOT NULL DEFAULT 0 CHECK (lock_Var IN (0,1)),
  FOREIGN KEY (user_ID) REFERENCES User(user_ID) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE Building (
  building_ID   VARCHAR(10) PRIMARY KEY,
  building_Name VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE Office (
  office_ID   VARCHAR(10) PRIMARY KEY,
  building_ID VARCHAR(10) NOT NULL,
  room_No     VARCHAR(10) NOT NULL,
  UNIQUE (building_ID, room_No),
  FOREIGN KEY (building_ID) REFERENCES Building(building_ID)
) ENGINE=InnoDB;

CREATE TABLE Room (
  room_ID     VARCHAR(10) PRIMARY KEY,
  building_ID VARCHAR(10) NOT NULL,
  room_No     VARCHAR(10) NOT NULL,
  room_Type   ENUM('Lecture','Lab') NOT NULL,
  capacity    INT         NOT NULL CHECK (capacity > 0),
  UNIQUE (building_ID, room_No),
  FOREIGN KEY (building_ID) REFERENCES Building(building_ID)
) ENGINE=InnoDB;

CREATE TABLE Faculty (
  faculty_ID   INT          PRIMARY KEY,
  office_ID    VARCHAR(10),
  specialty    VARCHAR(100),
  `rank`       VARCHAR(30),
  faculty_Type ENUM('Full-time','Part-time') NOT NULL,
  FOREIGN KEY (faculty_ID) REFERENCES User(user_ID) ON DELETE CASCADE,
  FOREIGN KEY (office_ID)  REFERENCES Office(office_ID)
) ENGINE=InnoDB;

CREATE TABLE Admin (
  admin_ID       INT PRIMARY KEY,
  security_Level ENUM('Read-Only','Read-Write') NOT NULL,
  FOREIGN KEY (admin_ID) REFERENCES User(user_ID) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE Stat_Dept_Member (
  stat_dept_ID INT         PRIMARY KEY,
  access_Level VARCHAR(30) NOT NULL DEFAULT 'Aggregate',
  FOREIGN KEY (stat_dept_ID) REFERENCES User(user_ID) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------- Departments, majors, minors, courses ----------

CREATE TABLE Department (
  dept_ID      VARCHAR(10)  PRIMARY KEY,
  dept_Name    VARCHAR(100) NOT NULL UNIQUE,
  chair_ID     INT,
  email        VARCHAR(100),
  phone_No     VARCHAR(20),
  office_ID    VARCHAR(10),
  dept_Manager VARCHAR(100),
  FOREIGN KEY (chair_ID)  REFERENCES Faculty(faculty_ID) ON DELETE SET NULL,
  FOREIGN KEY (office_ID) REFERENCES Office(office_ID)
) ENGINE=InnoDB;

CREATE TABLE Faculty_Department (
  faculty_ID          INT         NOT NULL,
  dept_ID             VARCHAR(10) NOT NULL,
  percent_Time        INT         NOT NULL DEFAULT 100 CHECK (percent_Time BETWEEN 1 AND 100),
  date_Of_Appointment DATE        NOT NULL,
  PRIMARY KEY (faculty_ID, dept_ID),
  FOREIGN KEY (faculty_ID) REFERENCES Faculty(faculty_ID) ON DELETE CASCADE,
  FOREIGN KEY (dept_ID)    REFERENCES Department(dept_ID) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE Major (
  major_ID   VARCHAR(10)  PRIMARY KEY,
  dept_ID    VARCHAR(10)  NOT NULL,
  major_Name VARCHAR(100) NOT NULL UNIQUE,
  FOREIGN KEY (dept_ID) REFERENCES Department(dept_ID)
) ENGINE=InnoDB;

CREATE TABLE Minor (
  minor_ID   VARCHAR(10)  PRIMARY KEY,
  dept_ID    VARCHAR(10)  NOT NULL,
  minor_Name VARCHAR(100) NOT NULL UNIQUE,
  FOREIGN KEY (dept_ID) REFERENCES Department(dept_ID)
) ENGINE=InnoDB;

CREATE TABLE Course (
  course_ID      VARCHAR(10)  PRIMARY KEY,
  course_Name    VARCHAR(100) NOT NULL,
  dept_ID        VARCHAR(10)  NOT NULL,
  course_Credits INT          NOT NULL CHECK (course_Credits BETWEEN 1 AND 6),
  course_Desc    VARCHAR(500),
  course_Type    ENUM('Undergraduate','Graduate') NOT NULL,
  FOREIGN KEY (dept_ID) REFERENCES Department(dept_ID)
) ENGINE=InnoDB;

-- MySQL does not allow a CHECK on columns whose foreign keys cascade,
-- so these two foreign keys use the default (RESTRICT).
CREATE TABLE Course_Prerequisite (
  course_ID              VARCHAR(10) NOT NULL,
  prerequisite_Course_ID VARCHAR(10) NOT NULL,
  min_Grade_Req          VARCHAR(2)  NOT NULL DEFAULT 'D',
  PRIMARY KEY (course_ID, prerequisite_Course_ID),
  CHECK (course_ID <> prerequisite_Course_ID),
  FOREIGN KEY (course_ID)              REFERENCES Course(course_ID),
  FOREIGN KEY (prerequisite_Course_ID) REFERENCES Course(course_ID)
) ENGINE=InnoDB;

CREATE TABLE Major_Course_Requirement (
  major_ID  VARCHAR(10) NOT NULL,
  course_ID VARCHAR(10) NOT NULL,
  PRIMARY KEY (major_ID, course_ID),
  FOREIGN KEY (major_ID)  REFERENCES Major(major_ID)   ON DELETE CASCADE,
  FOREIGN KEY (course_ID) REFERENCES Course(course_ID) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE Minor_Course_Requirement (
  minor_ID  VARCHAR(10) NOT NULL,
  course_ID VARCHAR(10) NOT NULL,
  PRIMARY KEY (minor_ID, course_ID),
  FOREIGN KEY (minor_ID)  REFERENCES Minor(minor_ID)   ON DELETE CASCADE,
  FOREIGN KEY (course_ID) REFERENCES Course(course_ID) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------- Students ----------

CREATE TABLE Student (
  student_ID   INT         PRIMARY KEY,
  student_Year VARCHAR(20) NOT NULL,
  student_Type ENUM('Undergraduate','Graduate') NOT NULL,
  FOREIGN KEY (student_ID) REFERENCES User(user_ID) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE Undergraduate_Student (
  student_ID                 INT         PRIMARY KEY,
  dept_ID                    VARCHAR(10),
  undergraduate_Student_Type ENUM('Full-time','Part-time') NOT NULL,
  FOREIGN KEY (student_ID) REFERENCES Student(student_ID) ON DELETE CASCADE,
  FOREIGN KEY (dept_ID)    REFERENCES Department(dept_ID)
) ENGINE=InnoDB;

CREATE TABLE Full_Time_Undergraduate (
  student_ID     INT         PRIMARY KEY,
  status         VARCHAR(30) NOT NULL DEFAULT 'Good Standing',
  min_Credits    INT         NOT NULL DEFAULT 12,
  max_Credits    INT         NOT NULL DEFAULT 16,
  credits_Earned INT         NOT NULL DEFAULT 0,
  FOREIGN KEY (student_ID) REFERENCES Undergraduate_Student(student_ID) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE Part_Time_Undergraduate (
  student_ID     INT         PRIMARY KEY,
  status         VARCHAR(30) NOT NULL DEFAULT 'Good Standing',
  min_Credits    INT         NOT NULL DEFAULT 1,
  max_Credits    INT         NOT NULL DEFAULT 8,
  credits_Earned INT         NOT NULL DEFAULT 0,
  FOREIGN KEY (student_ID) REFERENCES Undergraduate_Student(student_ID) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE Graduate_Student (
  student_ID            INT         PRIMARY KEY,
  dept_ID               VARCHAR(10),
  program               ENUM('MA/MS','PhD') NOT NULL,
  graduate_Student_Type ENUM('Full-time','Part-time') NOT NULL,
  FOREIGN KEY (student_ID) REFERENCES Student(student_ID) ON DELETE CASCADE,
  FOREIGN KEY (dept_ID)    REFERENCES Department(dept_ID)
) ENGINE=InnoDB;

CREATE TABLE Full_Time_Graduate_Student (
  student_ID     INT         PRIMARY KEY,
  year           INT         NOT NULL DEFAULT 1,
  credits_Earned INT         NOT NULL DEFAULT 0,
  thesis_Type    ENUM('Thesis','Non-Thesis','Dissertation') NOT NULL DEFAULT 'Non-Thesis',
  FOREIGN KEY (student_ID) REFERENCES Graduate_Student(student_ID) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE Part_Time_Graduate_Student (
  student_ID     INT         PRIMARY KEY,
  year           INT         NOT NULL DEFAULT 1,
  credits_Earned INT         NOT NULL DEFAULT 0,
  thesis_Type    ENUM('Thesis','Non-Thesis','Dissertation') NOT NULL DEFAULT 'Non-Thesis',
  FOREIGN KEY (student_ID) REFERENCES Graduate_Student(student_ID) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE Student_Major (
  student_ID     INT         NOT NULL,
  major_ID       VARCHAR(10) NOT NULL,
  date_Of_Choice DATE        NOT NULL,
  PRIMARY KEY (student_ID, major_ID),
  FOREIGN KEY (student_ID) REFERENCES Student(student_ID) ON DELETE CASCADE,
  FOREIGN KEY (major_ID)   REFERENCES Major(major_ID)
) ENGINE=InnoDB;

CREATE TABLE Student_Minor (
  student_ID     INT         NOT NULL,
  minor_ID       VARCHAR(10) NOT NULL,
  date_Of_Choice DATE        NOT NULL,
  PRIMARY KEY (student_ID, minor_ID),
  FOREIGN KEY (student_ID) REFERENCES Student(student_ID) ON DELETE CASCADE,
  FOREIGN KEY (minor_ID)   REFERENCES Minor(minor_ID)
) ENGINE=InnoDB;

CREATE TABLE Advisor (
  faculty_ID          INT  NOT NULL,
  student_ID          INT  NOT NULL,
  date_Of_Appointment DATE NOT NULL,
  PRIMARY KEY (faculty_ID, student_ID),
  FOREIGN KEY (faculty_ID) REFERENCES Faculty(faculty_ID) ON DELETE CASCADE,
  FOREIGN KEY (student_ID) REFERENCES Student(student_ID) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE Hold (
  hold_ID   INT PRIMARY KEY,
  hold_Type ENUM('Academic','Financial','Health','Disciplinary') NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE Student_Hold (
  student_ID INT  NOT NULL,
  hold_ID    INT  NOT NULL,
  hold_Date  DATE NOT NULL,
  PRIMARY KEY (student_ID, hold_ID),
  FOREIGN KEY (student_ID) REFERENCES Student(student_ID) ON DELETE CASCADE,
  FOREIGN KEY (hold_ID)    REFERENCES Hold(hold_ID)
) ENGINE=InnoDB;

-- ---------- Calendar and scheduling ----------

CREATE TABLE Semester (
  semester_ID   VARCHAR(10) PRIMARY KEY,
  semester_Name VARCHAR(30) NOT NULL,
  start_Date    DATE        NOT NULL,
  end_Date      DATE        NOT NULL,
  add_Start     DATE        NOT NULL,
  add_End       DATE        NOT NULL,
  drop_Start    DATE        NOT NULL,
  drop_End      DATE        NOT NULL,
  grade_Start   DATE        NOT NULL,
  grade_End     DATE        NOT NULL,
  CHECK (start_Date < end_Date),
  CHECK (add_Start <= add_End),
  CHECK (drop_Start <= drop_End),
  CHECK (grade_Start <= grade_End)
) ENGINE=InnoDB;

CREATE TABLE Day (
  day_ID   CHAR(1)     PRIMARY KEY,
  week_Day VARCHAR(10) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE Period (
  period_ID  INT  PRIMARY KEY,
  start_Time TIME NOT NULL,
  end_Time   TIME NOT NULL,
  CHECK (start_Time < end_Time)
) ENGINE=InnoDB;

CREATE TABLE Time_Slot (
  time_Slot_ID INT PRIMARY KEY
) ENGINE=InnoDB;

CREATE TABLE Time_Slot_Day (
  time_Slot_ID INT     NOT NULL,
  day_ID       CHAR(1) NOT NULL,
  PRIMARY KEY (time_Slot_ID, day_ID),
  FOREIGN KEY (time_Slot_ID) REFERENCES Time_Slot(time_Slot_ID) ON DELETE CASCADE,
  FOREIGN KEY (day_ID)       REFERENCES Day(day_ID)
) ENGINE=InnoDB;

CREATE TABLE Time_Slot_Period (
  time_Slot_ID INT NOT NULL,
  period_ID    INT NOT NULL,
  PRIMARY KEY (time_Slot_ID, period_ID),
  FOREIGN KEY (time_Slot_ID) REFERENCES Time_Slot(time_Slot_ID) ON DELETE CASCADE,
  FOREIGN KEY (period_ID)    REFERENCES Period(period_ID)
) ENGINE=InnoDB;

-- A course section with fewer than 5 registered students is cancelled (deleted) before the semester starts.
CREATE TABLE Course_Section (
  CRN          INT         PRIMARY KEY,
  course_ID    VARCHAR(10) NOT NULL,
  section_No   VARCHAR(5)  NOT NULL,
  faculty_ID   INT,
  time_Slot_ID INT         NOT NULL,
  room_ID      VARCHAR(10) NOT NULL,
  semester_ID  VARCHAR(10) NOT NULL,
  max_Seats    INT         NOT NULL DEFAULT 10 CHECK (max_Seats BETWEEN 1 AND 10),
  UNIQUE (course_ID, section_No, semester_ID),
  UNIQUE (room_ID, time_Slot_ID, semester_ID),     -- no two sections in one room at the same time
  UNIQUE (faculty_ID, time_Slot_ID, semester_ID),  -- no faculty member teaches two sections at the same time
  FOREIGN KEY (course_ID)    REFERENCES Course(course_ID),
  FOREIGN KEY (faculty_ID)   REFERENCES Faculty(faculty_ID),
  FOREIGN KEY (time_Slot_ID) REFERENCES Time_Slot(time_Slot_ID),
  FOREIGN KEY (room_ID)      REFERENCES Room(room_ID),
  FOREIGN KEY (semester_ID)  REFERENCES Semester(semester_ID)
) ENGINE=InnoDB;

-- ---------- Registration, grades, attendance ----------

CREATE TABLE Enrollment (
  student_ID INT        NOT NULL,
  CRN        INT        NOT NULL,
  grade      VARCHAR(2),
  PRIMARY KEY (student_ID, CRN),
  FOREIGN KEY (student_ID) REFERENCES Student(student_ID)    ON DELETE CASCADE,
  FOREIGN KEY (CRN)        REFERENCES Course_Section(CRN)    ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE Attendance (
  student_ID        INT  NOT NULL,
  CRN               INT  NOT NULL,
  attendance_Date   DATE NOT NULL,
  attendance_Status ENUM('Present','Absent') NOT NULL,
  PRIMARY KEY (student_ID, CRN, attendance_Date),
  FOREIGN KEY (student_ID) REFERENCES Student(student_ID)    ON DELETE CASCADE,
  FOREIGN KEY (CRN)        REFERENCES Course_Section(CRN)    ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE Student_History (
  student_ID  INT         NOT NULL,
  CRN         INT         NOT NULL,
  semester_ID VARCHAR(10) NOT NULL,
  grade       VARCHAR(2)  NOT NULL,
  PRIMARY KEY (student_ID, CRN),
  FOREIGN KEY (student_ID)  REFERENCES Student(student_ID) ON DELETE CASCADE,
  FOREIGN KEY (CRN)         REFERENCES Course_Section(CRN),
  FOREIGN KEY (semester_ID) REFERENCES Semester(semester_ID)
) ENGINE=InnoDB;

CREATE TABLE Faculty_History (
  faculty_ID  INT         NOT NULL,
  CRN         INT         NOT NULL,
  semester_ID VARCHAR(10) NOT NULL,
  PRIMARY KEY (faculty_ID, CRN),
  FOREIGN KEY (faculty_ID)  REFERENCES Faculty(faculty_ID) ON DELETE CASCADE,
  FOREIGN KEY (CRN)         REFERENCES Course_Section(CRN),
  FOREIGN KEY (semester_ID) REFERENCES Semester(semester_ID)
) ENGINE=InnoDB;

-- ---------- Admin audit log (A-R43, A-R44) ----------

CREATE TABLE Audit_Log (
  log_ID      INT          PRIMARY KEY AUTO_INCREMENT,
  admin_ID    INT          NOT NULL,
  user_ID     INT          NOT NULL,
  action_Type ENUM('Create','Update','Delete') NOT NULL,
  field_Name  VARCHAR(50),
  old_Value   VARCHAR(255),
  new_Value   VARCHAR(255),
  date_Time   DATETIME     NOT NULL,
  FOREIGN KEY (admin_ID) REFERENCES Admin(admin_ID),
  FOREIGN KEY (user_ID)  REFERENCES User(user_ID)
) ENGINE=InnoDB;
