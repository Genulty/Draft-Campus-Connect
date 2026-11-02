# Scheduling records and verification

The three October 28-29 scheduling correction files are historical records of
changes already persisted in the local development database. They are deliberately
comment-only SQL: sourcing them cannot repeat an update, insert IDs, or start or
commit a transaction. Original SQL is preserved line for line after the `-- |`
prefix, with its SHA-256 recorded in each file. The prefixes are an execution
barrier, not instructions to remove them.

## Verified local state on October 29, 2026

- FA26 undergraduate three-credit correction: 57/57 sections meet twice weekly
  for 80 minutes per meeting.
- FA26 graduate correction: five target CRNs use slots 49-53, one 180-minute
  meeting per week.
- SP27 undergraduate three-credit correction: all 13 target CRNs match the
  recorded assignments; all 66 three-credit undergraduate sections meet 2 x 80.
- Independent MySQL connections observed these values with InnoDB and REPEATABLE
  READ isolation; no open InnoDB transactions were reported.
- Faculty, room, and student primary-section conflict checks returned zero for
  FA26 and SP27. The scripts below do not include separate lab meetings.
- These observations establish local persistence, not an AWS deployment or
  approval to apply the same changes to another database.

## Read-only verification files

- `verify-spring-2027-final.sql`: faculty, room, and student overlaps among all
  primary section meetings in SP27, using actual day/time intervals.
- `verify-graduate-schedule-conflicts.sql`: equivalent checks for all FA26 primary
  section meetings, not only graduate sections; excludes separate lab meetings.
- `verify-spring-2027-schedule.sql`: historical proposed-assignment check only
  between the 13 selected sections. It does not check unselected sections or
  student conflicts and is not sufficient for full validation.

These three files contain SELECT/WITH queries only. No application bootstrap or
active Git hook was found that runs the new files automatically. Existing older
migration files elsewhere in this directory are outside this archive and must
not be batch-executed. Never run all SQL files as a directory-wide migration.
Any future database repair requires a reviewed plan and explicit authorization.

## Work intentionally unresolved

The October 19 44-table PDF is the documented baseline; explicit Professor Gupta
approval is unverified. The working database contains 47 tables, including
Course_Section_Lab, Email_Message, and Password_Reset. Approval for those three
additions is unconfirmed. Preserve all existing tables and data; do not infer
schema approval from the current application or these records.

SP27 BIO110, CRN 10569, remains slot 17 with no separate lab assignment. Its exact
four-credit lecture/lab durations, frequency, and CRN arrangement are unconfirmed.
Do not change it based on generator comments or other sections' schedules.

The Degree Audit credit targets and General Education/Liberal Arts/elective
classifications remain explicitly provisional. Saving them in Git does not
establish final degree requirements or graduation eligibility.
