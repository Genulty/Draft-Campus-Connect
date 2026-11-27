# Student Register course cards

This stacked application change builds on the current-main Student registration foundation. It carries the final grouped-card implementation, not just the older thirteen-file table redesign. It changes Register presentation and allocation-backed planning labels; no academic rule rows, shared seed, timetable or transcript fixture is included.

A course appears once. Its native expandable summary shows code/title, credits, available section count, primary Applies to label and availability/restriction summary. A mixed course carries both its eligible and blocked sections in the available group; per-section eligibility remains authoritative. Blocked groups start collapsed. Keyboard and touch work with native details/summary controls.

Expanded content shows every configured prerequisite with catalog title, minimum grade and Met/Not met evidence, even when already satisfied. None appears only when no prerequisite is configured. Minimum standing stays visible. Additional possible requirement matches are disclosed only when they add information; primary labels and prerequisite summaries are not repeated. Lecture and Lab meetings use Days, Time and Location labels. Each section retains CRN, instructor, seats and Add only when eligible.

Requirement names mirror Degree Audit: Applies to: Major Requirements, General Education, Liberal Arts Requirement, Major Electives or Free Electives. Optional matches reuse the actual allocation helpers and reserve current/future coursework before recommending another course. Sharing/target exclusions and missing rules are preserved. Provisional is a keyboard-focusable disclosure; no draft rule is promoted to official graduation policy. Requirement match unavailable does not mean a course is academically useless.

Restrictions use concise summaries and preserve full explanations, all missing prerequisite codes/titles/grades and conflicting sections. Already completed is the primary useful summary for BIO115/B, while any actual explicit access restriction remains in details. Requirement membership alone does not create an exclusive-major restriction. Existing explicit catalog restrictions still win in list/submit validation. No Try buttons appear on blocked sections.

Department and Courses for my degree filters compose without changing My Registration or credit totals. Add/Drop windows, holds, caps, earned-credit standing, prerequisites, linked labs, seats, conflicts and server-side permissions remain unchanged. The work does not activate BIO110→BIO115, HIST115→World History, new degree policies or section proposals.

## Reviewer setup

Use your own machine and a free port with the documented PHP/PDO MySQL/MySQL/Node/Playwright/Chromium dependencies:

```sh
bash tests/student-review-demo.sh 3041
# In another terminal, export the private DB_SOCKET printed by the launcher.
# Set DEMO_PASSWORD to the existing public local fixture login.
export REGISTER_BASE=http://127.0.0.1:3041
node tests/registration-requirements-browser.cjs "$DB_SOCKET"
node tests/course-access-and-groups-browser.cjs "$DB_SOCKET"
node tests/register-polish-browser.cjs "$DB_SOCKET"
node tests/register-card-hierarchy-browser.cjs "$DB_SOCKET"
node tests/register-terminology-browser.cjs "$DB_SOCKET"
```

The launcher uses current-main seed data and existing setup migrations only. It does not load the twelve proposed prerequisites, HIST115 mapping, seven/eleven sections or reconstructed histories. Private access-rule rows used by the regression suite are test fixtures only and are removed; tests do not approve catalog restrictions. If an academic policy is missing, the UI reports the configuration it actually finds.

## Short walkthrough

1. Student 100001: Register → Spring 2027 → All Departments. Expand BIO115: Already completed — B is primary. BIO220 still shows configured BIO115/D/Met. BIO115 has no configured prerequisite in the ordinary main fixture; the separate C-minimum proposal is not part of this PR.
2. Inspect HIST110: its provisional World History match is distinct from section conflicts. Toggle Courses for my degree and Department; My Registration and credit totals remain unchanged.
3. Compare a mixed full/open or conflict/open course. Only eligible section rows have Add. View the complete explanation for a blocked row. Use keyboard expansion and a 390px viewport; no horizontal page scrolling is required.
4. Run the browser/API suites for valid Add, persistence, Drop confirmation, full-time progression, cap refusal, explicit private major restrictions, same-session double-major access and cross-major shared coursework.

Thirty final current-main checks passed on the exact application/test content in this review, covering Student, Admin, Faculty, Degree Audit, standing, scheduling, security and the focused card regressions. This is local verification, not a full WCAG certification, hosted-site check, school-wide Spring launch claim or secure password-delivery certification. Password standard delivery remains blocked/unverified. Academic proposals and cohort/concurrency analyses remain separate.
