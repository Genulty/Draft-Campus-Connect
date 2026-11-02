# Password recovery and session security review

Repository baseline: `398e7f20d750c89531ae55759d25c3a3d396c90a`, branch `feature/degree-audit-redesign`.
This report describes the security changes prepared from that baseline. No deployment or changes to existing account data were performed.

## Exact files changed

- `api/accounts.php`: serialized login checks/counters and credential-bound session establishment; removed obsolete DOB-mail verifier.
- `api/db.php`: configurable Unix socket, retaining the existing default.
- `api/index.php`: per-request authentication revalidation, strict session settings, sanitized exception logging.
- `api/mail.php`: durable reset attempts, account-first locking, guarded single-use completion, fail-closed delivery, owner-only demo mail, production redaction.
- `api/security.php` (new): local-demo gate, session fingerprint validation/revocation, reset account lock helper.
- `api/views.php`: accurate unavailable-delivery notices and exhausted-grant filtering.
- `public/index.html`: recovery delivery information replaces ID/DOB mailbox access.
- `public/login.js`: removes unsafe mailbox submission and handles identity-free grants/sign-out after password change.
- `tests/registration-windows-api.cjs`: delegates to genuine credential-bound isolated fixtures.
- `tests/security/bootstrap.php` (new): disposable-server identity guard.
- `tests/security/run.cjs` (new): native MySQL/PHP server lifecycle and HTTP checks.
- `tests/security/suite.php` (new): InnoDB behavior/concurrency and session tests.
- `tests/security/worker.php` (new): simultaneous independent PHP requests.
- `tests/security/schema.sql` (new): existing definitions for disposable fixtures only; not a migration.
- `tests/security/browser.cjs` (new): simulated recovery UI checks.
- `docs/security-recovery-session.md` (new): findings, verified results, limits, and deployment prerequisites.

## Verified existing schema and previous behavior

SHOW inspected the existing `Login`, `User`, `Admin`, `Password_Reset`, `Email_Message`, and `Audit_Log` definitions. No columns or tables were inferred or added to the application database.

- `Login`: `user_ID`, `user_Email`, `user_Password`, `user_Type`, `no_Of_Tries`, `lock_Var`. There is no disabled-account flag or session version.
- `Password_Reset`: `reset_ID`, `user_ID`, `purpose`, `requested_By`, `code_Hash`, `token_Hash`, `attempts`, `created_At`, `expires_At`, `used_At`. InnoDB; unique `token_Hash`, foreign keys to User/Admin.
- `Email_Message`: owner, recipient, subject, body, sent/read timestamps. Recovery messages contain plaintext bearer credentials in the body.
- Codes: cryptographically random six-digit strings, including leading zeros, hashed with `password_hash(PASSWORD_DEFAULT)`.
- Link tokens: 32 random bytes represented as 64 hexadecimal characters; SHA-256 digest stored in `Password_Reset`.
- Lifetime: 15 minutes using database timestamps; valid only while `expires_At > NOW()` and `used_At IS NULL`. A completed password change consumes the request.
- Issuance expires older unused requests. Previously it did not serialize on an existing account row, allowing overlapping issuances.
- Previously a wrong-code UPDATE and `fail()` occurred in the same transaction. The transaction helper rolled back on the resulting RuleError, undoing the attempts increment.
- Previously unauthenticated `/api/demo-mail` returned mailbox contents based on ID and DOB. Its file-based attempt counter did not make that proof of ownership.
- Previously sign-in regenerated the session ID, but subsequent protected requests trusted the cached identity. Password changes and later account locks did not revoke those sessions. Login failure counters also used an unlocked read/modify/write sequence.

The transaction behavior follows the [PHP PDO transaction documentation](https://www.php.net/manual/en/pdo.transactions.php); session hardening follows [PHP session security guidance](https://www.php.net/manual/en/features.session.security.management.php).

## Implemented behavior

### Attempts and reset lifecycle

All reset writers lock the existing `Login` row first, then the latest active `Password_Reset` row. The order is shared by issuance, code/link verification, and completion. Sign-in also locks its account row while checking and updating counters.

A failed code verification increments `attempts` under the lock and returns an error marker from the transaction. The transaction commits before the RuleError is thrown to produce HTTP 422. At five attempts, codes, links, and prior verified grants are refused. Malformed codes count as failures against an active request; non-string, short, whitespace-suffixed, and malformed token inputs are rejected safely.

Completion rechecks account ownership, the latest request ID, expiration, usage, and attempts under locks. It atomically changes the fixture/user password, resets the original account lock/counter as the existing recovery workflow did, marks the request used, expires any remaining requests, and records a notification. These write paths were exercised only in the disposable test database, never against existing accounts.

Verification and completion errors do not return codes, tokens, or account email addresses. Public request messages in demo mode do not distinguish unknown from ineligible accounts. Exception logs contain the exception class, not messages, stack arguments, request bodies, credentials, or tokens.

### Recovery delivery is disabled by default

There is no configured secure external mailbox delivery. Public forgot-password, code/link verification, completion, own-password change, and Admin reset creation therefore fail closed in normal/production mode. Account/Admin pages explain that delivery is unavailable. No third-party provider was introduced.

An explicit local demonstration requires **both** `APP_ENV=development` and `CAMPUS_DEMO_RECOVERY=1`, and either CLI execution or a loopback client on PHP's built-in server. PHP-FPM/production requests do not gain demo access. Keep any demonstration server bound to loopback; do not put it behind a publicly reachable reverse proxy with development flags.

In that mode, a currently authenticated, unlocked account may read only its own inbox. ID/DOB never authorize mailbox access. The signed-out ID/DOB form and old file-based verifier were removed. A visitor who has neither the password nor an existing authenticated session cannot use this demo inbox as real password recovery; a local operator or an approved external delivery mechanism is needed. Do not enable weak mailbox access to work around that limitation.

Production inbox output redacts legacy verification-message bodies. Existing stored messages and reset records were not deleted or altered. Recovery delivery must be redesigned and approved before enabling an external production recovery channel; merely setting a development flag is not a production solution.

### Sessions

Successful login stores only a server-side SHA-256 fingerprint of the existing password hash alongside the identity. It is never returned to clients. Every protected request rechecks:

1. A credential-bound session is present.
2. The Login/User records still exist.
3. `lock_Var` is false.
4. Both stored account roles match the session role.
5. The password-hash fingerprint still matches.

A password change invalidates all other sessions at their next protected request. Locking, deletion, and role changes also reject the old session. Old pre-change sessions without a credential binding must sign in again. The completing browser is signed out after the transaction commits.

Session IDs regenerate after sign-in, verified recovery grants, completion, and rejection of obsolete authentication. Strict session mode, cookie-only IDs, and disabled URL session IDs supplement existing HttpOnly/SameSite/HTTPS-cookie settings. Existing route role allowlists remain server-side.

Limitations: there is no separate disabled-account status or general global-revoke generation in the existing schema. Already in-flight requests cannot be cancelled by next-request validation. Unlocking an account without changing its password cannot invalidate a session that never made a request during the locked interval. No new status/version fields or filesystem-wide session scans were added.

`api/db.php` now accepts an explicit Unix socket from config or `DB_SOCKET`, retaining `/tmp/mysql.sock` as the default. This lets isolated tests use the actual application connection/transaction code without ever connecting to the normal socket.

## Executed tests

`node tests/security/run.cjs` was executed on the available native **MySQL 26.10.0** / InnoDB binary, not MySQL 8.4. It creates a fresh temporary data directory, disables database networking and binary logging, uses a unique Unix socket and server ID, and removes its own server/data after completion.

Before write-capable PHP tests run, the harness verifies the manifest, socket, database name (`campus_security_test`), actual server data directory and server ID, and absence of any `campus_connect` database. It never accepts the ordinary application configuration, TCP database host, normal socket, or an existing data directory. `schema.sql` contains SHOW-only copies of six existing table definitions, used exclusively by this runner; it is not an application migration. Fictional users/passwords are created only inside this disposable server.

Passed:

- Failed-attempt durability after exceptions; exactly five counted failures.
- Malformed, expired (including the expiration boundary), used, and superseded requests.
- Correct codes and links, regeneration at verification, single-use completion, cross-account grant refusal, and exhaustion after a valid grant.
- **12 simultaneous wrong-code verifications**: all denied and attempts stop at five.
- **Six simultaneous reset issuances**: exactly one active request remains.
- **Two simultaneous password completions**: exactly one succeeds.
- **Eight simultaneous failed sign-ins**: five failures persist and the account locks.
- Locked, deleted-account, changed-role, legacy-unbound, and password-changed session rejection.
- Production shutdown even when the demo flag alone is present; legacy mail redaction.
- Local demo anonymous/cross-account inbox refusal and authenticated owner access.
- Read-Only Admin refusal and Read-Write Admin reset/audit behavior.
- Actual HTTP login, role boundaries, strict session regeneration, password-change revocation across two browsers' cookies, locked-session rejection, generic recovery errors, and absence of secrets in captured server logs.
- **38 registration-router cases** (401/403/422) using genuine credential-bound disposable sessions. `tests/registration-windows-api.cjs` now delegates to the guarded runner with `--registration-only`; it no longer fabricates unbound sessions or uses an unreachable database as a substitute for authentication checks.

`tests/security/browser.cjs` passed with actual HTML/JS/CSS and simulated API responses: no ID/DOB mailbox, clear disabled-delivery errors, identity-free recovery links, return to sign-in after completion, and no console errors.

Existing regression results: 240-student Admin lookup and Degree Audit SELECT-only suites; 360 faculty/semester lab checks; registration window/locking, capacity, and dashboard filter checks. Browser regressions passed for dashboard restoration, Major Course Browser ordering, registration windows, and capacity. No write-capable test targeted the existing database.

PHP/JavaScript syntax and tracked/untracked Git whitespace checks are required for final review.

### Re-running safely

Requires local `mysqld`, PHP with PDO MySQL, and Node. `MYSQLD_BIN` may select another binary, but the runner always initializes its own temporary directory and private socket. Do not run the fixture SQL manually.

```sh
node tests/security/run.cjs
node tests/registration-windows-api.cjs
node tests/security/browser.cjs
```

Browser tests require Playwright and an installed Chromium, or `CHROME_PATH` pointing to Chrome. The native suite does not require Docker or credentials for existing MySQL. Bootstrap and worker scripts are intentionally not usable without the runner's disposable-server identity checks.

## Not executed / remaining risks

- MySQL 8.4 concurrency verification was **not executed**; no Docker runtime is installed. Native InnoDB concurrency results above are actual executed tests on 26.10.0.
- No production/AWS/real-mail-provider tests, existing-account sign-ins, password resets, counter changes, locks/unlocks, or write-capable authentication tests against `campus_connect` were performed.
- The previously documented registration concurrency risk remains unresolved; its separate MySQL 8.4 registration suite was not executed by this task.
- Existing shared demo passwords and public demo sign-in affordances remain an **unresolved deployment blocker** until an approved credential-rotation plan exists. No existing password was rotated.
- No disabled-account field exists; no such field was invented. Password/lock-based next-request revocation has the limitations listed above.
- New public recovery delivery would also need approved transport, issuance/abuse throttling, monitoring, and production integration testing. Those capabilities are not claimed by this local demo.

## Deployment prerequisites (no deployment performed)

1. Review the implementation and execute the security tests on the intended deployment MySQL/PHP versions.
2. Approve a credential-rotation plan for existing shared demo passwords; address demo-login exposure before a public deployment.
3. Keep development recovery flags off in production. Approve and implement secure recovery delivery before promising password recovery or unlocking locked accounts remotely; otherwise explicitly retain recovery unavailability.
4. Enforce HTTPS, trusted reverse-proxy forwarding settings, and protected session storage. Supply approved database credentials through the protected external configuration; never use the existing local `campus`/`campus` fallback in production. No database/AWS credentials were rotated or changed.
5. Plan for all existing sessions to require fresh sign-in after this change. Password changes subsequently revoke sessions on their next protected request.
6. No application schema migration or data repair is required or included. Never load `tests/security/schema.sql` into the existing database.
