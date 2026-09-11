-- api/schema.sql
-- Run once against your MySQL database: mysql -u root securechat < schema.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    email         VARCHAR(255) UNIQUE NOT NULL,
    name          VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    public_key    TEXT NOT NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sessions (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    jwt_jti    VARCHAR(64) UNIQUE NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS conversations (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user1_id   INT NOT NULL,
    user2_id   INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_pair (user1_id, user2_id),
    FOREIGN KEY (user1_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (user2_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `groups` (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(255) NOT NULL,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS group_members (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    group_id            INT NOT NULL,
    user_id             INT NOT NULL,
    encrypted_group_key TEXT NOT NULL,
    joined_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_member (group_id, user_id),
    FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)  REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS messages (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id         INT NULL,
    group_id                INT NULL,
    sender_id               INT NOT NULL,
    reply_to_id             INT NULL,
    message_type            ENUM('text','voice','media','text_media') NOT NULL,
    encrypted_content       MEDIUMTEXT NOT NULL,
    encrypted_key_recipient TEXT NULL,
    encrypted_key_sender    TEXT NULL,
    is_edited               TINYINT(1) NOT NULL DEFAULT 0,
    created_at              DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id)        REFERENCES `groups`(id)      ON DELETE CASCADE,
    FOREIGN KEY (sender_id)       REFERENCES users(id)         ON DELETE CASCADE,
    FOREIGN KEY (reply_to_id)     REFERENCES messages(id)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS message_media (
    id                          INT AUTO_INCREMENT PRIMARY KEY,
    message_id                  INT NOT NULL,
    file_path                   VARCHAR(500) NOT NULL,
    file_type                   ENUM('image','video','voice','file') NOT NULL,
    file_name                   VARCHAR(255) NULL,
    encrypted_file_key_recipient TEXT NULL,
    encrypted_file_key_sender    TEXT NULL,
    created_at                  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_chat_state (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    user_id              INT NOT NULL,
    conversation_id      INT NULL,
    group_id             INT NULL,
    last_read_message_id INT NOT NULL DEFAULT 0,
    updated_at           DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_conv  (user_id, conversation_id),
    UNIQUE KEY unique_user_group (user_id, group_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reactions (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL,
    user_id    INT NOT NULL,
    emoji      VARCHAR(10) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_reaction (message_id, user_id, emoji),
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_settings (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    user_id          INT NOT NULL UNIQUE,
    media_cache_days INT NOT NULL DEFAULT 30,
    updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
