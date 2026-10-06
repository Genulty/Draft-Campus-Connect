# Campus Connect (Draft)

CS5910 System Design and Implementation — Julio Larrea, Muiz M. Onifade, Pavel Clarke.

Contains the database schema and a working login screen.

## Run it

Requires Node.js 18 or newer.

```bash
npm install
npm start        # http://localhost:3000
```

To put it online on Amazon Lightsail, see [DEPLOY-LIGHTSAIL.md](DEPLOY-LIGHTSAIL.md).

The first start creates `campus.db` from `db/Database_schema.sql` and adds two test accounts.
`npm run reset-db` deletes the database and starts fresh.

## Test accounts (password `Campus123!`)

| Role | Email |
|---|---|
| Student | student@campus.edu |
| Faculty | faculty@campus.edu |

## Files

| Path | What it is |
|---|---|
| `db/Database_schema.sql` | Database schema (all tables) |
| `server/db.js` | Creates the database and the test accounts |
| `server/index.js` | Login / logout server |
| `public/` | Login page |
| `deploy/lightsail-setup.sh` | One-step setup for an Ubuntu server (Lightsail) |
