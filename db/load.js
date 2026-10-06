// Creates the campus_connect database from Database_schema.sql and loads Project_data.sql.
// This DROPS and recreates the database.   npm run db:setup
const fs = require('fs');
const path = require('path');
const mysql = require('mysql2/promise');
const { config } = require('../server/db');

(async () => {
  const conn = await mysql.createConnection({ ...config, database: undefined, multipleStatements: true });
  for (const file of ['Database_schema.sql', 'Project_data.sql']) {
    process.stdout.write(`Running db/${file} ... `);
    await conn.query(fs.readFileSync(path.join(__dirname, file), 'utf8'));
    console.log('done');
  }
  const [rows] = await conn.query(`SELECT table_name AS t, table_rows AS n FROM information_schema.tables
    WHERE table_schema = 'campus_connect' ORDER BY table_name`);
  console.log(`Loaded ${rows.length} tables into campus_connect.`);
  await conn.end();
})().catch((err) => {
  console.error(`\nFailed: ${err.message}`);
  process.exit(1);
});
