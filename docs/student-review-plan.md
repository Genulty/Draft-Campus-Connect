# Student review plan with Register requirement matches

## Review status and source

On November 17, 2026, origin/main was
c95c0cf2115628250b043fccf6193a57312aa6e5. PR #8
(review/faculty-master-schedule, 98b4bdf58360c368a69e8b8dff6c7c4eff20487a),
#9 (fix/admin-access-levels, 74e4189004203108e463558282d272ee5de7bd56)
and #10 (fix/create-section-workflow, 844b5ceeed8ad61c065cf6bb982ad71a26505763)
were open against main.

The isolated review worktree locally applies those three tips in #8 → #9 → #10
order. Its provisional base is b0823f3c6ac77d09d18bbfb5594218e8961b8ce1. None of these local assembly commits
was pushed. The pending Student diff is measured against that provisional
base, excluding the three PRs' application changes. Current main already has
Degree Audit allocation, Major Change Preview, first-year registration,
semester-wide totals and Credit Goal wording. Those changes are reused.

## Recommended PR split and order

1. Review and merge #8, then #9, then #10.
2. Rebase and retest the prepared Student acceptance + Register requirement
   matches diff against the actual updated main. Publish only after that.
3. Keep earned-credit standing as a separate cross-role PR. Its calculation
   and all Student/Admin/Faculty standing displays and standing-based Add
   checks stay outside this Student diff. It can be reviewed independently;
   there is no technical need to merge it before requirement matching.

The original 3011 feature imported academic-standing.php directly because its
worktree inherited standing edits. The Student review copy imports the
existing degree-audit.php instead to obtain auditGradeRank and the existing
audit evaluator. That proves the matcher does not require the standing module.
No academic-standing.php, class-year boundary code, Admin standing cards or
Faculty standing changes are included here.

## Pending Student application scope

- Existing Student acceptance changes from d8a2a3e, reconciled with current main:
  shared program eligibility, the department empty-state recovery button, Drop
  confirmation, traceability and regressions.
- Personalized Requirement match column beside Course in both addable and
  blocked tables. Labels use the signed-in student's actual audit. Completed
  requirements and same-term registrations suppress recommendations.
- The optional Fulfills an unmet requirement filter composes with department
  and semester browsing and can be cleared with All courses.
- Existing shared table renderer handles both Faculty's empty-state message
  and Student's recovery button. Match text wraps in the existing table layout.
- JavaScript and CSS version bumps.

Degree match and registration eligibility are independent. HIST110 may match
draft World History while the specific section is still blocked by a time
conflict. A match never overrides the Add validator. Draft mappings remain
draft and do not certify graduation.

The Student shared program validator also applies to Admin registration.
Its only related Admin test change loads the existing Gen Ed migration in the
disposable Admin fixture, establishing the BIO110 shared pathway the test
uses. No Admin permission or Faculty application code is included in this diff.
The legacy reset-bootstrap fix belongs to #9 and is not duplicated.

## Test fixtures and evidence scope

The disposable demo launcher and browser tests are under tests. They initialize
a new socket-only database, import existing schema/migrations and demo rules,
and use only loopback services. No production migrations, new course mappings,
cap changes, instructor assignments or timetable proposals are included.
The HIST110 alternate is created only during the guarded browser test and
removed afterward. The test restores 100072 to nine credits.

Historical Student evidence stays labeled as historical. It does not replace
a school-wide Spring readiness assessment. UC-3 and UC-23 remain Blocked for
standard secure delivery. The combined local recovery fixture verifies only
the delivery simulation. Faculty Transcript and Degree Audit access stays
closed pending policy. Concurrency remains unverified.

## Combined verification

A fresh build on port 3012 used the local #8/#9/#10 assembly, the prepared
Student acceptance patch and the matcher, with no earned-credit standing
module. Its database server ID is 732156 and its application clock is
December 17, 2026.

- Student 100072 at nine credits sees a draft HIST110 World History label
  beside each existing section's conflict reason. Filter changes preserve
  My Registration and totals, including after refresh and department changes.
- A temporary valid HIST110 alternate is both addable and labeled. After Add,
  that requirement no longer appears as an unmet match for Spring 2027.
  Dropping the test alternate restores the match. The test removes the
  alternate section and returns the student to nine credits.
- Student 100014 has the stored Mathematics major match on Calculus I.
  Completed major and Gen Ed requirements are not labeled for 100001.
- A newly Admin-created full-time Economics student, 117148, reached 13
  credits with labeled Gen Ed options. Degree Audit's missing program credit
  target stayed Rules unavailable after registration and refresh. Nothing
  inferred a degree-credit policy.
- Fresh CS, English and Economics registration tests passed; CS reached 16
  and an over-cap request was refused.
- The same 3012 build passed the cross-role walkthrough: Faculty Period,
  Building and Clear Filters; grade persistence into the Student transcript;
  denied unassigned grading/private record access; Admin read-only save
  refusal; combined section conflicts; valid section visible in Master Schedule.
- Student acceptance, Admin access, Create Section and Major Preview live
  regression runners passed using isolated instances of this code.

Evidence is in evidence/student-review-combined.txt. These results demonstrate
the intended integration before merge. They do not substitute for the required
retest after the three open PRs merge.

## Short demo

Use the existing prepared port-3012 demo and the password shown under Test
accounts.

1. Sign in as Student 100072. Register → Spring 2027 → All Departments.
   Show nine credits, the HIST110 draft match and its blocked conflict reason.
2. Select Fulfills an unmet requirement, refresh, then clear it with All courses.
   My Registration and totals stay unchanged.
3. As Admin 300001, Create Section → Spring 2027 → HIST110 → Hiroshi Tanaka →
   MW 9:30–10:50 → ACB-104 → 10 seats. Preview and create. Return as 100072,
   add the new section, and refresh. The World History requirement stops being
   recommended for that term. Current section numbering may vary after other
   test workflows; use the returned CRN.
4. As 100014, show the personalized Mathematics major match. As Economics
   student 117148, show 13 registered credits and Rules unavailable for the
   missing overall program target.

To create a separate fresh demo from this checkout, run
bash tests/student-review-demo.sh 3012 on a free local port 3012 and pass the
printed private socket to the guarded browser tests. The prepared demo already
runs on this machine, so do not start a second server on that port.

## Exact verification commands

Prerequisites: PHP with PDO MySQL and SQLite, MySQL client/server, Node,
Playwright, and Chromium. Set NODE_PATH if Playwright is installed externally,
and CHROME_PATH if using system Chrome. These are local test dependencies.

```sh
php tests/registration-requirements.php
php tests/registration-windows.php
php tests/registration-locking.php
node tests/registration-windows-browser.cjs
bash tests/student-acceptance.sh
bash tests/admin-access.sh
bash tests/create-section-live.sh
bash tests/degree-audit-major-preview.sh
node tests/security/run.cjs
```

For the same-database browser checks, start the launcher in a separate terminal
on a free port 3012. The guarded browser scripts currently require 3012:

```sh
bash tests/student-review-demo.sh 3012
```

Set DEMO_PASSWORD to the local Test accounts password (do not store it in the
patch). Pass the socket printed by the launcher, never a default database:

```sh
node tests/registration-requirements-browser.cjs "$REVIEW_SOCKET"
node tests/spring-registration-browser.cjs "$REVIEW_SOCKET" --student-review
node tests/student-review-integration.cjs "$REVIEW_SOCKET"
```

The first command prepares 100072's nine-credit schedule when absent, tests
and deletes its HIST110 alternate. The last creates a new synthetic Economics
account and retains its 13-credit schedule for demonstration; the generated ID
is written to ../missing-rules-fulltime-result.json. IDs are not fixed across
runs. This is fixture setup, never a production seed.

The cross-role rehearsal script and its output are included separately in the
local review packet; they are not additional Faculty/Admin application changes
in the Student patch. The final PHP lint, JavaScript and shell syntax checks
and staged Git whitespace check passed. Browser window regression required
the installed Playwright NODE_PATH; it passed with that dependency configured.

## Publication gate

The diff is prepared for local teammate review. It is not ready to publish
while #8–#10 remain open under the agreed order. After their actual merges,
fetch main, regenerate the Student-only diff, preserve intervening edits and
rerun the affected Student/registration/audit, shared Admin registration and
cross-role checks. Review the final staged diff for application scope,
fixture-only SQL, credentials, and whitespace before publishing. No PR was
opened, updated or pushed by this task.
