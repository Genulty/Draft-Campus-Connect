# Midterm demo script

Before the demo, reset the data so it starts clean: `sudo bash deploy/reset-db.sh` (on the server).
Every account's password is `Campus123!`. The login page has one-click buttons for each test account.

## 1. Login → Student
Sign in as **student@campus.edu** (Julio Larrea). Optionally show a failed login first: a wrong password
shows how many attempts are left; 5 failures lock the account (UC-1, UC-2).

## 2. Dashboard shows database data
**Overview**: major, GPA, credits earned and the Fall 2026 schedule all come from MySQL
(`Student_Major`, `Student_History`, `Enrollment`, `Course_Section` …). **Transcript** shows 16 graded courses.

## 3. Add a course section (S-R22, UC-24)
1. Open **Register**. Spring 2027 registration is open (add period Oct 1, 2026 – Feb 1, 2027).
2. **Before**: scroll to *Database: Enrollment table*. It shows **0 rows** for this student, with the SQL query above it.
3. Click **Add** on **CS455 System Design and Implementation**.
4. **After**: the green box shows the exact `INSERT INTO Enrollment …` that ran; *My Spring 2027 registration*
   lists CS455; *Database: Enrollment table* now shows the row `100001 | 10550 | —`.
5. Open **My schedule**: CS455 appears under Spring 2027.

The page splits the department's sections in two, using the same rule check as the Add button:
**Sections you can add** (CS450, CS455 for Julio; switch Department to Mathematics, Psychology or English for electives)
and **Sections you can't add**, each with the rule it breaks (Julio has already passed CS110–CS445, so those show S-F10).
Show a rule being enforced (UC-25): click **Try** on **CS110**; the system refuses it with
`S-F10: you already passed CS110`. After adding CS455, other refusals appear in that list, e.g. a section at the same
time (S-F11), a course missing a prerequisite (S-F4), or going over 16 credits (S-F8).
**Drop** (S-R23) removes the row again (`DELETE FROM Enrollment …`).

## 4. Faculty or Admin function
- **Faculty → roster / attendance (F-R8, F-R10)**: sign in as **faculty@campus.edu** (Pavel Clarke).
  *My teaching* shows his Fall 2026 section and its roster with each student's attendance rate (from `Attendance`).
- **Admin → create a course section (A-R26, UC-13)**: sign in as **admin@campus.edu**, open **Create section**.
  - **Before**: *Database: newest Course_Section rows* shows the newest Spring 2027 sections.
  - Choose a course, a faculty member whose label says "teaching 0 of 2" or "1 of 2", a time slot and a room → **Create section**.
  - **After**: the `INSERT INTO Course_Section …` is shown and the new CRN is the top row of the table.
  - Refusals: a faculty member from another department (A-F4), one already teaching 2 (A-F7) or a part-timer
    teaching 1 (A-F8), a room or teacher already busy at that time (A-F6, A-F5).
  - The new section shows up for students on **Register** (choose its department).

## 5. Show the database change directly (optional)
On the server:
```bash
sudo mysql campus_connect -e "SELECT * FROM Enrollment WHERE student_ID = 100001 AND CRN = 10550"
sudo mysql campus_connect -e "SELECT * FROM Course_Section ORDER BY CRN DESC LIMIT 3"
sudo mysql -t campus_connect < db/validate.sql   # every SRS rule still shows 0 violations
```
