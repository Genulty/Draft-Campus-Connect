// Registration API checks require genuine credential-bound sessions after session hardening.
// The shared runner creates a fresh native MySQL server with a private socket and
// disposable accounts. It never uses the application's existing campus_connect DB.
const { spawnSync } = require('node:child_process');
const path = require('node:path');
const result = spawnSync(process.execPath, [path.join(__dirname, 'security/run.cjs'), '--registration-only'], { stdio: 'inherit' });
if (result.error) throw result.error;
process.exitCode = result.status ?? 1;
