-- Campus Connect: tables for the emailed password-reset codes (campus mail).
-- Safe on the live database: it only adds two new tables and changes no existing data.
-- (A fresh database gets them from db/Database_schema.sql.)
USE `campus_connect`;

CREATE TABLE IF NOT EXISTS Email_Message (
  message_ID INT          PRIMARY KEY AUTO_INCREMENT,
  user_ID    INT          NOT NULL,
  to_Email   VARCHAR(100) NOT NULL,
  subject    VARCHAR(150) NOT NULL,
  body       TEXT         NOT NULL,
  sent_At    DATETIME     NOT NULL,
  read_At    DATETIME,
  FOREIGN KEY (user_ID) REFERENCES User(user_ID) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS Password_Reset (
  reset_ID     INT       PRIMARY KEY AUTO_INCREMENT,
  user_ID      INT       NOT NULL,
  purpose      ENUM('Forgot','Change','Admin') NOT NULL,
  requested_By INT,
  code_Hash    VARCHAR(255) NOT NULL,
  token_Hash   CHAR(64)  NOT NULL UNIQUE,
  attempts     INT       NOT NULL DEFAULT 0,
  created_At   DATETIME  NOT NULL,
  expires_At   DATETIME  NOT NULL,
  used_At      DATETIME,
  FOREIGN KEY (user_ID)      REFERENCES User(user_ID) ON DELETE CASCADE,
  FOREIGN KEY (requested_By) REFERENCES Admin(admin_ID)
);

SELECT 'Email_Message and Password_Reset are ready' AS status;
