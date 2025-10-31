CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('super_admin', 'boss', 'manager', 'finance', 'accounting', 'secretary', 'viewer') NOT NULL DEFAULT 'viewer',
    full_name VARCHAR(120) NOT NULL,
    department VARCHAR(120) NULL,
    email VARCHAR(120) NULL,
    phone VARCHAR(40) NULL,
    photo_path VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS manager_statuses (
    user_id INT UNSIGNED PRIMARY KEY,
    status ENUM('available', 'unavailable', 'meeting', 'lunch', 'leave', 'cheque_ready', 'payment_ready') NOT NULL DEFAULT 'available',
    state_started_at DATETIME NULL,
    state_ends_at DATETIME NULL,
    note VARCHAR(255) NULL,
    active_meeting_id INT UNSIGNED NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_status_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meeting_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    manager_id INT UNSIGNED NOT NULL,
    started_at DATETIME NOT NULL,
    expected_end_at DATETIME NULL,
    ended_at DATETIME NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_meeting_manager FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_meeting_manager_day (manager_id, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    body TEXT NULL,
    priority INT NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticker_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message VARCHAR(255) NOT NULL,
    priority INT NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS media_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    media_type ENUM('image', 'video') NOT NULL,
    is_fullscreen TINYINT(1) NOT NULL DEFAULT 1,
    duration_seconds INT NULL,
    priority INT NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    `key` VARCHAR(60) NOT NULL PRIMARY KEY,
    `value` TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action VARCHAR(120) NOT NULL,
    details TEXT NULL,
    ip_address VARCHAR(45) NULL,
    context JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_syslog_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_syslog_created_at (created_at),
    INDEX idx_syslog_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO users (username, password_hash, role, full_name, department) VALUES
    ('admin', '$2y$12$kyWgocXyDYltldwK52lYweTeEROIh.surRj6S9boSNWvLZ5qt/gUC', 'super_admin', 'Süper Yönetici', 'Bilgi İşlem'),
    ('boss', '$2y$12$VsQpdEEYZShT0jTD08CD2.bxesM4tsF.nj6o6EwBFeQZmRMch0We6', 'boss', 'Genel Müdür', 'Yönetim'),
    ('ayse', '$2y$12$t8WX/X/9IZavAEQOnw5xk.1WxufQPVXc2jxcLw3odtDEGOcvzLoee', 'manager', 'Ayşe Kara', 'Satınalma'),
    ('mehmet', '$2y$12$t8WX/X/9IZavAEQOnw5xk.1WxufQPVXc2jxcLw3odtDEGOcvzLoee', 'manager', 'Mehmet Yıldız', 'Satınalma');

INSERT IGNORE INTO manager_statuses (user_id, status) VALUES
    ((SELECT id FROM users WHERE username = 'ayse'), 'available'),
    ((SELECT id FROM users WHERE username = 'mehmet'), 'available');

INSERT INTO settings (`key`, `value`) VALUES
    ('company_name', 'Gapgross'),
    ('theme_primary', '#E3000B'),
    ('theme_secondary', '#17007A'),
    ('signage_refresh_seconds', '5'),
    ('time_format', '24h'),
    ('ticker_speed', '40')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
