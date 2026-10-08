-- Clocked permits: the employee leaves for a personal permit during the shift and clocks back in.
ALTER TABLE clockings
    MODIFY type ENUM('in', 'out', 'break_start', 'break_end', 'permit_start', 'permit_end') NOT NULL;
