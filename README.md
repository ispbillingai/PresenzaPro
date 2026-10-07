# PresenzaPro

Rilevazione presenze via web con timbratura geolocalizzata. Nessun dispositivo fisico:
ogni dipendente accede dal proprio telefono e può timbrare entrata/uscita solo se la
posizione GPS in tempo reale è entro il raggio della sede di lavoro assegnata.

## Stack
PHP 8.3, MariaDB 10.11, Apache. Nessuna dipendenza esterna lato server. Leaflet + OpenStreetMap
(CDN) per la mappa delle sedi nell'area admin.

## Ruoli
- **Admin**: dipendenti, sedi (punto + raggio), assegnazioni, timbrature, report ore, impostazioni.
- **Dipendente**: pagina "Timbra" (entrata/uscita con GPS) e storico mensile.

## Installazione
```
cp config/database.example.php config/database.php   # inserisci le credenziali
php migrate.php                                      # crea le tabelle
php bin/create-admin.php admin 'password-lunga' "Nome Admin"
```
La geolocalizzazione nel browser richiede **HTTPS** (o localhost).

## Come funziona la timbratura
`POST /api/clock.php` riceve lat/lng/precisione/timestamp del fix. Il server:
1. prende le sedi attive assegnate al dipendente (nessuna → rifiuto);
2. rifiuta se la precisione supera `max_accuracy_m` o il fix è più vecchio di `max_fix_age_s`;
3. calcola la distanza (haversine) dalla sede più vicina: fuori raggio → rifiuto;
4. verifica la sequenza (dopo un'entrata serve un'uscita);
5. registra **sempre** il tentativo in `clockings` con esito, motivo, coordinate, distanza, IP e user agent.

## Struttura
- `includes/` bootstrap, db, auth, helpers, geo (haversine), clocking (regole), layout
- `api/clock.php` endpoint timbratura
- `employee/` area dipendente · `admin/` area amministratore
- `database_schema.sql` schema base · `migrations/*.sql` migrazioni incrementali (`php migrate.php`)
