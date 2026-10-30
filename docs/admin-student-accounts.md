# Admin student account lookup

In Admin > My account, use **Student account lookup** below the administrator's
own address/password settings. It accepts one student ID or campus email.
The information is read-only; existing personal settings still affect only the
signed-in account. Both Read-Only and Read-Write administrators may inspect it.

## API contract

Existing endpoint: `GET /api/views/account?student=100001` (or a campus email).
The existing title, subtitle, own-account stats, address form, and password
blocks are preserved. No new route or JavaScript component is required.

Only Admin responses append the lookup form and, after a successful lookup,
a table block named `Student Account Information` with columns `Field`, `Value`.
Its rows contain student ID/name, campus email, student type/year, enrollment
status, independent Academic/Disciplinary hold indicators, and placement dates.
Empty lookups show the form only. Invalid inputs and unmatched students return
an explanatory message without retaining a previous student's details.

Other roles ignore the student parameter and retain their own-account response.
The existing requireUser/buildView authentication and role checks remain in place;
the lookup helper independently refuses non-Admin roles. Parameters are bound,
column selection uses trusted fragments, and the shared renderer uses textContent.
No login password/hash, date of birth, or student address is returned by this lookup.

## Data and semantics

- Student.student_ID/student_Type/student_Year identify the student.
- User supplies names; Login optionally supplies the campus email.
- Undergraduate_Student.undergraduate_Student_Type supplies undergraduate status.
- Graduate_Student.graduate_Student_Type supplies graduate status separately.
- Only Full-time and Part-time are displayed as classifications. Missing or
  unexpected values show Unknown / not on file; credits are never used to infer it.
- Hold.hold_Type selects Academic and Disciplinary by their exact existing names.
- Student_Hold links student_ID/hold_ID and provides hold_Date. The composite
  primary key and unique Hold.hold_Type permit at most one record per type/student.
- Yes (record on file) and No record on file describe links, not a claim that a
  historical hold is currently active or resolved. There is no resolved flag,
  expiry/end date, or separate history table in the inspected relationship.
  A missing hold definition displays Unknown (hold type unavailable).

The detail lookup uses one SELECT with LEFT JOINs, without changing hold records.
No schema changes, database writes, migrations, or scheduling changes are included.
Immunization is deferred; Health and Financial are not mapped into these indicators.
The 44-table documented baseline and the extra working tables remain unresolved.

## Validation

Run `DB_USER=root DB_PASSWORD='' php tests/admin-student-accounts.php` against
the existing local database (override DB_SOCKET/DB_NAME/DB_USER/DB_PASSWORD as
needed). The test runner rejects non-SELECT SQL and creates no database fixtures.
It reports scenarios absent from current records rather than inventing data.

The shared page loader ignores older responses after a newer view/filter/lookup
request starts, preventing stale student details during rapid account switching.
Normal successful login is not exercised by the read-only test workflow because
it updates Login.no_Of_Tries. Browser tests use isolated temporary PHP sessions
for existing users; anonymous access and non-admin data isolation are checked.
