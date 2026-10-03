// Opens campus.db. On first start it builds the tables from db/schema.sql
// and adds the two test accounts (one student, one faculty member).
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const Database = require('better-sqlite3');

const DB_PATH = process.env.DB_PATH || path.join(__dirname, '..', 'campus.db');
const isNew = !fs.existsSync(DB_PATH);

const db = new Database(DB_PATH);
db.pragma('foreign_keys = ON');

function hashPassword(plain) {
  const salt = crypto.randomBytes(16).toString('hex');
  return `${salt}:${crypto.scryptSync(plain, salt, 32).toString('hex')}`;
}

function verifyPassword(plain, stored) {
  const [salt, hash] = String(stored).split(':');
  if (!salt || !hash) return false;
  const test = crypto.scryptSync(plain, salt, 32);
  const known = Buffer.from(hash, 'hex');
  return known.length === test.length && crypto.timingSafeEqual(known, test);
}

if (isNew) {
  db.exec(fs.readFileSync(path.join(__dirname, '..', 'db', 'schema.sql'), 'utf8'));

  const addUser = db.prepare(`INSERT INTO User (user_ID, first_Name, last_Name, gender, DOB, street, city, state, zip_Code, user_Type)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`);
  const addLogin = db.prepare('INSERT INTO Login (user_ID, user_Email, user_Password, user_Type) VALUES (?, ?, ?, ?)');

  db.transaction(() => {
    // Test student
    addUser.run(100001, 'Julio', 'Larrea', 'Male', '2004-03-14', '520 Lake View Rd', 'Hicksville', 'NY', '11801', 'Student');
    addLogin.run(100001, 'student@campus.edu', hashPassword('Campus123!'), 'Student');
    db.prepare("INSERT INTO Student (student_ID, student_Year, student_Type) VALUES (100001, 'Junior', 'Undergraduate')").run();
    db.prepare("INSERT INTO Undergraduate_Student (student_ID, undergraduate_Student_Type) VALUES (100001, 'Full-time')").run();
    db.prepare('INSERT INTO Full_Time_Undergraduate (student_ID) VALUES (100001)').run();

    // Test faculty member
    addUser.run(200001, 'Pavel', 'Clarke', 'Male', '1984-08-17', '488 Pine St', 'Westbury', 'NY', '11590', 'Faculty');
    addLogin.run(200001, 'faculty@campus.edu', hashPassword('Campus123!'), 'Faculty');
    db.prepare("INSERT INTO Faculty (faculty_ID, rank, faculty_Type) VALUES (200001, 'Associate Professor', 'Full-time')").run();
  })();
  console.log('Created campus.db with 2 test accounts.');
}

module.exports = { db, verifyPassword };
