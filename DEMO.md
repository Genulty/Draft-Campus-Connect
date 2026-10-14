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
2. **Before**: *My Spring 2027 registration* is empty. Julio has passed every CS course except CS455, which he is
   taking this fall, so set **Department** to **Mathematics**.
3. Click **Add** on **MATH115 Calculus II** (CRN 10556).
4. **After**: a green *Done* message appears and *My Spring 2027 registration* lists MATH115. Open seats for that
   section drop from 10 to 9 (a database trigger keeps `Course_Section.available_Seats` up to date).
5. Open **My schedule**: MATH115 appears under Spring 2027. To show the row in MySQL, see step 5 below.

The page splits the department's sections in two, using the same rule check as the Add button:
**Sections you can add** and **Sections you can't add**, each with the rule it breaks. Show a rule being enforced
(UC-25): with Department = Computer Science, click **Try** on **CS110**; the system refuses it with "You already
passed CS110" (S-F10). With Department = Mathematics, **Try** on **MATH220** shows "Missing prerequisite MATH115"
(S-F4). Other refusals: a section at the same time (S-F11) or going over 16 credits (S-F8).
**Drop** (S-R23) removes the row again (`DELETE FROM Enrollment …`).

## 4. Faculty or Admin function
- **Faculty → roster / attendance (F-R8, F-R10)**: sign in as **faculty@campus.edu** (Pavel Clarke).
  *My teaching* shows his Fall 2026 section (CS225) and its roster with each student's attendance rate (from `Attendance`).
- **Admin → create a course section (A-R26, UC-13)**: sign in as **admin@campus.edu**, open **Create section**.
  - **Before**: *Newest Spring 2027 sections* lists the latest sections.
  - Choose a course, a faculty member whose label says "teaching 0 of 2" or "1 of 2", a time slot and a room → **Create section**.
  - **After**: a *Done* message names the new CRN, and it is the top row of *Newest Spring 2027 sections*.
  - Refusals: a faculty member from another department (A-F4), one already teaching 2 (A-F7) or a part-timer
    teaching 1 (A-F8), a room or teacher already busy at that time (A-F6, A-F5).
  - The new section shows up for students on **Register** (choose its department).

## 5. Show the database change directly (optional)
On the server:
```bash
sudo mysql campus_connect -e "SELECT * FROM Enrollment WHERE student_ID = 100001 AND CRN = 10556"
sudo mysql campus_connect -e "SELECT CRN, max_Seats, available_Seats FROM Course_Section WHERE CRN = 10556"
sudo mysql campus_connect -e "SELECT * FROM Course_Section ORDER BY CRN DESC LIMIT 3"
sudo mysql -t campus_connect < db/validate.sql   # all 27 checks still show 0 violations
```

## 6. Sign up as a new student, then reset the password
1. On the sign-in page click **Create an account**. Enter a full name, gender, date of birth, status and major
   (the address can stay empty) and a password, then **Create account**.
2. The next screen shows the new **random student ID** and **campus email**. Behind the scenes, rows were added to
   `User`, `Login`, `Student`, `Undergraduate_Student`, `Full_Time_Undergraduate`, `Student_Major` and `Advisor`.
3. **Sign in now** with the email or the student ID. The new student's dashboard loads from MySQL, and **Register**
   lets them add Spring 2027 intro courses. `Student.major_ID` and `Login.user_Type` are filled in too.
4. Sign out and enter a wrong password **3 times**: a *Forgot your password? Reset it* link appears (S-R2).
5. Verify with the student ID and date of birth (S-F2) and choose a new password. Failed attempts go back to 0
   and a locked account is unlocked. Sign in with the new password.

