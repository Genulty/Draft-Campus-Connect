-- Existing table definitions only; disposable security fixture, never an application migration.
CREATE TABLE `User` (
  `user_ID` int NOT NULL,
  `first_Name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `middle_Name` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_Name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `gender` enum('Male','Female','Other') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `DOB` date DEFAULT NULL,
  `street` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state` char(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `zip_Code` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_Type` enum('Student','Faculty','Admin','StatDept') COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`user_ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `Admin` (
  `admin_ID` int NOT NULL,
  `security_Level` enum('Read-Only','Read-Write') COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`admin_ID`),
  CONSTRAINT `admin_ibfk_1` FOREIGN KEY (`admin_ID`) REFERENCES `User` (`user_ID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `Login` (
  `user_ID` int NOT NULL,
  `user_Email` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_Password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_Type` enum('Student','Faculty','Admin','StatDept') COLLATE utf8mb4_unicode_ci NOT NULL,
  `no_Of_Tries` int NOT NULL DEFAULT '0',
  `lock_Var` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`user_ID`),
  UNIQUE KEY `user_Email` (`user_Email`),
  CONSTRAINT `login_ibfk_1` FOREIGN KEY (`user_ID`) REFERENCES `User` (`user_ID`) ON DELETE CASCADE,
  CONSTRAINT `login_chk_1` CHECK ((`lock_Var` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `Password_Reset` (
  `reset_ID` int NOT NULL AUTO_INCREMENT,
  `user_ID` int NOT NULL,
  `purpose` enum('Forgot','Change','Admin') COLLATE utf8mb4_unicode_ci NOT NULL,
  `requested_By` int DEFAULT NULL,
  `code_Hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_Hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` int NOT NULL DEFAULT '0',
  `created_At` datetime NOT NULL,
  `expires_At` datetime NOT NULL,
  `used_At` datetime DEFAULT NULL,
  PRIMARY KEY (`reset_ID`),
  UNIQUE KEY `token_Hash` (`token_Hash`),
  KEY `user_ID` (`user_ID`),
  KEY `requested_By` (`requested_By`),
  CONSTRAINT `password_reset_ibfk_1` FOREIGN KEY (`user_ID`) REFERENCES `User` (`user_ID`) ON DELETE CASCADE,
  CONSTRAINT `password_reset_ibfk_2` FOREIGN KEY (`requested_By`) REFERENCES `Admin` (`admin_ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `Email_Message` (
  `message_ID` int NOT NULL AUTO_INCREMENT,
  `user_ID` int NOT NULL,
  `to_Email` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `sent_At` datetime NOT NULL,
  `read_At` datetime DEFAULT NULL,
  PRIMARY KEY (`message_ID`),
  KEY `user_ID` (`user_ID`),
  CONSTRAINT `email_message_ibfk_1` FOREIGN KEY (`user_ID`) REFERENCES `User` (`user_ID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `Audit_Log` (
  `log_ID` int NOT NULL AUTO_INCREMENT,
  `admin_ID` int NOT NULL,
  `user_ID` int NOT NULL,
  `action_Type` enum('Create','Update','Delete') COLLATE utf8mb4_unicode_ci NOT NULL,
  `field_Name` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `old_Value` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `new_Value` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_Time` datetime NOT NULL,
  PRIMARY KEY (`log_ID`),
  KEY `admin_ID` (`admin_ID`),
  KEY `user_ID` (`user_ID`),
  CONSTRAINT `audit_log_ibfk_1` FOREIGN KEY (`admin_ID`) REFERENCES `Admin` (`admin_ID`),
  CONSTRAINT `audit_log_ibfk_2` FOREIGN KEY (`user_ID`) REFERENCES `User` (`user_ID`)
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
