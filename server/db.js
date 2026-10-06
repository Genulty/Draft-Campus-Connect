// MySQL connection pool. Connection settings come from environment variables
// (see .env.example); the database itself is created with `npm run db:setup`.
const crypto = require('crypto');
const mysql = require('mysql2/promise');

const config = {
  host: process.env.DB_HOST || '127.0.0.1',
  port: Number(process.env.DB_PORT) || 3306,
  user: process.env.DB_USER || 'campus',
  password: process.env.DB_PASSWORD || 'campus',
  database: process.env.DB_NAME || 'campus_connect',
};

const db = mysql.createPool({ ...config, connectionLimit: 10, dateStrings: true });

function verifyPassword(plain, stored) {
  const [salt, hash] = String(stored).split(':');
  if (!salt || !hash) return false;
  const test = crypto.scryptSync(plain, salt, 32);
  const known = Buffer.from(hash, 'hex');
  return known.length === test.length && crypto.timingSafeEqual(known, test);
}

module.exports = { db, config, verifyPassword };
