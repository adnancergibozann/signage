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
    font_family VARCHAR(100) NOT NULL DEFAULT 'Arial, sans-serif',
    font_size TINYINT UNSIGNED NOT NULL DEFAULT 28,
    text_color CHAR(7) NOT NULL DEFAULT '#FFFFFF',
    background_color CHAR(7) NOT NULL DEFAULT '#0A0A0A',
    speed INT NOT NULL DEFAULT 30,
    position INT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
