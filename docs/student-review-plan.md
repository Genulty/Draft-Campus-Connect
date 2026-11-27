# Student registration application review

This replaces the earlier pre-merge review plan. The application review is based on current main a9725667d664efba5cb4c6ed69128e45bccbeecd, which includes Faculty #8, Admin #9, Create Section #10, earned-credit standing #11, and the teammate statistics/past-grade updates. Those changes are reused, not duplicated.

Review the Student foundation first, then the stacked grouped-course-card PR. The foundation preserves the existing table presentation while adding signed-in audit matching, composable filters, department recovery and Drop confirmation. It corrects the assumption that a required-course mapping alone authorizes exclusive access: only explicit catalog program restrictions restrict programs; prerequisites, standing, seats, holds, conflicts, caps and locked submit validation remain enforced. No restriction policy rows are created.

The grouped-card follow-up contains course/section presentation, complete configured prerequisite evidence, concise prioritized restriction summaries with all explanations retained, allocation-backed optional matches, canonical Applies to terminology, Provisional disclosures, mobile layout and keyboard interactions. It depends on the foundation. It does not require academic proposal data.

The historical thirteen-file UI patch remains preserved separately and predates later grouping/terminology polish. Do not publish it as the whole latest implementation. Existing PRs #12/#13 document the earlier revisions; the replacement reviews reconcile current main without rewriting those branches.

All local-only prerequisite SQL, HIST115 Gen Ed proposal, seven/eleven section packages, Riley/progression reconstruction, Faculty period cleanup, Degree Audit navigation and Transcript summary changes are excluded. Existing draft rules remain visibly provisional and missing rules remain unavailable. No new schema migration or production/shared seed change is included. A schema-only optional restriction fixture is test-only where needed.

Standard password delivery UC-3/UC-23 remains Blocked; the local recovery fixture verifies the flow only. Schoolwide degree applicability, approved curricula, staffing, cohort forecasts and deployment readiness are separate decisions. Native concurrency evidence remains in its separate local packet; this review does not claim production concurrency certification.

Historical Student acceptance evidence is retained with its original scope. Final current-main publication results are recorded in docs/evidence/student-registration-publication.txt. No merge or deployment is authorized by these PRs.
