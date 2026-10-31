# Registration concurrency — unresolved security/data-integrity risk

Status: the Student-row locking correction is implemented, but the MySQL 8.4
concurrency suite is PREPARED — NOT EXECUTED. No real InnoDB concurrency tests have
been run in any database. Execution remains pending explicit approval and Docker
availability. No concurrent registration writes have been run against campus_connect.
The security/data-integrity finding remains UNRESOLVED; control-flow probes and
static safety checks are not proof of database serialization or race prevention.

## Read-only findings

Inspected baseline: c3ae4d5193a449c950731092dc772c2468949f0e on
feature/degree-audit-redesign. Previously inspected local metadata reports InnoDB and REPEATABLE READ.
Enrollment has a (student_ID, CRN) primary key, not a unique constraint on student,
course and semester. Credit totals and overlaps are cross-row application rules.

Originally, addSection began a transaction and checkAdd locked a joined
Course_Section/Course/Semester result before its ordinary reads of the student's
classification, holds, enrollments, credits and schedule. Drop used its own joined
locking read. No explicit Student mutex protected the entire student's enrollment
set across the two writers.

Important qualification: unqualified FOR UPDATE on the current join can also lock
Course/Semester records. These incidental shared-row locks may already serialize
some requests (and create broader contention). A read-only EXPLAIN does not prove
actual lock ownership/waits. No bypass of the original live implementation was
established. The original audit finding remains an unresolved design/race risk,
not a claim that an over-limit/duplicate/conflicting enrollment was reproduced.

PHP session-file locking may serialize requests sharing one session cookie; it
does not protect a student with multiple independently authenticated sessions.
A single-worker PHP development server also cannot prove database concurrency.

## Correction and locking contract

- Both enrollment mutation paths now acquire SELECT student_ID FROM Student WHERE
  student_ID = ? FOR UPDATE inside the existing transaction, before any ordinary
  read. It uses the existing primary key; no DDL or index changes are needed.
- Add keeps its original joined section locks, including last-seat protection.
  Drop keeps its original enrollment/section locking read. Previews acquire no
  registration locks and remain advisory; the actual add request revalidates.
- The order is Student first, then existing section/enrollment locks. The guard
  refuses use outside a transaction and rejects a missing student before writes.
- The first locking read does not create a consistent-read snapshot. Because
  transaction() starts a fresh transaction and performs no earlier SELECT, a
  waiting add sees the prior writer's committed enrollments when its first
  ordinary read runs under REPEATABLE READ. READ COMMITTED must also be tested.
- Commit or rollback releases the mutex. Future enrollment writers must follow
  this protocol, and must not establish a read view before waiting for it.
- Existing program-change paths already lock Student first. Direct SQL, future
  admin enrollment handlers, simultaneous hold/classification/catalog edits and
  nonparticipating writers are not certified by this change.

No approved schema, stored records, scheduling, grading, capacity rules, or generic
transaction helper was changed. Incidental broad joined locks are retained; this
patch does not promise improved parallel throughput. Deadlocks/lock timeouts must
fail closed; user-facing retries and global transaction-error handling remain
separate follow-up concerns, not automatically solved by the mutex.

## Tests that are safe without a database

Run `php tests/registration-locking.php`. It invokes the real add/drop/preview
functions with a database-free query adapter that stops at the existing lookup.
It checks transaction scope, Student-first query order, bound IDs, retained locking
lookups, missing-student rejection and unlocked previews. It does not simulate or
prove InnoDB row locks or final concurrent enrollment invariants.

Existing SELECT-only regression suites:

- `DB_USER=root DB_PASSWORD='' php tests/degree-audit.php`
- `DB_USER=root DB_PASSWORD='' php tests/admin-student-accounts.php`

These never invoke add/drop or create database fixtures.

## Prepared write-capable isolated suite — DO NOT RUN without approval

Files: tests/registration-concurrency/{compose.yaml,Dockerfile,bootstrap.php,
worker.php,run.php}. Execution will download/build images, create tables, insert
fictional fixtures and run registration writes only in a disposable database.
This is intentionally a separate opt-in suite, not a migration or startup hook.

Safety barriers:

- The runner refuses execution outside Docker or without its explicit disposable
  test acknowledgment. It does not load the host's application configuration.
- Host, database and fixture user are hard-wired. Generic DB_HOST/DB_NAME variables
  are overridden. The actual database name AND server hostname must match before
  any writes or session settings. campus_connect is never a permitted target.
- MySQL runs on an internal Docker network, with no published port, host network,
  host database socket or persistent database volume. Its data directory is tmpfs.
- The repository is mounted read-only. Only the empty disposable schema is used;
  a nonempty schema is refused rather than overwritten.
- Only CREATE TABLE/CREATE TRIGGER definitions are extracted from the current
  schema source, with parser-count checks. DROP DATABASE, CREATE DATABASE, USE,
  Project_data.sql, migrations and real user records are never loaded.
- Fixtures test registration invariants, not approval of academic or lab policies.
  They do not include BIO110 10569 or any copy of actual student data.
- Workers use the actual api/db.php transaction helper and api/actions.php
  registration functions with independent PDO connections, not HTTP requests
  through a serial development server or shared PHP session.

The prepared coordinator is designed to wait for worker A to acquire the Student
(or last-seat section) lock, start B, and check that B cannot report acquiring the
held row lock while A is paused. After release, it is designed to check actual
transaction results and database invariants. The short observation interval supplements the explicit barrier;
engine/runtime execution is still required to validate it.

Each scenario is prepared for REPEATABLE READ and READ COMMITTED (18 cases):

| Scenario | Required result |
| --- | --- |
| Same student, two adds starting from 12 credits | First reaches 15; second rejects 18 > 16 |
| Same student, two CRNs of one course | Only one enrollment; second rejects duplicate |
| Different slot IDs with overlapping actual times | Only first enrollment; second rejects overlap |
| Same course across two open semesters | Second detects existing ungraded registration |
| Forced exception after first INSERT | Enrollment and seat-trigger effects roll back; waiting add succeeds |
| Drop followed by competing add | Add sees the committed drop and recalculates credits |
| Different students competing for the last seat | Exactly one success; seats remain accurate and nonnegative |
| Different students using different sections | Second Student mutex can proceed while only first Student mutex is held |
| Forced lock-wait timeout | Contender fails closed without persisting enrollment; transaction closes |

The prepared suite is designed to check credit ceilings, duplicate-course rows,
actual-time overlaps and seat counts after every scenario; none of these InnoDB
concurrency results has been obtained yet. It has bounded waits and cancels worker pipes
on failure. It does not yet cover concurrent hold edits, bulk/admin/direct SQL,
server crashes, forced deadlocks, full HTTP/session behavior, or lab-only races.

After approval and Docker availability, the intended commands are:

```sh
docker compose -f tests/registration-concurrency/compose.yaml up --build --abort-on-container-exit --exit-code-from tests
docker compose -f tests/registration-concurrency/compose.yaml down
```

Do not substitute a live DSN or import the fixture runner into application startup.
The prepared suite targets a disposable MySQL 8.4 image, but it has NOT been
executed. There are no MySQL 8.4 concurrency results to report. After an approved
isolated run, results and deployment engine/version compatibility must be reviewed
before declaring the risk resolved.

## Preservation review — October 31, 2026

All five relevant PHP files passed syntax checks. The Compose YAML was parsed
with Ruby's YAML library; its internal network, absence of host ports/network or
socket mounts, tmpfs database, fixed test identity, explicit opt-in and read-only
repository mount were verified statically. These checks do not validate running
Docker containers or InnoDB locking. The application patch and test source files
were preserved unchanged during this review; only this documentation was corrected.
No Docker build/container, coordinator, worker, MySQL connection, data write or
schema change was executed as part of this preservation review.
