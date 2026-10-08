-- Permit codes: an approved permit request gets a one-time code the employee must enter
-- when clocking "Uscita per permesso"; the clocking links back to the request (type).
ALTER TABLE leave_requests
    ADD COLUMN permit_code VARCHAR(12) NULL AFTER admin_note,
    ADD COLUMN permit_used_at DATETIME NULL AFTER permit_code,
    ADD UNIQUE KEY uq_lr_code (permit_code);

ALTER TABLE clockings
    ADD COLUMN request_id INT UNSIGNED NULL AFTER note;
