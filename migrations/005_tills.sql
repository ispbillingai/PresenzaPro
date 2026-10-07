-- Migration: 005_tills
-- Multiple independent tills ("Cassa 1", "Cassa 2"). Each is a station of
-- type='till' that carries its OWN device bundle:
--   * printer_* columns      -> the till's NON-FISCAL bill printer (ESC/POS)
--   * device_config (JSON)   -> the till's fiscal printer, card terminal (POS)
--                               and cash machine (Cashmatic), e.g.
--     {"fiscal":{"base_url":"http://100.x","operator":"1","timeout_ms":35000},
--      "pos":{"base_url":"http://100.x/WebDoremiposWS","terminal_name":"Ingenico-X",
--             "protocol_type":"0","connect_timeout":5,"read_timeout":90},
--      "cashmatic":{"base_url":"http://100.x:50301","username":"u","password":"p","verify_ssl":false}}
--
-- The waiter routes a bill to a till (orders.till_id); every payment for that
-- order then uses that till's devices, falling back to the global config in
-- config/devices.php / admin Printers when a field is left blank.

ALTER TABLE stations
    ADD COLUMN device_config JSON NULL AFTER printer_codepage;

ALTER TABLE orders
    ADD COLUMN till_id INT NULL DEFAULT NULL AFTER waiter_id;
