# PresenzaPro

Rilevazione presenze via web con timbratura geolocalizzata. Nessun dispositivo fisico:
ogni dipendente accede dal proprio telefono e può timbrare entrata/uscita solo se la
posizione GPS in tempo reale è entro il raggio della sede di lavoro assegnata.

## Stack
PHP 8.3, MariaDB 10.11, Apache. Nessuna dipendenza esterna lato server. Leaflet + OpenStreetMap
(CDN) per la mappa delle sedi nell'area admin.

## Ruoli
- **Admin**: dipendenti (con link personale di accesso inviabile via WhatsApp), sedi (punto + raggio),
  fasce orarie e orario settimanale, timbrature (anche manuali, annullabili), assenze/permessi,
  riepilogo mensile stile lettore badge, festività, impostazioni.
- **Dipendente**: pagina "Timbra" (entrata/uscita con GPS, turno del giorno) e storico mensile con stato di ogni giorno.

## Riepilogo mensile (logica "lettore badge")
`includes/attendance.php` calcola per ogni dipendente e giorno: fascia prevista (da `user_shifts`,
1 = lunedì … 7 = domenica), ore previste (turno meno pausa, zero nei festivi nazionali + `holidays`),
ore lavorate (coppie entrata/uscita accettate, manuali comprese, annullate escluse), stato
(presente, assente, ferie, permesso, malattia, riposo, festivo), ritardo e uscita anticipata oltre la
tolleranza del turno, straordinario oltre `overtime_min_minutes`, uscite mancanti. Un giustificativo
senza ore copre l'intera giornata; con le ore riduce le ore previste di quel giorno.
Il link personale `/t.php?k=<token>` fa entrare il dipendente senza password (token revocabile).

## Altre funzioni
- **Richieste** ferie/permessi dal telefono (`employee/requests.php`), approvazione in `admin/requests.php`
  (l'approvazione crea la riga in `absences`).
- **Avvisi**: `bin/check-alerts.php` (cron ogni 5 minuti) segnala mancata entrata e uscita mancante
  rispetto al turno; invio email (`mail()`) e/o WhatsApp (TextMeBot) ai contatti in Impostazioni › Avvisi;
  tabella `alerts`, mostrati nel Riepilogo admin.
- **Saldi** (`admin/balances.php`): ferie spettanti/godute/pianificate/residue, permessi, banca ore
  (lavorate meno previste da inizio anno), straordinari. Spettanze nella scheda del dipendente.
- **Export paghe** (`admin/export-payroll.php`): CSV giornaliero per causale (ORD, STR, FER, PER, MAL,
  PSE, ALT, ASS, RIT) o totali mensili per dipendente. `permesso` = personale (scala il monte ore
  permessi), `permesso_servizio` = per servizio (giustifica le ore, non scala nulla).
- **Pausa timbrata**: fascia con `break_mode = clocked` → il dipendente timbra inizio/fine pausa
  (tipi `break_start`/`break_end`), le ore lavorate escludono la pausa reale.
- **Pianificazione per data** (`admin/schedule.php`): eccezioni all'orario settimanale (turno diverso o
  riposo) in `schedule_overrides`, usate da riepilogo, avvisi e pagina Timbra.

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
