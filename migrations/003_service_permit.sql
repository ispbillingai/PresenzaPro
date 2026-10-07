-- "Permesso per servizio": justified hours that are NOT deducted from the permit allowance.
ALTER TABLE absences MODIFY type ENUM('ferie', 'permesso', 'permesso_servizio', 'malattia', 'altro') NOT NULL;
ALTER TABLE leave_requests MODIFY type ENUM('ferie', 'permesso', 'permesso_servizio', 'malattia', 'altro') NOT NULL;
