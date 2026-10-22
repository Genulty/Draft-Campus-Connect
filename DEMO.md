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

## 6. Admin creates a new user, then the new student signs in
New accounts are created by an admin, not on the sign-in page (A-R5, Admin UC-3).
1. Sign in as **admin@campus.edu** (a Read-Write admin) and open **Create user**.
2. Leave **Type of user** on *Student*. Enter a name, gender, date of birth, level, status and major (the address can
   stay empty) and a starting password, then **Create user**. Choosing *Faculty*, *Admin* or *Stat-Dept member*
   instead changes the form to that role's fields.
3. The *Done* message shows the new **random ID** and **campus email**, and the account appears in *Accounts created
   by admins*. Rows were added to `User`, `Login`, `Student`, `Undergraduate_Student`, `Full_Time_Undergraduate`,
   `Student_Major`, `Advisor` and `Audit_Log`.
4. Refusal (A-F2): sign in as the Read-Only admin **brian.jones@campus.edu** and try the same; it is refused.
5. Sign out and sign in as the new student with the email or ID and the starting password.

## 7. Password reset after 3 failed sign-ins
1. Enter a wrong password **3 times**: a *Forgot your password? Reset it* link appears (S-R2).
2. Verify with the user ID and date of birth (S-F2) and choose a new password. Failed attempts go back to 0
   and a locked account is unlocked. Sign in with the new password.

## 8. Every student use case
Signed in as **student@campus.edu**, each of the manual's 41 student use cases is on one of these pages.
The *Unsuccessful* cases show a red *Not allowed* message with the reason, and nothing in the database changes.

| Use case | Page | How to show it |
|---|---|---|
| 1–2 Successful / unsuccessful login | Sign-in page | Correct password, then a wrong one |
| 3–4 Successful / unsuccessful password reset | Sign-in page | Step 7 above; a wrong date of birth is refused |
| 5 View own dashboard | Overview | Opens after sign-in |
| 6–7 Current / next-semester schedule | My schedule | Fall 2026 and Spring 2027 tables |
| 8 View holds | Holds & advisors | *Active holds* |
| 9 Semester grades | Grades | Pick a semester; shows that semester's GPA |
| 10 Unofficial transcript | Transcript | All graded courses and the cumulative GPA |
| 11 Degree audit | Degree audit | Each required course: completed, in progress or not started |
| 12 Advisor contact information | Holds & advisors | *My advisors* with email and office |
| 13 View departments | Course catalog | *University departments* |
| 14 View courses | Course catalog | Pick a department |
| 15–16 View majors / minors | Majors & minors | *Majors offered*, *Minors offered* |
| 17–18 Major / minor course requirements | Majors & minors | Pick one in *Show requirements for* |
| 19 View course prerequisites | Course catalog | *Prerequisites* column |
| 20 Successful prerequisite search | Prerequisite search | Search **CS455** or **Calculus** |
| 21 Unsuccessful prerequisite search | Prerequisite search | Search **CS999** (no such course) or **CS110** (no prerequisites) |
| 22 Update address | My account | *Update address*; a state like "New York" is refused |
| 23 Update password | My account | *Change password*; a wrong current password is refused |
| 24–27 Add / drop a section | Register | Step 3 above |
| 28, 32 Add a (second) major | My majors & minors | *Add a major* → B.S. in Mathematics; a new advisor is assigned |
| 29, 33 Unsuccessful add major | My majors & minors | A graduate major (M.S.), one already declared, or a 3rd major |
| 30 Change a major | My majors & minors | *Change a major* |
| 31 Unsuccessful change | My majors & minors | Change to a graduate major or one you already have |
| 34 Drop a major | My majors & minors | *Drop a major* when you have 2 |
| 35 Unsuccessful drop major | My majors & minors | Drop your only major, or one that isn't yours |
| 36 Add a minor | My majors & minors | *Add a minor* → Minor in Psychology |
| 37 Unsuccessful add minor | My majors & minors | A minor in your major's department, or a second minor |
| 38–39 Change a minor | My majors & minors | *Change a minor*; with no minor it is refused |
| 40–41 Drop a minor | My majors & minors | *Drop a minor*; one that isn't yours is refused |

Program rules: at most 2 majors and 1 minor, majors must match the student's level (undergraduate or graduate),
no minor in a major's department, and every student keeps at least one major. Advisors follow the programs: one
full-time advisor per department of each major and the minor, at most 2 (S-R11, A-F12).
