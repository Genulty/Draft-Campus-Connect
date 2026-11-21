# Admin Access Levels Acceptance

Scope is Professor Gupta's two access levels, SRS A-R2–A-R4 and A-F2, and detailed Admin use cases 45–47. Tested on `fix/admin-access-levels` from `origin/main` at `c95c0cf2115628250b043fccf6193a57312aa6e5`. The [local Admin demo](http://127.0.0.1:3005/) uses a disposable database over a Unix socket with networking disabled. The fixture clock is November 16, 2026, inside the existing Spring 2027 add window. Session and academic-write regression fixtures were isolated from the running demo.

## Acceptance checklist

| Requirement | Result | Evidence |
|---|---|---|
| A-R2 Recognize both security levels | Passed | Seeded Admin 300003 is Read-Only. Admin 300001 is Read-Write. The same role navigation and level-specific notices appeared. |
| A-R3 and UC-45 Read-Only views authorized records | Passed | Chromium looked up a user, department, course section, and student schedule. Existing records matched the Read-Write views. Read-Only registration lookup preserved the student while filtering course sections. |
| A-R4 and UC-46 Read-Write performs authorized changes | Passed | The valid user update persisted in Chromium after refresh and was logged. Create department, place/remove hold, register an eligible student, and cancel an under-enrolled section succeeded in the fixture. |
| A-F2 and UC-47 Reject unauthorized changes | Passed | All 12 Admin-only routes rejected Read-Only, Student, Faculty, and Statistics accounts with 403 and anonymous callers with 401. The database fingerprint, including enrollment, seat availability, resets, and audit records, remained identical after the denied requests. |
| A-R6, A-R43, A-R44 Update user and audit it | Passed | The same valid address payload was refused for Read-Only and accepted for Read-Write. The saved street was present after refresh. The audit entry identified the acting Admin and target user. |
| A-R35, A-R46, A-R47 Registration and cancellation | Passed locally | Read-Write still obeyed the hold check, then registered the student after the hold was removed. Cancellation removed one low-enrollment section and produced one internal notice. External notification delivery was not tested. |
| Admin password reset authorization | Passed locally | Read-Only received 403; Read-Write reached the standard delivery-disabled guard. Legacy fixture tested reset authorization and its local audit record. Secure password delivery remains unverified. |

## Institutional write inventory

| Admin action | Server before change | Read-only display after change |
|---|---|---|
| create-course | Router and handler checked update permission. | Existing notice, no form. Catalog remains visible. |
| create-section | Router and handler checked update permission. | Existing recent section results, no create form. |
| create-user | Router and handler checked update permission. | Existing created-account results, no create form. |
| send-password-reset | Router and handler checked update permission. | Existing recent reset metadata, no send form. |
| upsert-department | Router checked update permission; form offered an enabled submit. | Department records and lookup stay usable. Write fields and submit are disabled. |
| reassign-faculty, update-section-time-slot | Router checked update permission; both forms offered enabled submits. | Section lookup stays usable. Write fields and submits are disabled. |
| cancel-section | Router checked update permission. | Existing section list and disabled cancellation control. |
| register-student | The router explicitly exempted this route. Read-Only registration returned 200 and inserted enrollment in a baseline disposable fixture. | A GET lookup displays the schedule and course sections. Only Read-Write receives the registration form. |
| place-hold, remove-hold | Router checked update permission. Place form was enabled; Remove was already disabled. | Active holds remain visible. Both write controls are disabled. |
| update-user-info | Router checked update permission; form offered an enabled submit. | User search stays usable, record fields remain visible, and editing and submit are disabled. |

All Admin-only actions now pass the same update-level gate before handler dispatch. Login, logout, and user-scoped own-account actions keep their existing rules. The Admin access level does not grant Student or Faculty actions. Existing registration eligibility, hold, cancellation, and record-visibility rules were preserved. The Create a Course workflow and password delivery were outside this change.

## Legacy regression

The first run of `node tests/security/run.cjs` stopped in the Admin reset case because `tests/security/bootstrap.php` loaded mail without the `api/actions.php` definition of `adminCanUpdate()`. The application entry point already loads it. After restoring parity in the bootstrap, a stale assertion expected five failed logins while the application uses `MAX_ATTEMPTS` (three). The test now checks the current constant. The complete native and HTTP security fixture then passed. These changes are test-only and verify local behavior.

[Recorded test evidence](evidence/admin-access-local.txt)

## Local demo

1. Sign in on port 3005 as **Admin 300003**. Open **Update user info**, enter **100014**, and click **Find**. The record and its values display. **Save changes** is disabled.
2. Sign out and sign in as **Admin 300001**. Open the same record. Enter a fixture street address and click **Save changes**. Refresh to verify the persisted address. **Audit log** shows the edit.
3. For the registration contrast, open **Register a student**. Both roles can find student 100014 and browse Spring 2027 sections. Read-Write also sees the Register form.

Run `bash tests/admin-access.sh` for a fresh isolated Chromium and API fixture. The runner generates database and test-account credentials for each run. Set `CHROME_PATH` or `NODE_PATH` if required locally. Use `--serve 3005` to start a separate demo. Workflows outside these two access-level requirements have no new acceptance result here.
