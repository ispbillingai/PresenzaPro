# PresenzaPro

Rilevazione presenze via web con timbratura geolocalizzata. Nessun dispositivo fisico:
ogni dipendente accede dal proprio telefono e può timbrare entrata/uscita solo se la
posizione GPS in tempo reale è entro il raggio della sede di lavoro assegnata.

## Stack
PHP 8.3, MariaDB 10.11, Apache. Nessuna dipendenza esterna lato server. Leaflet + OpenStreetMap
(CDN) per la mappa delle sedi nell'area admin.

## Ruoli
- **Admin**: dipendenti (con webhook GET opzionale chiamato a ogni timbratura), sedi (punto + raggio),
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
**Webhook**: per ogni dipendente si può impostare un URL (`users.webhook_url`, attivabile per dipendente e
globalmente con `settings.webhooks_enabled`) chiamato in GET dopo ogni timbratura accettata, con segnaposto
`{user_id} {username} {name} {type} {type_label} {date} {time} {datetime} {timestamp} {location} {lat} {lng} {clocking_id}`
o, senza segnaposto, gli stessi valori come parametri. La chiamata avviene dopo l'invio della risposta al
telefono (`includes/webhook.php`), esito in `webhook_log`, bottone di prova nella scheda del dipendente.

## Altre funzioni
- **Richieste** ferie/permessi dal telefono (`employee/requests.php`), approvazione in `admin/requests.php`
  (l'approvazione crea la riga in `absences`).
- **Messaggi richieste**: nuova richiesta → admin (`notifyAdmin`), decisione → dipendente (`notifyEmployee`:
  WhatsApp sul suo cellulare via TextMeBot e/o email); interruttore `settings.notify_requests`.
- **Cartellino mensile PDF**: `employee/timecard.php?m=` (dipendente) e `admin/timecard.php?user=&m=`,
  generato da `includes/timecard.php` con il writer minimale `includes/pdf.php` (Helvetica, WinAnsi, nessuna libreria).
- **Avvisi**: `bin/check-alerts.php` (cron ogni 5 minuti) segnala mancata entrata e uscita mancante
  rispetto al turno; invio email (`mail()`) e/o WhatsApp (TextMeBot) ai contatti in Impostazioni › Avvisi;
  tabella `alerts`, mostrati nel Riepilogo admin.
- **Saldi** (`admin/balances.php`): ferie spettanti/godute/pianificate/residue, permessi, banca ore
  (lavorate meno previste da inizio anno), straordinari. Spettanze nella scheda del dipendente.
- **Export paghe** (`admin/export-payroll.php`): CSV giornaliero per causale (ORD, STR, FER, PER, MAL,
  PSE, ALT, ASS, RIT) o totali mensili per dipendente. `permesso` = personale (scala il monte ore
  permessi), `permesso_servizio` = per servizio (giustifica le ore, non scala nulla).
- **Permesso timbrato**: pulsanti "Uscita per permesso" / "Rientro da permesso" (tipi `permit_start`/`permit_end`,
  sempre disponibili quando si è in servizio): il tempo fuori è scalato dalle ore lavorate e dalle ore previste
  e conteggiato come permesso personale (quindi scala il monte ore).
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
