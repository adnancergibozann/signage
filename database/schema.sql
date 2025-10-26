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
    ticker_font_family VARCHAR(150) NOT NULL DEFAULT 'Segoe UI, sans-serif',
    ticker_font_size SMALLINT UNSIGNED NOT NULL DEFAULT 28,
    ticker_border_width TINYINT UNSIGNED NOT NULL DEFAULT 2,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO signage_settings (id, organization_name)
VALUES (1, 'Okulumuz')
ON DUPLICATE KEY UPDATE organization_name = organization_name;

CREATE TABLE IF NOT EXISTS signage_layouts (
    module_key VARCHAR(50) PRIMARY KEY,
    title VARCHAR(120) NOT NULL,
    top_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    left_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    width_percent DECIMAL(5,2) NOT NULL DEFAULT 30.00,
    height_percent DECIMAL(5,2) NOT NULL DEFAULT 20.00,
    font_scale DECIMAL(4,2) NOT NULL DEFAULT 1.00,
    z_index INT NOT NULL DEFAULT 1,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO signage_layouts (module_key, title, top_percent, left_percent, width_percent, height_percent, font_scale, z_index)
VALUES
    ('logo', 'Logo & Kurum Bilgisi', 2.00, 2.00, 22.00, 15.00, 1.00, 5),
    ('next_period', 'Sonraki Ders/Teneffüs', 19.00, 2.00, 22.00, 16.00, 1.00, 4),
    ('teachers', 'Nöbetçi Öğretmenler', 38.00, 2.00, 22.00, 30.00, 1.00, 3),
    ('news', 'Güncel Haberler', 70.00, 2.00, 22.00, 18.00, 1.00, 3),
    ('media', 'Medya Slayt Alanı', 8.00, 26.00, 44.00, 58.00, 1.00, 2),
    ('schedule', 'Ders Programı', 8.00, 72.00, 26.00, 58.00, 1.00, 3),
    ('weather', 'Hava Durumu', 2.00, 72.00, 26.00, 18.00, 1.00, 4),
    ('countdowns', 'Yaklaşan Etkinlikler', 68.00, 72.00, 26.00, 22.00, 1.00, 3),
    ('ticker', 'Kayan Yazılar', 90.00, 2.00, 96.00, 8.00, 1.00, 6)
ON DUPLICATE KEY UPDATE
    title = VALUES(title),
    top_percent = VALUES(top_percent),
    left_percent = VALUES(left_percent),
    width_percent = VALUES(width_percent),
    height_percent = VALUES(height_percent),
    font_scale = VALUES(font_scale),
    z_index = VALUES(z_index);

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
    source TEXT NULL,
    storage_path VARCHAR(255) NULL,
    duration_seconds INT NOT NULL DEFAULT 5,
    position INT NOT NULL DEFAULT 1,
    expires_at DATETIME NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_media_active (is_active, position),
    INDEX idx_media_expiration (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schedule_periods (
    id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    period_number TINYINT UNSIGNED NOT NULL,
    label VARCHAR(50) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    period_type ENUM('lesson', 'break') NOT NULL DEFAULT 'lesson',
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

CREATE TABLE IF NOT EXISTS news_feeds (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    feed_url VARCHAR(255) NOT NULL,
    items_limit TINYINT UNSIGNED NOT NULL DEFAULT 5,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_checked_at DATETIME NULL,
    last_status VARCHAR(120) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_feed_url (feed_url)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS news_feed_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    feed_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    summary TEXT NULL,
    image_url VARCHAR(255) NULL,
    source_url VARCHAR(255) NULL,
    published_at DATETIME NULL,
    fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_feed_article (feed_id, source_url(180)),
    INDEX idx_feed_items_published (feed_id, published_at),
    CONSTRAINT fk_feed_items_feed FOREIGN KEY (feed_id) REFERENCES news_feeds(id) ON DELETE CASCADE
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
