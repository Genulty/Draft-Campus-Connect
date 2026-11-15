# Degree Audit

The Student portal evaluates the declared major and minor course lists and the
shared undergraduate General Education rules. The results describe individual
course requirements. Graduation eligibility is unavailable.

## Apply the General Education migration

For a clean demo database, load the current `db/Database_schema.sql` and
`db/Project_data.sql` into a disposable database. The schema file recreates the
`campus_connect` database on the selected server. The current seed includes the
November 10 catalog additions.

For an existing teammate database, use the existing schema and verify that its
catalog includes the November 10 department/course additions, including
`HIST110`, `HIST220`, `PHYS110` and `ECON110`. Then apply only this migration
to the intended database.

```sh
mysql -u YOUR_DB_USER -p campus_connect < database/migrations/2026-11-11-add-general-education.sql
```

The migration creates the two Gen Ed tables if needed and seeds seven shared
requirements with eleven mappings at a draft D minimum. A rerun fills missing
seed rows and preserves existing requirement names and mapped minimum grades.

```sql
SELECT r.requirement_ID, r.requirement_Name, m.course_ID, m.min_Grade_Req
FROM Gen_Ed_Requirement r
LEFT JOIN Gen_Ed_Requirement_Course m USING (requirement_ID)
ORDER BY r.requirement_ID, m.course_ID;
```

| Requirement ID | Demo course options |
| --- | --- |
| AMERICAN_EXPERIENCE | HIST220 |
| HUMANITIES | ENG115, ENG220 |
| MATH | MATH110, MATH225 |
| NATURAL_SCIENCES | BIO110, PHYS110 |
| SOCIAL_SCIENCE | ECON110, PSY110 |
| WORLD_HISTORY | HIST110 |
| WRITING | ENG110 |

## Evidence and decisions

- The Student-only endpoint `GET /api/views/degree-audit` binds reads to the
  signed-in student. The session selects the student independently of query
  parameters. A change to a declaration affects the next audit's major list
  while shared undergraduate Gen Ed continues to use the same rules and
  student records.
- Each stored course rule uses the same evaluation for major, minor, and Gen Ed
  courses. A recognized passing transcript grade must meet the stored minimum.
  Each Gen Ed requirement accepts one listed option. The UI shows the matching
  course, grade, credits, term, CRN, and evaluation reason, and lists the
  options still available for requirements awaiting completion.
- All transcript attempts remain visible. Conflicting or missing catalog, section, or term
  records, unsupported grades, and grades for future terms are excluded from
  calculated credit and flagged for review. A current ungraded enrollment is
  in progress. Future registration is shown separately. Past enrollments with
  a missing transcript and posted enrollment grades without transcript history
  need review.
- A passing course contributes its current catalog credits once to the overall
  provisional total, even if repeated or matched against multiple requirements.
  Independent matches describe each rule. They do not establish how credit may
  be applied across programs or toward graduation. Draft Gen Ed is excluded from
  graduation eligibility calculations.
- Program percentages use the stored course list as the denominator.
  A missing mapping or unsupported minimum is reported as Rules unavailable.
  The stored student credit total is displayed separately from calculated credits.
- Diversity and Proficiency evaluate explicit program rules when configured.
  Elective sections use explicit eligibility, targets, and allocation rules.
  Registrar evaluation also requires catalog-year assignment, approved treatment
  of repeats, and explicit transfer, placement, substitution, and waiver records
  where those policies apply.

## Configure the degree credit rules

Apply `database/migrations/2026-11-12-add-degree-credit-requirements.sql` after the
current course catalog. It adds a degree-program table, explicit major-to-program
mappings, and program-specific Liberal Arts course eligibility. Reruns preserve
existing target values and major mappings.

The migration maps `CS-BS` to the `BS-CS` degree program and seeds draft targets of
120 degree credits and 60 Liberal Arts credits. A student's primary major selects
the mapped program. Majors mapped to the same program share its targets. Create
a separate program and map its major IDs to configure a master's target.

The schema has no student catalog-year assignment. The draft targets are
unversioned. A degree total uses distinct passing courses from the validated
transcript and can exceed the target. Liberal Arts uses the same earned courses
filtered by explicit course eligibility, caps the applied total at the target,
and shows eligible current enrollment for planning. A course can appear in
multiple requirement blocks and count once in the overall total.

The local catalog records identify these subjects by name, with short descriptions
that largely repeat the titles. They support a draft classification of clearly
academic subjects. They provide limited evidence about ambiguous, applied, and
professional courses. Those courses, along with graduate courses, remain
unclassified in the seed.

| Subject | Courses included in the draft |
| --- | --- |
| English | ENG110, ENG115, ENG220, ENG225, ENG335, ENG340, ENG445 |
| History | HIST110, HIST115, HIST220, HIST225, HIST330, HIST335 |
| Mathematics | MATH110, MATH115, MATH220, MATH225, MATH330, MATH335, MATH340, MATH445, MATH450 |
| Biology | BIO110, BIO115, BIO220, BIO225, BIO330, BIO335, BIO445, BIO450, BIO455 |
| Chemistry | CHEM110, CHEM115, CHEM220, CHEM225, CHEM330, CHEM335, CHEM445 |
| Physics | PHYS110, PHYS115, PHYS220, PHYS225, PHYS330, PHYS335, PHYS445, PHYS450 |
| Psychology | PSY110, PSY220, PSY225, PSY330, PSY335, PSY340, PSY445 |
| Economics | ECON110, ECON115, ECON220, ECON225, ECON330, ECON335, ECON450 |

Registrar decisions remain open on catalog-year assignments, additional
program targets, borderline courses and the evidence needed to classify them,
repeat and transfer credit, Liberal Arts credit-sharing, and graduation
eligibility. The audit presents these credit calculations as planning evidence.

## Configure Diversity and Proficiency

Apply `database/migrations/2026-11-12-add-diversity-and-proficiency.sql` after the
degree-credit migration. It creates four tables without seeding academic policy
or placement records. Add rules for a mapped program in
`Degree_Audit_Supplemental_Rule` using `DIVERSITY`, `MATH`, or `WRITING`.
Add course IDs and minimum grades in `Degree_Audit_Supplemental_Course`. Keep
`is_Draft` enabled until the applicable rules are approved.

Placement uses a configured assessment code and minimum score for mathematics
or writing. Store student results by assessment and date in
`Student_Placement_Result`. The evaluator uses the most recent result on or
before the audit date for each configured assessment. It accepts placement or
coursework and awards credit only through the existing transcript calculation.
Undergraduates see both sections. Other programs see the sections they configure.

New rules default to exclusive use. Existing matched Major, Minor, Gen Ed, and
Liberal Arts courses are reserved unless the new rule explicitly permits
sharing. Two new rules may share a course only if both permit it. The audit
selects among available alternatives to satisfy as many configured requirements
as possible while keeping the overall earned-credit total deduplicated.

Harvest Hill still needs approved Diversity courses and minimum grades, an
explicit decision about which blocks may share them, and the mathematics and
writing coursework equivalents, placement assessments and thresholds. Confirm
the use of the latest recorded result, including any expiry or retest rules.
The Degree Audit provides no graduation eligibility determination.

## Configure elective rules and local demo data

Apply `database/migrations/2026-11-12-add-elective-rules.sql` after the
degree-credit migration. Configure a program and block in
`Degree_Audit_Elective_Rule`, then explicitly list eligible courses and minimum
grades in `Degree_Audit_Elective_Course`. Major Electives supports a course or
credit target. Free Electives uses a credit target.

The audit first reserves courses matched to Major, Minor and Gen Ed. Liberal Arts
then uses overlapping courses where possible and reserves enough whole courses
to meet its target. Diversity and Proficiency allocations are respected. Major
Electives allocates courses before Free Electives, preferring equally available
options outside the Free Electives list. Each block uses completed courses
before considering current enrollment for planning. A course that crosses a
credit target is allocated as a whole. Sharing requires permission from both
the new block and any earlier supplemental or elective allocation. Course
ordering and whole-course allocation are provisional policy choices.

For a local demo only, apply the supplemental and elective migrations, then
source `tests/fixtures/degree-audit-demo-rules.sql`. The fixture is optional,
draft-labeled, and preserves any existing rule configuration for BS-CS. It adds
no transcript, enrollment, or placement results. Its proposed courses, grade
minimums, placement thresholds, elective targets, and sharing permissions
require approval. The draft Major Electives examples use CS445, CS450, and CS455
with explicit overlap permission so the demo works with the existing local
catalog. Their inclusion and sharing permission require separate approval.

## Run the regression check

```sh
DB_USER=root DB_PASSWORD='' php tests/degree-audit.php
php -l api/degree-audit.php
php -l tests/degree-audit.php
node --check public/login.js
git diff --check
```

The PHP test uses SELECT-only queries against existing data and pure calculation
cases. Set `DB_SOCKET` or `DB_NAME` to point it at a different database. Use a
disposable database to verify migration installation and reruns and to exercise
the portal's major-change workflow. Compare the audit before and after the change
to confirm Gen Ed, transcript evidence, and provisional credits agree.
