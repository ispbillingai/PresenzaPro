-- Personal login links, shifts, weekly schedules, absences, holidays, manual clockings.

ALTER TABLE users
    ADD COLUMN login_token VARCHAR(64) NULL AFTER phone,
    ADD COLUMN token_created_at DATETIME NULL AFTER login_token,
    ADD UNIQUE KEY uq_users_token (login_token);

ALTER TABLE clockings
    MODIFY status ENUM('accepted', 'rejected', 'voided') NOT NULL,
    ADD COLUMN source ENUM('gps', 'manual') NOT NULL DEFAULT 'gps' AFTER status,
    ADD COLUMN created_by INT UNSIGNED NULL AFTER note,
    ADD COLUMN voided_by INT UNSIGNED NULL AFTER created_by,
    ADD COLUMN voided_at DATETIME NULL AFTER voided_by;

CREATE TABLE IF NOT EXISTS shifts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    break_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    tolerance_in_min SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    tolerance_out_min SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- weekday: 1 = Monday ... 7 = Sunday (PHP date('N')). Missing row = rest day.
CREATE TABLE IF NOT EXISTS user_shifts (
    user_id INT UNSIGNED NOT NULL,
    weekday TINYINT UNSIGNED NOT NULL,
    shift_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, weekday),
    CONSTRAINT fk_us_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_us_shift FOREIGN KEY (shift_id) REFERENCES shifts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Justified absences. hours NULL = whole day(s); hours set = partial leave on a single day.
CREATE TABLE IF NOT EXISTS absences (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    type ENUM('ferie', 'permesso', 'malattia', 'altro') NOT NULL,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    hours DECIMAL(4, 2) NULL,
    note VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_absences_user_dates (user_id, date_from, date_to),
    CONSTRAINT fk_ab_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Company-specific holidays (national Italian holidays are computed in code).
CREATE TABLE IF NOT EXISTS holidays (
    `date` DATE NOT NULL PRIMARY KEY,
    name VARCHAR(120) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (`key`, `value`) VALUES ('overtime_min_minutes', '15');
