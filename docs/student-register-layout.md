# Student Register layout review

Local-only, November 22, 2026. Branch `feature/student-register-layout`, based on
`origin/main` `7eb6afb9e762c54c523e53a3a8d8903a55bd535e` plus the reconciled
Student patch from the post-merge review packet. No commit, push or deployment.

## Before and after

Previously Requirement match followed the course code in a wide raw-field table.
Blocked rows had Try buttons and omitted meeting, seat and prerequisite details.
Now offered sections use six grouped columns: **Course, Meetings, Open seats,
Prerequisites, Requirement match, Registration**. Course includes code, title,
CRN, section, credits and minimum standing; Meetings includes lecture, actual
linked lab and instructor. Both eligible and blocked sections retain those
fields. Requirement match sits immediately beside Prerequisites. My Registration
uses the same grouped course/meeting style, with its confirmed Drop action.

Blocked sections have no action. They show the existing server validator's
reason. If prerequisites are the blocking rule, a **Prerequisites needed** list
shows every missing course's catalog code, title and configured minimum grade.
The existing missing-prerequisite query is shared between validation and this
read-only explanation; its grade predicate, ordering of registration checks,
permissions and locking are unchanged. Direct Add requests are still validated.
The API keeps its flat columns and raw Reason for compatibility; grouping is
Register-only presentation metadata. Admin and Faculty tables are unchanged.

At desktop widths the columns wrap. At tablet widths each section becomes a
labeled two-column row; on mobile it stacks in one column. Keyboard Add and Drop
buttons retain their names and are described by their course cells. Native table
headers and labeled fields remain present. Confirmed catalog-major matches stay
separate from **Draft Gen Ed match** text. No match identified is not a declaration
that a course can never count; unavailable rules remain unavailable.

## Verified locally

- 100001 remains Junior/59 and 100018 Sophomore/50, using merged PR #11. Their
  transcripts and enrollments are unchanged. This demo uses the original seed;
  it does not contain the 181-profile reconstructed histories or change major
  completion percentages to imitate progression-demo results.
- Existing catalog BIO330 CRN 10573 demonstrates two missing prerequisites:
  BIO220 **Cell Biology** and BIO225 **Ecology**, both at D. The browser test
  creates a NEW Biology test profile and copies the existing 100001 posted
  evidence solely to exercise Junior-level prerequisite failure. It does not
  alter 100001 or invent a new catalog prerequisite. This is explicit opt-in
  test evidence, not a representative Biology degree plan.
- Already passed, standing, holds, full sections, conflicts and credit-limit
  reasons agree between displayed restrictions and direct rejected API calls;
  rejected calls preserve enrollment and section seats.
- A newly created full-time Economics student builds **3 → 6 → 9 → 13** credits:
  ENG110 10611 (3), MATH110 10555 (3), ENG115 10612 (3), BIO110 10579 (4, linked
  lab). The browser/API test verifies actual course/meeting data and normal Add
  validation at each step, then adds an eligible 3-credit section to reach 16
  and confirms another add is refused by the unchanged cap. Department switches
  preserve schedule and totals. This is an individual fixture pass, not cohort
  capacity or approved degree-plan verification.
- Requirement-match browser coverage verifies draft and catalog-major matches,
  filter composition/clearing, already completed and same-term suppression,
  and a temporary valid HIST110 alternate that is added normally and removed
  after testing. No proposed section or mapping is added to production data.
- Chromium at 1440, 1024 and 390 pixels has no horizontal document or Register
  card overflow. Desktop and mobile screenshots were inspected.

The final commands and results are in `docs/evidence/student-register-layout-local.txt`.

## Reproduce on a fresh disposable fixture

Requires PHP/PDO/MySQL, Node, Playwright and Chromium. Export NODE_PATH/CHROME_PATH
for external installs, and set DEMO_PASSWORD to the public local fixture login.
On a free local port:

```sh
bash tests/student-review-demo.sh 3020
# In another terminal, use the private socket printed by the launcher:
export REGISTER_BASE=http://127.0.0.1:3020
export REGISTER_EVIDENCE_DIR=/path/to/writable/local/evidence
node tests/register-layout-browser.cjs "$DB_SOCKET"
node tests/registration-requirements-browser.cjs "$DB_SOCKET"
node tests/student-review-integration.cjs "$DB_SOCKET"
```

These tests explicitly reject non-loopback or non-owned Student review databases.
They create disposable accounts; the full-section probe temporarily changes and
restores one seat count. Start with a fresh fixture for repeated test runs rather
than applying them to an existing demo or shared database. No schema migration,
production seed update, progression reconstruction or policy change is required.

## Demo and review scope

The new demo is local `http://localhost:3020/#register?semester=SP27`.
Existing 3017 and 3019 demos and saved patches remain unchanged. As 100018,
select English to show passed-course and Junior-standing restrictions without
Try buttons; select All Departments or Show all departments to browse shared
options. As 100072, draft HIST110 matches retain the existing conflict reason.
The focused multi-prerequisite fixture ID is recorded in the browser results.

Keep this UI follow-up separate from Transcript summary correction, Faculty
used-period cleanup, Degree Audit checklist links, generator changes and the
181-profile data reconstruction. The UI depends on the reconciled Student
requirement-match payload; that patch already incorporates the merged standing
argument at `whyCannotAdd`. A combined Student review PR may contain both layers
as separate review commits; if split, review the reconciled Student base first.
Only new UI hunks and their tests/evidence belong in a standalone UI follow-up.
Asset versions must be reconciled with whichever independent UI fix lands first.

Draft Gen Ed approval, missing program credit/elective rules, unknown degree
applicability and BIO115 sequencing remain academic decisions. No UI label or
eligible Add grants official graduation credit. Secure password delivery, joint
cohort capacity and concurrency remain unverified.
