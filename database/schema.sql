CREATE TABLE IF NOT EXISTS admin_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO admin_users (username, password_hash)
VALUES ('admin', '$2y$12$kyWgocXyDYltldwK52lYweTeEROIh.surRj6S9boSNWvLZ5qt/gUC')
ON DUPLICATE KEY UPDATE username = username;

CREATE TABLE IF NOT EXISTS ticker_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    body TEXT NULL,
    text_color CHAR(7) NOT NULL DEFAULT '#FFFFFF',
    background_color CHAR(7) NOT NULL DEFAULT '#0A0A0A',
    speed INT NOT NULL DEFAULT 30,
    position INT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS signage_settings (
    id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
    organization_name VARCHAR(255) NOT NULL DEFAULT 'Okulumuz',
    logo LONGBLOB NULL,
    logo_mime VARCHAR(100) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO signage_settings (id, organization_name)
VALUES (1, 'Okulumuz')
ON DUPLICATE KEY UPDATE organization_name = organization_name;

CREATE TABLE IF NOT EXISTS teachers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    branch VARCHAR(120) NOT NULL,
    photo LONGBLOB NULL,
    photo_mime VARCHAR(100) NULL,
    is_on_duty TINYINT(1) NOT NULL DEFAULT 0,
    position INT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS weather_snapshots (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    city VARCHAR(120) NOT NULL,
    temperature DECIMAL(4,1) NOT NULL,
    feels_like DECIMAL(4,1) NULL,
    humidity TINYINT UNSIGNED NULL,
    wind_speed DECIMAL(4,1) NULL,
    condition_label VARCHAR(80) NOT NULL,
    condition_icon VARCHAR(40) NULL,
    fetched_at DATETIME NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_weather_active (is_active, fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS media_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    type ENUM('image', 'video', 'pdf') NOT NULL,
    source TEXT NOT NULL,
    duration_seconds INT NOT NULL DEFAULT 5,
    position INT NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_media_active (is_active, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schedule_periods (
    id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    period_number TINYINT UNSIGNED NOT NULL,
    label VARCHAR(50) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    UNIQUE KEY uniq_period_number (period_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS classrooms (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    display_order INT NOT NULL DEFAULT 1,
    UNIQUE KEY uniq_classroom_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS class_schedule_entries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    classroom_id INT UNSIGNED NOT NULL,
    weekday TINYINT UNSIGNED NOT NULL,
    period_number TINYINT UNSIGNED NOT NULL,
    subject VARCHAR(120) NOT NULL,
    teacher VARCHAR(120) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_schedule (classroom_id, weekday, period_number),
    CONSTRAINT fk_schedule_classroom FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE,
    CONSTRAINT fk_schedule_period FOREIGN KEY (period_number) REFERENCES schedule_periods(period_number) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS news_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    summary TEXT NULL,
    image_url VARCHAR(255) NULL,
    source_url VARCHAR(255) NULL,
    published_at DATETIME NULL,
    position INT NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_news_active (is_active, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS countdowns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    target_at DATETIME NOT NULL,
    icon VARCHAR(20) NULL,
    highlight_color CHAR(7) NOT NULL DEFAULT '#FFD400',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    position INT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_countdown_active (is_active, target_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prayer_settings (
    id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
    country VARCHAR(120) NOT NULL DEFAULT 'Türkiye',
    city VARCHAR(120) NOT NULL DEFAULT 'İstanbul',
    district VARCHAR(120) NULL,
    calculation_method TINYINT UNSIGNED NOT NULL DEFAULT 13,
    madhab ENUM('shafi', 'hanafi') NOT NULL DEFAULT 'hanafi',
    timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Istanbul',
    jumuah_offset_minutes SMALLINT NOT NULL DEFAULT 45,
    auto_refresh_days SMALLINT NOT NULL DEFAULT 14,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO prayer_settings (id)
VALUES (1)
ON DUPLICATE KEY UPDATE id = id;

CREATE TABLE IF NOT EXISTS prayer_audio_profiles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    prayer_key VARCHAR(32) NOT NULL UNIQUE,
    display_name VARCHAR(120) NOT NULL,
    file_path VARCHAR(255) NULL,
    file_name VARCHAR(255) NULL,
    file_mime VARCHAR(120) NULL,
    file_size INT UNSIGNED NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO prayer_audio_profiles (prayer_key, display_name)
VALUES
    ('fajr', 'Sabah (İmsak)'),
    ('dhuhr', 'Öğle'),
    ('asr', 'İkindi'),
    ('maghrib', 'Akşam'),
    ('isha', 'Yatsı'),
    ('jumuah', 'Cuma Selası')
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name);

CREATE TABLE IF NOT EXISTS prayer_times (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    prayer_date DATE NOT NULL,
    prayer_key VARCHAR(32) NOT NULL,
    azan_at DATETIME NOT NULL,
    source VARCHAR(50) NULL,
    fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_prayer (prayer_date, prayer_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
