# Student Portal Acceptance and Requirements Traceability

This is the Student acceptance update to section g of the Campus Connect System Manual (PDF pages 116–117). It keeps the manual's Student requirement IDs and adds the remaining Student use cases from the detailed use-case document. **39 of 41 passed.** UC-3 and UC-23 are blocked in the standard configuration. Use the status column for the original closeout fixture. Its audit figures and Spring coverage are historical. **Passed** means the workflow and its relevant rejection or empty state matched the requirement. **Blocked** means the normal configuration lacked a prerequisite for the workflow. Hosted behavior is **Not tested**.

## Tested build and data

- Repository worktree `/private/tmp/campus-major-preview.QyBEE8/worktree`, baseline `feature/degree-audit-major-preview` at `0c098ea0b58a3cab952a1c6a2aaaa5232843f8a1`. The acceptance snapshot is recorded on local branch `review/student-acceptance`. The tested build includes the uncommitted Student registration patch and the UC-26 confirmation fix. The served asset versions are `login.js?v=21` and `styles.css?v=15`.
- Local walkthrough at `http://localhost:3003`, PHP 8.5.6, Chrome 157.0.8103.52, and MySQL 26.10.0 using `campus_connect` over `/private/tmp/campus-audit-preview.EWqreR/mysql.sock` with MySQL networking disabled. The MySQL date was November 16, 2026. Fall 2026 was current. Spring 2027 was next, with Add open through February 1, 2027 and Drop open through February 15, 2027.
- The walkthrough used the seeded schema and data, the Gen Ed and degree-audit migrations, and the local draft `BS-CS` audit fixture. The write-capable acceptance suite used a fresh socket-only copy. It started the same app with standard recovery disabled and a second loopback server with fixture-only recovery enabled. The targeted Spring 2027 walkthrough at port 3003 used only temporary write-test accounts and verified the original seat counts after cleanup.
- Major preview, the local registration changes, and the draft audit rules are evidenced here only on localhost. The hosted application, its revision, its database, and mail delivery were outside this acceptance run.

## Student use cases

| Use case | SRS | Portal page | Expected outcome | Status | Evidence |
|---|---|---|---|---|---|
| UC-1 Successful login | S-R1 | Sign in | Valid credentials open the Student Dashboard. | Passed | Chromium sign-in followed by a successful reload. `Login`, `User`, `Student`. |
| UC-2 Unsuccessful login | S-F1 | Sign in | Bad credentials are rejected and repeated failures lock the account. | Passed | Three HTTP 401 responses, locked account returned 423, anonymous Overview returned 401. `Login`. |
| UC-3 Successful password reset | S-R2 | Forgot password | After receiving and verifying a recovery code or link, the student can save a new password and sign in. | Blocked | Standard configuration reports delivery unavailable. In the local fixture, a test-delivered one-time link unlocked the account and the new password worked. A locked account could not read its own mailbox to retrieve the link. `Login`, `Password_Reset`, `Email_Message`. |
| UC-4 Unsuccessful password reset | S-F2 | Forgot password | Invalid verification or an unverified submission leaves the password unchanged. | Passed | Local verification fixture rejected a wrong code, completion without verification, and replay of a used link. Standard configuration failed closed. |
| UC-5 View own dashboard | S-R3 | Overview | Show the signed-in student's dashboard. | Passed | Chromium displayed Student 100014's Overview after login and refresh. Anonymous access returned 401. `User`, `Student`, `Enrollment`, `Student_Hold`. |
| UC-6 View current schedule | S-R4 | My schedule | Show the student's current-term schedule. | Passed | Fall 2026 displayed the student's existing enrollment. Anonymous access was rejected. `Enrollment`, `Course_Section`, `Semester`. |
| UC-7 View next schedule | S-R5 | My schedule | Show next-term enrollment when a next term is available. | Passed | Spring 2027 changed from empty to the saved BIO110 lecture and lab after Add, then returned to empty after Drop. `Course_Section_Lab`. |
| UC-8 View account holds | S-R6 | Holds & advisors | Show active holds for the student. | Passed | An academic hold and a separate no-hold account displayed correctly. All four hold types were tested at Add. `Student_Hold`, `Hold`. |
| UC-9 View semester grades | S-R7 | Grades | Show grades for the selected semester and handle a semester without posted grades. | Passed | Seeded Fall 2024 grades and the separate “No grades yet” state. `Student_History`, `Enrollment`, `Semester`. |
| UC-10 View unofficial transcript | S-R8 | Transcript | Show the student's own completed record. | Passed | Seeded and empty transcripts displayed. Another student's ID in the query did not expose that student's record. `Student_History`, `Course`, `Course_Section`. |
| UC-11 View degree audit | S-R9 | Degree Audit | Evaluate the student's records and report draft or unavailable rules. | Passed | Seven Gen Ed categories, draft flags, 50 provisional earned credits, invalid preview 422, and “Rules unavailable” for missing program rules. Preview preserved the academic records. `Student_History`, `Enrollment`, `Degree_Audit_*`. |
| UC-12 View advisor contact information | S-R10, S-R11 | Holds & advisors | Show assigned advisor contact details and support up to two advisors. | Passed | Advisor email displayed. Adding a second major produced two advisors. Anonymous access was rejected. `Advisor`, `Faculty`, `Login`. |
| UC-13 View departments | S-R12 | Course catalog | List university departments. | Passed | All 10 seeded departments displayed. Invalid department selection fell back safely. `Department`. |
| UC-14 View courses | S-R13 | Course catalog | Show courses for the selected department. | Passed | Mathematics selection showed MATH110. `Department`, `Course`. |
| UC-15 View majors | S-R14 | Majors & minors | List the offered majors. | Passed | 16 seeded major programs displayed. `Major`. |
| UC-16 View minors | S-R16 | Majors & minors | List the offered minors. | Passed | 10 seeded minors displayed. `Minor`. |
| UC-17 View major requirements | S-R15 | Majors & minors | Show courses for the selected major. | Passed | CS major showed CS110. An invalid program selection fell back safely. `Major_Course_Requirement`. |
| UC-18 View minor requirements | S-R17 | Majors & minors | Show courses for the selected minor. | Passed | Psychology minor showed PSY110. `Minor_Course_Requirement`. |
| UC-19 View course prerequisites | S-R18 | Prerequisite search | Show the required prior course and minimum grade. | Passed | HIST220 showed HIST115 and a D minimum. `Course_Prerequisite`. |
| UC-20 Successful prerequisite search | S-R19 | Prerequisite search | Find prerequisites by course ID or title. | Passed | HIST220 and United States History returned the same prerequisite. |
| UC-21 Unsuccessful prerequisite search | S-R19 | Prerequisite search | Explain a missing course or missing prerequisite record. | Passed | Unknown course and a known course with no prerequisite each showed a specific message. |
| UC-22 Update address | S-R20 | My account | Validate and save the student's permitted address fields. | Passed | Invalid state rejected. Valid address persisted after reload. Submitted foreign ID and email did not change identity or another student's record. `User`, `Login`. |
| UC-23 Update password | S-R21 | My account → Change password | Verify the current password and save a new one after code verification. | Blocked | Standard configuration reports delivery unavailable. In the authenticated local fixture, the wrong current password was rejected, a valid code permitted the update, the old password stopped working, and the other session was revoked. |
| UC-24 Successfully add a section | S-R22, S-F3–S-F12 | Register | Add an eligible section and persist the schedule and credit total. | Passed | Cross-major BIO110 added in Chromium, including its lab. Refresh and My schedule confirmed it. Newly created CS account reached 12 and 16 one add at a time. `Enrollment`, `Course_Section`, `Course_Section_Lab`. |
| UC-25 Unsuccessfully add a section | S-F3–S-F12 | Register | Reject invalid registration and explain the rule without an enrollment or seat change. | Passed | Tested each guard in the table below. English and Economics also received the same CS110 major restriction in the list and HTTP Add. |
| UC-26 Successfully drop a section | S-R23, S-F13 | Register | Confirm the drop in the open window, then remove it from the schedule. | Passed | Initially failed: Drop immediately posted the request. After the fix, Cancel made no POST. Confirm returned 200, and the refresh showed no section and 0 credits. `Enrollment`, `Semester`. |
| UC-27 Unsuccessfully drop a section | S-F13 | Register | Reject a closed-window drop or a section the student does not own. | Passed | Closed Fall drop, repeated Spring drop, and attempted drop against another student's CRN all returned 422. |
| UC-28 Declare/add a major | S-R24, S-F19, S-F20 | My majors & minors | Add an eligible major and persist it. | Passed | Existing accounts have a designated primary major at provisioning. The Student Add workflow saved a valid declaration and displayed it after reload. `Student`, `Student_Major`. |
| UC-29 Reject invalid major declaration | S-F19, S-F20 | My majors & minors | Reject an invalid or wrong-level major. | Passed | Unknown major and a graduate major for an undergraduate returned 422. |
| UC-30 Change a major | S-R25, S-F19, S-F21 | My majors & minors → Degree Audit | Save the permitted change and show the new audit requirements without altering academic history. | Passed | Primary change persisted after reload. In the preview regression CS → Economics showed eight Economics requirements and left transcript and 50 earned credits intact. |
| UC-31 Reject an invalid major change | S-F19, S-F21 | My majors & minors | Reject a change from an undeclared major. | Passed | The API rejected replacing an undeclared Biology major. |
| UC-32 Add a second major | S-R26, S-F19, S-F20 | My majors & minors | Add a second valid declaration while keeping the primary. | Passed | Mathematics retained as primary and CS visible after reload. Two advisors were shown. |
| UC-33 Reject a third major | S-F19, S-F20 | My majors & minors | Enforce the two-major limit. | Passed | A third major returned 422. |
| UC-34 Drop a major | S-R27, S-F21 | My majors & minors | Drop an existing major when permitted. | Passed | CS was removed and the remaining Economics declaration was confirmed by a new GET. |
| UC-35 Reject an invalid major drop | S-F21 | My majors & minors | Reject dropping an undeclared major or the last remaining major. | Passed | Both requests returned 422 with specific reasons. |
| UC-36 Add a minor | S-R28, S-F22 | My majors & minors | Save a valid minor. | Passed | Psychology minor appeared on a new GET. `Student_Minor`. |
| UC-37 Reject an invalid minor | S-F22 | My majors & minors | Reject an unknown or otherwise invalid minor. | Passed | Unknown and same-department minors returned 422. |
| UC-38 Change a minor | S-R29, S-F22, S-F23 | My majors & minors | Save a permitted replacement minor. | Passed | Psychology → Biology persisted after reload. |
| UC-39 Reject an invalid minor change | S-F22, S-F23 | My majors & minors | Reject replacing an undeclared minor. | Passed | Attempt to replace an undeclared English minor returned 422. |
| UC-40 Drop a minor | S-R30, S-F23 | My majors & minors | Drop a currently declared minor. | Passed | Biology minor disappeared after reload and the empty state was shown. |
| UC-41 Reject an invalid minor drop | S-F23 | My majors & minors | Reject dropping an undeclared minor. | Passed | Attempt to drop an undeclared Psychology minor returned 422. |

## Registration and authorization guard evidence

| SRS | Evidence | Status |
|---|---|---|
| S-F3 | Academic, financial, health, and disciplinary holds each rejected Add. | Passed |
| S-F4 | HIST220 rejected when HIST115 was missing. | Passed |
| S-F5, S-F6 | Undergraduate-to-graduate and graduate-to-undergraduate Add returned 422. | Passed |
| S-F7, S-F8 | Part-time 6 → 9 and full-time 16 → above cap returned 422. | Passed |
| S-F9 | A zero-seat fixture rejected Add. | Passed |
| S-F10 | Prior passing ENG110 grade rejected a repeat Add. | Passed |
| S-F11 | PHYS110 overlapped with an enrolled MATH110 section and was rejected. The Spring schedule search checked lecture and linked lab pairs. | Passed |
| S-F12, S-F13 | Fall 2026 add and drop windows were closed. The registration boundary suite also checked stored window dates. | Passed |
| S-F14, S-F15 | Address request with extra ID and email fields left both immutable. | Passed |
| S-F16, S-F17 | Student grade and attendance POSTs returned 403. | Passed |
| S-F18 | A second user could not read the target student's account, transcript, or audit via a supplied ID, or drop the target student's enrollment. | Passed |
| S-F19–S-F23 | Invalid programs, wrong-level and same-department selections, excess majors, and change/drop of undeclared programs were rejected. | Passed |

## Spring 2027 readiness (original Student fixture)

Before the local fix, Register forced each student's department, including when All Departments was requested. Mathematics student 100014 and the newly created CS student 169833 each had 6 of 16 credits, no holds, and zero addable sections in their own department. All Departments on the patched build returned 8 and 15 eligible sections, respectively, with both schedules still intact. The shared validator allowed English and Economics students to add BIO110 (10579) through Natural Sciences and rejected CS110 for both.

The [seeded-account coverage snapshot](evidence/spring-2027-seeded-coverage.csv) contains 180 synthetic undergraduate profiles. It records current registrations, example compatible additions, and the highest valid load for each student, using the local seat snapshot and lecture and linked lab intervals.

| Outcome | Accounts | Findings |
|---|---:|---|
| Valid path to at least 12 credits | 78 | Checked independently per student at the recorded seat snapshot. |
| Part-time credit cap | 51 | The 8-credit cap governs these profiles. Some also have holds. |
| Full-time with an active hold | 24 | Registration is blocked by the existing hold. |
| Full-time, no hold, no compatible timetable | 21 | Individually eligible distinct courses sum to at least 12 credits, but no combination fits together under the cap. For 100012, four eligible Gen Ed courses share two Monday/Wednesday slots. |
| Full-time, no hold, too few distinct eligible courses | 6 | Eligible distinct courses total below 12 before resolving timetable conflicts. For 100009, six addable CRNs represent just ECON110, HIST110, and PHYS110. Those three courses occupy the same two Monday/Wednesday slots. The highest compatible load is 6 credits. |

The 27 full-time shortfalls are local offering and eligibility findings. Prior passes, existing enrollments, standing, and prerequisites limit which offered courses each student can take. One concrete bottleneck is American Experience: HIST220 is the sole mapped course, with sophomore standing and HIST115 at D or better. Some accounts face both limited options and overlapping meetings. The coverage did not use proposed sections, relaxed holds, changed caps, or new curriculum mappings. Cohort capacity and concurrency remain **Not tested**.

## Outstanding acceptance items

| Item | Owner | Handoff |
|---|---|---|
| UC-3 and UC-23 secure password delivery | Deployment and security | Configure an approved delivery channel to the verified institutional mailbox, independent of portal sign-in. Protect provider credentials and set the authenticated sender and HTTPS link origin. The current code writes to the campus inbox. A demo flag or inbox setting alone supplies no external delivery. |
| UC-3 and UC-23 deployment verification | Student QA and security | In the intended staging environment, verify receipt and successful completion for a locked out test student and an authenticated password change. Also verify incorrect recipient or code, expiry, replay, throttling, safe logs, old-password rejection, and other-session revocation. The local fixture verifies the application workflow under simulated delivery. |
| Active holds and part-time caps | Registrar and student services | Verify the intended enrollment classifications and handle holds through the normal process. The 51 part-time and 24 held full-time profiles are separate from the course and timetable shortfalls. |
| 27 full-time schedule shortfalls | Scheduling, department chairs, and registrar | Inspect the six profiles with too few eligible courses and the 21 with incompatible timetables. Review existing approved courses, alternate meeting times, staffing, and linked labs. The registrar owns approval of any new curricular pathway. |
| Cohort capacity and concurrency | Scheduling and platform QA | Compare forecast demand with usable seats by nonconflicting section. Run the isolated registration concurrency suite before claiming readiness. Both checks are **Not tested**. |
| Audit rules and hosted verification | Registrar and Student QA | Confirm which rules are approved for the intended environment. The local CS fixture is draft. Unconfigured programs show “Rules unavailable.” Preview and actual change were verified locally. |
| Legacy Admin reset test bootstrap | Admin/security test maintainer | The original Student run hit a test-bootstrap error. The fix belongs to [Admin PR #9](https://github.com/Genulty/Draft-Campus-Connect/pull/9). The combined build passed the local security suite with that fix. Standard delivery remains unverified. |

## Branch and integration notes

- [Degree Audit allocation PR #5](https://github.com/Genulty/Draft-Campus-Connect/pull/5), [first-year registration PR #6](https://github.com/Genulty/Draft-Campus-Connect/pull/6), and [Major Change Preview PR #7](https://github.com/Genulty/Draft-Campus-Connect/pull/7) were already merged on GitHub at closeout. No open PR dependency was found.
- The acceptance branch preserves the exact locally tested snapshot. Its parent already contains Major Change Preview. The local registration-default hunk overlaps the merged All Departments, semester-wide totals, and lab support from PR #6. The snapshot also has the Gen Ed column, empty-filter recovery button, and UC-26 drop confirmation.
- `api/actions.php` includes the same program eligibility helper and validator call as the separate unpublished local branch `fix/program-restricted-registration` at `a6d6266`. That behavior is part of the tested Student snapshot. No restriction migration or proposed catalog data is included here. Integrate the eligibility change only once.
- For integration, start from current `origin/main` (observed at `c95c0cf2115628250b043fccf6193a57312aa6e5`), retain its merged Credit Goal, planning and audit work, and apply only the remaining acceptance changes. Rerun the acceptance suite and Spring coverage on that combined build because `main` has newer audit and catalog data. No hosted status is implied by the merge history.

The combined rehearsal used the current catalog and draft audit rule migration.
The CS-to-Economics preview and actual change preserved 59 earned credits and
the transcript. A separate read-only Spring search in that fixture found 85 of
180 students with a compatible load of at least 12 credits, 51 part-time caps,
24 held full-time students, and 20 unheld full-time shortfalls (18 incompatible
timetables and two with too few eligible courses). These are per-student
timetable results. Cohort capacity, official degree applicability, and
registration concurrency remain unverified. Retest acceptance and refresh the
coverage after rebasing the Student changes onto the eventual merged main.

## Reproducible checks

See the [recorded Student browser and API run](evidence/student-acceptance-local.txt). Run `bash tests/student-acceptance.sh` with PHP, MySQL, Node, Playwright, and a Chromium installation. Set `CHROME_PATH` if using system Chrome and `NODE_PATH` if Playwright is installed outside the repository. The harness provisions a fresh socket-only fixture, restricts browser requests to loopback, and removes the fixture on exit.

Also run `bash tests/degree-audit-major-preview.sh`, `php tests/registration-windows.php`, `php tests/registration-locking.php`, `node tests/registration-windows-browser.cjs`, and `node tests/security/run.cjs` after Admin PR #9 is merged. The port 3003 Spring-specific check is `node tests/spring-registration-browser.cjs /private/tmp/campus-audit-preview.EWqreR/mysql.sock` for the current disposable server. `tests/spring-registration-coverage.php` provides the read-only per-student report when pointed to that socket-only database.

## Verified demonstration steps

1. On port 3003 as the existing Mathematics demo student, open Register for Spring 2027. Select Mathematics, then choose **Show all departments**. Show the eligible Gen Ed rows and the same two registrations and 6 of 16 credits.
2. In a disposable account, add BIO110 (10579), refresh, and open My schedule. Show the lecture and lab. On Register, cancel Drop once, then confirm it and refresh to see the section removed.
3. In the local CS demo, open Degree Audit and choose **Preview another major → Economics**. Show the Economics requirements and the same 50 earned credits, then return to the current CS audit. The separate disposable test also verifies the real major change.
