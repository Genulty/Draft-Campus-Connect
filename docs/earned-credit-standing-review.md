# Earned-credit standing: separate PR review plan

Prepared and tested locally November 18, 2026. Publication of this standalone
standing PR is authorized; Student changes remain separate.

## Scope and source

Base tested: `c95c0cf2115628250b043fccf6193a57312aa6e5` (`origin/main`, fetched today).
Extracted from the verified 3008 worktree at
`17e9e1353858884b65814a3bf285a3f7a11e6df0` plus its standing-only local edits.
The original worktree, Student registration patch and other demos were not edited.

- Move the existing Degree Audit grade scale, transcript query and credit
  validation into `api/academic-standing.php`. Preserve the existing passing
  grade, evidence validation and once-per-catalog-course credit rules.
- Derive undergraduate year at 32, 57 and 88 earned credits. Keep graduate
  year labels as recorded. Do not rewrite `Student.student_Year` or stored
  credit fields to make the display pass.
- Use that calculation in Degree Audit and its existing Student Overview
  consumer, Faculty rosters/advisees, Admin account lookup and Admin Register.
- Show earned credits separately from the current semester's enrolled credits
  in Admin Register. Existing recorded full/part-time status and caps stay as-is.
- Use the same evidence for Student and Admin-assisted Add. Register can reuse
  one per-page earned total; locked submissions always recompute instead of
  trusting that display cache.
- No JavaScript/CSS or asset bump is needed: existing renderers consume the
  corrected API data. No requirement-match UI, timetable proposal, permission
  expansion, migration, shared seed change or graduation-policy change is included.

## Verified record comparison

| Profile | Unchanged main label | Posted earned | Correct label | Current enrolled |
|---|---|---:|---|---:|
| 100018 | Senior | 50 | Sophomore | Existing records unchanged |
| 100001 | Senior | 59 | Junior | Existing records unchanged |
| 199088 Riley (opt-in fixture) | Freshman | 92 | Senior | 12 Fall 2026 |

Browser and API agree after refresh. Student Overview and Degree Audit,
Admin account and Register, Faculty CS480 roster and advisee lists were checked.
Graduate Degree Audit, Admin account and Admin Register retain the stored year.
CS445 (Spring CRN 10548) is refused for 100001 by both Add routes with the same
Junior-versus-Senior explanation; Riley sees it as addable. The Riley fixture
also replays the normal Add validator for its four current courses. Rejections
and all standing walkthroughs preserve academic row fingerprints.

Pure evidence tests cover repeats, a later failed repeat, F, unsupported/null
unposted grades, future transcript records and inconsistent records. Actual
shared-validator probes exercise 31/32, 56/57 and 87/88 in previews and locked
submissions, stale stored Senior labels, and ignored cached credits on submit.
A real current enrollment is not posted earned credit: Riley stays at 92,
separately from 12 in progress.

## Riley: opt-in synthetic data only

`tests/fixtures/senior-standing.php` and its adjacent history SQL are test-only.
The PHP entry point refuses to load without `--load-senior`, the dedicated
socket prefix, socket identity, skip-networking and server ID 732157.
`tests/academic-standing-live.sh` creates a fresh private database; by default
it leaves Riley absent. It loads Riley only with `--senior`.
Do not run the SQL body directly or use these fixtures in deployment.
The default setup was checked: 240 seeded students and zero Riley rows.

Riley uses existing courses/mappings and 30 distinct passing attempts:
FA23 16, SP24 16, FA24 15, SP25 15, FA25 15, SP26 15 = **92**.
The fixture adds historical sections/two earlier terms, uses existing catalog
courses, and leaves the reference accounts' transcripts and enrollments alone.

Current Fall 2026 sections: CS455 10442, CS460 10901, CS470 10903 and
CS480 Senior Capstone 10902, each three credits. These total **12**, fit the
16-credit cap, and passed the normal validator. Transcript prerequisite order,
lecture/lab conflicts, available seats and room capacity are verified.
Eight major requirements are completed: CS110, CS115, CS220, CS225, CS330,
CS335, CS340 and MATH360. CS445 and CS450 remain Not completed. Capstone is
In progress. Existing draft audit rules remain draft; Senior is not a
statement of graduation readiness.

## Dependencies and overlap

PRs [#8](https://github.com/Genulty/Draft-Campus-Connect/pull/8),
[#9](https://github.com/Genulty/Draft-Campus-Connect/pull/9) and
[#10](https://github.com/Genulty/Draft-Campus-Connect/pull/10) were still open.
Each PR's actual patch applied cleanly over the standing-only tree. The
standing patch is based on current main and has no unmerged-PR dependency.
It does not include their application changes.

The prepared Student patch (SHA-256
`40d0280749e0e383b11999b5092c75ee11f57807fc85aafe4479d8f24c6962b5`)
was copied into a scratch checkout of the tested #8/#9/#10 local assembly
`b0823f3c6ac77d09d18bbfb5594218e8961b8ce1`, then standing was applied.
Two three-way conflicts occurred:

1. `api/views.php`: retain Student's match columns/branching and pass the
   standing total as the optional third `whyCannotAdd` argument. Keep the
   per-page earned-credit read before the loop.
2. `tests/registration-windows.php`: retain both the missing-audit-profile
   stub from Student and the CURDATE stub from standing, plus the existing
   empty transcript adapter.

`api/actions.php` merged automatically, preserving the Student program
validator and the standing gate. Grade ranking now comes from the shared
module through Degree Audit; no matcher import of a standing-only API is needed.
The combined matcher browser/API, standing browser/API, Student acceptance,
threshold, registration-window and matcher calculation suites passed.

Recommended review plan: prepare standing independently against main now;
retain the agreed #8 → #9 → #10 → Student queue. Standing can be reviewed and
land independently, without making Student wait for it. Rebase whichever of
Student/standing lands second, resolve the two hunks as above and rerun the
affected suites. Do not create a stacked dependency merely because two files
have overlapping edits. Nothing is authorized for publication by this task.

## Reproduce the isolated check

Requires PHP/PDO MySQL/SQLite, native MySQL client/server, Node, Playwright and
Chromium. Set NODE_PATH/CHROME_PATH for your installation if needed.
Set DEMO_PASSWORD to the existing local seed Test accounts password; do not
store local credentials in the PR.

```sh
php tests/academic-standing.php
php tests/standing-registration.php
php tests/registration-windows.php
php tests/registration-locking.php
node tests/registration-windows-browser.cjs
# In a separate terminal on a free port:
bash tests/academic-standing-live.sh 3013 --senior
# Copy the printed socket into DB_SOCKET. This must be the private fixture.
node tests/academic-standing-browser.cjs "$DB_SOCKET"
DB_USER=root DB_PASSWORD='' php tests/degree-audit.php
DB_USER=root DB_PASSWORD='' php tests/admin-student-accounts.php
DB_USER=root DB_PASSWORD='' php tests/faculty-lab-visibility.php
DB_USER=root DB_PASSWORD='' node tests/faculty-lab-visibility-browser.cjs
bash tests/degree-audit-major-preview.sh
node tests/security/run.cjs --registration-only
php tests/section-capacity.php
php tests/section-scheduling.php
```

Export DB_SOCKET for the PHP commands. The launcher imports already-existing
demo audit migrations only into its private database; it does not add new
policy or require a production migration for standing. Ctrl-C stops/discards
that private fixture. Without `--senior`, the launcher creates just the
unmodified shared seed plus the existing local demo rules.

## Shortest old/new demo

The current isolated review demo is 3013. A separate unchanged-main copy is
on 3014, reading the same disposable academic fixture. These URLs are local
to this machine, not remote teammate links.

1. As Student 100001 on 3014 Degree Audit, show stored **Senior** beside
   **59 earned**. On 3013, sign in as the same student, open Degree Audit
   and refresh: **Junior**, still **59**. No transcript correction was made.
2. On 3013 as Student 199088, show **Senior / 92 earned / 12 in progress**;
   expand Major Requirements for Capstone In progress and CS445/CS450
   Not completed. As Admin 300001, Find 199088 on Register a student:
   Senior, 92 earned and 12 Fall 2026 enrolled credits are separate.
3. Optional: repeat Student/Admin lookup for 100018: Sophomore at 50.
   Faculty 200003 → My Teaching → Fall 2026 → CS480 roster shows Riley
   Senior and 100001 Junior.

The existing 3008 and 3012 source worktrees and Student patch are intact.
Neither port had an active listener during this session's initial/final
inspection; they were not restarted or reseeded by this task. Review demos
use new databases and ports only.

## Verification limits

See `docs/evidence/earned-credit-standing-local.txt` for executed results.
Degree Audit and Admin regressions cover 241 accounts including Riley; Faculty
covers 608 faculty/semester cases and 12 desktop/mobile pages. Missing combined
Academic/Disciplinary hold and missing enrollment-classification examples were
not introduced for this standing task. Registration concurrency, production,
secure password delivery and official graduation eligibility remain unverified.
The full legacy Admin security/reset suite was not rerun on main: its separate
bootstrap fix belongs to #9. Only its isolated registration-router mode passed.
Student acceptance recovery passes establish fixture-flow behavior, not secure
deployment delivery. No school-wide Spring readiness claim is made.
