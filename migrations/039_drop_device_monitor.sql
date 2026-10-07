-- Migration: 039_drop_device_monitor
-- The device monitor (Admin > Dispositivi, Aree di rete: shop devices pinged
-- through the MikroTik router) is removed from this app, with its data.
-- device_events stays: it is the payments / kitchen-print audit log.

DROP TABLE IF EXISTS devices;
DROP TABLE IF EXISTS network_areas;
