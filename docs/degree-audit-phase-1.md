# Degree Audit Phase 1

## Findings before implementation

The existing page listed major/minor course requirements and minimum grades, chose
best historical grades, marked current/next-semester enrollment, and showed
coursework outside the major. Its credit deduplication was useful, but a universal
120-credit target, demonstration Gen Ed mappings, department-based Liberal Arts
classification, and fixed 60/9/15 credit goals were not represented by database
policies. Those unsupported goals/statuses have been replaced with explicit gaps.

The existing database supports primary/declared majors, minors, per-course minimum
grades, transcript attempts, current course credits, enrollment grades and semester
dates. It has no program/catalog-year graduation credit targets, Gen Ed mappings,
Liberal Arts eligibility, elective choices/quotas, GPA/residency graduation rules,
substitution/transfer-credit policy, cross-program credit-sharing rules, or full
graduate thesis/dissertation completion requirements. Existing tables are preserved.

## Backend and response

`api/degree-audit.php` loads a profile, majors/minors, required courses, transcript
attempts and enrollments in six SELECT queries with bound student IDs. It uses
Student, User, student subtype credit/status tables, Student_Major, Student_Minor,
Major, Minor, Major_Course_Requirement, Minor_Course_Requirement, Course,
Student_History, Enrollment, Course_Section and Semester. No writes or migrations.

`GET /api/views/degree-audit` remains Student-only through the existing role guard.
The signed-in session ID selects the student; query-string student IDs are ignored.
The response retains title/subtitle/blocks and now provides one `degree-audit` block
with an `audit` object. It contains profile, credits, programs, in_progress,
future_registered, pending_records, history, other_coursework, outstanding,
review and unavailable. Only this page uses the new renderer.

## Calculation contract

- Student_History is the transcript source. All attempts are displayed. Section or
  enrollment-grade conflicts and unrecognized transcript grades need review and
  do not earn calculated credits. Enrollment-only grades await transcript review.
- Grade order is the existing application order: F, D, D+, C-, C, C+, B-, B, B+, A-, A.
  Each distinct course with a recognized grade above F contributes its current
  Course.course_Credits once. These credits are provisional: repeat/transfer rules,
  historical credit values, program eligibility and graduation policy are absent.
- Stored subtype credits_Earned is shown separately and not used as a degree
  target. Differences from transcript-derived credits are flagged for review.
- The highest recognized consistent transcript grade is compared independently
  with each stored requirement minimum. Unknown grades/minima are not guessed.
- Program percentage = met course-list entries / stored course-list entries.
  Empty mappings yield null/unavailable, never 100%. Credit counts for a course
  list are separate from full degree credit goals. No global degree percentage.
- Multiple majors/minors are checked independently; credits are deduplicated in
  the overall provisional total. Cross-program application is not certified.
  A primary Student.major_ID lacking a Student_Major link is displayed and flagged.
- In-progress = ungraded enrollment, no transcript for that CRN, semester started
  and not ended as of database CURDATE(). Future registrations are separate.
  Past ungraded enrollments and enrollment-only grades need review.
- Current/future credit summaries count distinct course codes in each bucket,
  never add to earned credits, and do not promise new credit for repeated courses.
- Coursework outside all stored major/minor lists remains visible as Other Academic
  Coursework; it is not automatically assigned to an elective or Gen Ed category.
- General Education, Liberal Arts, Major Electives and Free Electives are visible
  but unavailable until applicable program rules and approved course mappings exist.
  Completing all stored course lists does not establish graduation eligibility.

## Tests

`DB_USER=root DB_PASSWORD='' php tests/degree-audit.php` checks all existing students
against independent SELECT-only credit, requirement and enrollment queries. It also
runs pure in-memory edge cases; no database fixtures are created. Connection
settings accept DB_SOCKET, DB_NAME, DB_USER and DB_PASSWORD environment variables.
Run `tests/admin-student-accounts.php` separately for Admin regression coverage.

Real records include undergraduates/graduates, no-history students, repeated
courses, multiple majors and minors. Unknown grades, conflicting records and empty
program mappings use isolated calculation tests when absent from live data; they
are not claimed as live-data scenarios. Browser/API tests check session isolation,
program progress, desktop/mobile layout, auth guards and existing feature routes.
Normal password sign-in is not executed because it updates Login counters.

## Verified local results (October 29, 2026)

Independent SELECT-only checks passed for all 240 student records: 60 graduates,
79 students without transcript history, 28 students with repeated courses (31
repeated course groups), five with multiple majors, and 36 with minors. Current
live data has no future registrations. Future/past-ungraded enrollment, unknown
grades, conflicting records and unmapped-program edge cases were verified only
with in-memory calculations, not invented database records.

Browser tests covered desktop and 390px mobile layout, expandable history/program
blocks, session isolation, denied anonymous/non-student audit access, and existing
Student dashboard, Admin account lookup, catalog, Master Schedule and Major Browser
FA26/SP27 routes. The existing signed-out /api/me 401 and missing favicon 404 remain;
no new JavaScript exceptions or failed local requests were observed.

The existing stylesheet/script version markers advance from 7 to 8 so cached
browsers load the renderer required by the new Degree Audit block contract.
