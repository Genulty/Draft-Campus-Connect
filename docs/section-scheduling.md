# Section-creation scheduling validation

Baseline: `f0b5a7caaf9d5fa7381f62ac3f069272b84ae017`, branch `feature/degree-audit-redesign`.

## Scope and verified model

Only new Admin-created sections are validated. No existing course, section, faculty assignment, enrollment, period, time slot, or lab is repaired or migrated.

SHOW/SELECT inspection confirmed:

- `Course.course_Type` is Undergraduate/Graduate; `course_Credits` is a non-null integer constrained to 1–6. Existing combinations are undergraduate 3/4 credits and graduate 3 credits.
- `Time_Slot_Day` links a slot to distinct `Day` records. `Time_Slot_Period` independently links that slot to `Period` records; both relationships use composite primary keys and foreign keys.
- Period start/end values are TIME fields with a positive-duration constraint. Existing periods include 80, 100, 150, and 180 minutes.
- There is no per-day period assignment in these relationships. Each period linked to a slot applies to every day linked to that slot, consistent with existing schedule display/conflict queries.
- Section creation has no proposed lab-record input. This task does not create a lab, infer a lecture/lab arrangement, or change BIO110 CRN 10569.

## Enforcement

`createSection()` loads course type/credits and validates a canonical integer time-slot identifier. The slot must exist. `validateSectionSchedule()` uses SELECT joins to the actual Day/Period records; missing mappings, missing times, and non-positive durations fail with an actionable validation error.

Distinct day IDs and period IDs are counted separately, so repeated query rows cannot inflate weekly frequency. Durations use `TIME_TO_SEC(end_Time) - TIME_TO_SEC(start_Time)`, without rounding seconds to minutes or relying on slot-number ranges.

- Every undergraduate course: at least two distinct days weekly.
- Three-credit undergraduate: exactly two distinct days and exactly one 4,800-second period per day, giving two 80-minute meetings and 160 weekly minutes. Three-day patterns and multiple periods per day fail this specific rule, even if two short periods sum to 80 minutes.
- Four-credit undergraduate: only the two-distinct-day minimum is enforced, in addition to valid relationship data. No 80-, 100-, or lab-duration policy is imposed. Other undergraduate credit values receive only that same confirmed day minimum.
- Graduate: valid relationship data is required, but no universal meeting frequency/duration is invented. Once-weekly 180-minute and twice-weekly patterns remain allowed. Acceptance of other graduate patterns means this validator imposes no unsupported restriction; it does not assert that all possible graduate credit policies have been approved.
- Capacity remains an integer from 5 through 10 inclusive. Department membership, room capacity, faculty load, and faculty/room conflict checks (including existing labs) remain intact.

All failures occur before CRN allocation and the section INSERT. Existing student registration prerequisite, enrollment, credit, seat, and conflict checks were not modified. There are no students assigned to a proposed section at creation time, so no new student-enrollment checks are invented here.

## Admin display

The form explains the same confirmed rules, shows each course's existing type and credits, preserves the existing slot/faculty/room selectors, and displays RuleError messages using the existing API error flash. No shared JavaScript renderer or styles changed.

## Modified files

- `api/actions.php`: course metadata, strict slot input, relationship-based scheduling validator.
- `api/views.php`: course type/credits in options and matching guidance.
- `tests/section-capacity.php`: existing database-free fixture now supplies valid course/slot relationships; all original capacity checks remain.
- `tests/section-scheduling.php`: database-free creation-handler probes and optional SELECT-only existing-data verification.
- `tests/section-scheduling-browser.cjs`: actual Admin renderer with intercepted response fixtures from the real creation handler.
- `docs/section-scheduling.md`: rules, test setup, and limitations.

## Verification

```sh
php tests/section-scheduling.php
# Optional, SELECT-only against existing data; never calls createSection:
DB_USER=root DB_PASSWORD='' php tests/section-scheduling.php --existing-data
node tests/section-scheduling-browser.cjs
```

Browser tests require Playwright and Chromium, or `CHROME_PATH` pointing to Chrome.

The database-free mode uses in-memory SQL adapters and intercepts every INSERT: it does not load `db.php` or connect to MySQL. It covers 79 scheduling/relationship/input/guard scenarios and 12 browser response fixtures, plus the existing 34 invalid capacity payloads, eight valid capacity payloads, and six existing creation guards. Synthetic patterns cover unsupported-day/duration combinations without changing stored records.

The SELECT-only mode compares the actual validation query against independent aggregates for all 53 existing slots and the three stored course-type/credit combinations (159 checks). Observed accepted slot counts: undergraduate 3 credits 28, undergraduate 4 credits 42, graduate 3 credits 53. These counts describe available slots, not newly created sections or certification of unconfirmed policies. BIO110 10569 is read before/after and remains unchanged.

Browser tests simulate API responses generated by the actual handler through those adapters; they do not submit requests to the existing PHP/MySQL application. Real section insertion, new-section concurrency, and triggers are not exercised by these tests.

Executed results: the 79 handler scenarios, 12 response fixtures, 159 stored-data checks, and eight intercepted Admin submissions passed. The browser showed three intended HTTP 422 validation responses, with no JavaScript or unexpected console errors. Existing capacity, dashboard/navigation, registration-window/locking, 38 isolated registration API checks, 240-account Admin lookup, and 240-student Degree Audit regressions passed. Relevant PHP/JavaScript syntax and tracked/new-file whitespace checks passed.

## Limits and unresolved issues

BIO110's exact four-credit lecture/lab frequency, duration, and shared-CRN arrangement remain unresolved. A future multi-component scheduling flow may need clarified rules; no new schema or implied lab is introduced here.

The current model cannot express different periods for different days within one time slot. A three-credit undergraduate slot with multiple period mappings is rejected because it cannot represent exactly the required two meetings. No stored slot relationships were restructured.

Existing historical/noncompliant sections remain unchanged. This change enforces creation-time rules only, not retroactive repairs or protection against direct out-of-application database edits. Existing registration/security deployment limitations remain documented separately.
