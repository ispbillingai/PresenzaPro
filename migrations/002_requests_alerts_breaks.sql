-- Leave requests, alerts, clocked breaks, per-date schedule overrides, leave entitlements.

ALTER TABLE clockings
    MODIFY type ENUM('in', 'out', 'break_start', 'break_end') NOT NULL;

ALTER TABLE shifts
    ADD COLUMN break_mode ENUM('fixed', 'clocked') NOT NULL DEFAULT 'fixed' AFTER break_minutes;

ALTER TABLE users
    ADD COLUMN annual_leave_days DECIMAL(5, 2) NOT NULL DEFAULT 0 AFTER phone,
    ADD COLUMN leave_carryover_days DECIMAL(5, 2) NOT NULL DEFAULT 0 AFTER annual_leave_days,
    ADD COLUMN annual_permit_hours DECIMAL(6, 2) NOT NULL DEFAULT 0 AFTER leave_carryover_days;

CREATE TABLE IF NOT EXISTS leave_requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    type ENUM('ferie', 'permesso', 'malattia', 'altro') NOT NULL,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    hours DECIMAL(4, 2) NULL,
    note VARCHAR(255) NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    admin_note VARCHAR(255) NULL,
    decided_by INT UNSIGNED NULL,
    decided_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_lr_status (status, created_at),
    KEY ix_lr_user (user_id, date_from),
    CONSTRAINT fk_lr_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE absences ADD COLUMN request_id INT UNSIGNED NULL AFTER created_by;

-- Per-date exception to the weekly schedule. shift_id NULL = forced rest day.
CREATE TABLE IF NOT EXISTS schedule_overrides (
    user_id INT UNSIGNED NOT NULL,
    `date` DATE NOT NULL,
    shift_id INT UNSIGNED NULL,
    note VARCHAR(120) NULL,
    PRIMARY KEY (user_id, `date`),
    CONSTRAINT fk_so_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_so_shift FOREIGN KEY (shift_id) REFERENCES shifts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alerts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    `date` DATE NOT NULL,
    kind ENUM('late', 'missing_out') NOT NULL,
    message VARCHAR(255) NOT NULL,
    channels VARCHAR(60) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_alert (user_id, `date`, kind),
    KEY ix_alerts_date (`date`),
    CONSTRAINT fk_al_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (`key`, `value`) VALUES
    ('alerts_enabled', '1'),
    ('alert_email', ''),
    ('alert_phone', ''),
    ('textmebot_api_key', ''),
    ('late_alert_minutes', '15'),
    ('missing_out_alert_minutes', '60');
