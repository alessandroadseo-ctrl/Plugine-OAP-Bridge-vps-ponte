# Rigenerazione schede — 1.4.0

WordPress controlla VPS2: nessuna rigenerazione, pubblicazione o WP-Cron locale.
Ricevitore, artefatti, hash/versioni, HTML, proprietà, GTIN, SEO, sitemap e redirect rimangono invariati.

## Configurazione protetta
In wp-config.php / configurazione gestita dal server, prima del caricamento plugin:
~~~php
define('OAP_REGENERATION_URL', 'https://vps2.example.org');
define('OAP_REGENERATION_TOKEN', 'INSERIRE_UN_SEGRETO_DEDICATO_DI_ALMENO_32_CARATTERI');
~~~
URL radice HTTPS (eventuale prefisso consentito), senza query, credenziali o frammenti.
Token Bearer dedicato da 32–4096 byte, senza spazi; non riusare il token del ricevitore.
Nessun token arriva al browser, al pannello o ai messaggi di errore.
Non serve modificare OAP_BRIDGE_WRITES_ENABLED.

## Contratto API richiesto a VPS2
Questi endpoint e il seguente schema devono essere implementati e verificati con VPS2 prima del rilascio. Il repository WordPress non contiene una specifica preesistente della risposta: questo documento esplicita il contratto richiesto, non certifica API già operative.

- GET /api/v1/regeneration/status
- POST /api/v1/regeneration/start, JSON {"batch_size":25}
- POST /api/v1/regeneration/pause e /resume, JSON {"campaign_id":"campaign-1"}

Tutte le richieste usano Authorization: Bearer TOKEN.
Tutti i POST usano Idempotency-Key (UUID). VPS2 deve conservare durevolmente le ricevute e rispondere ai replay con la ricevuta originale senza ripetere il comando. Deve inoltre impedire atomicamente più campagne attive, includendo quelle sospese, e rifiutare pause/riprese su ID non correnti. La pausa impedisce nuovi lotti: un lotto già in corso può concludersi.
Le chiavi devono essere isolate per chiamante; stessa chiave con payload diverso va rifiutata.
Nessun redirect viene seguito. TLS resta verificato.

Risposta 2xx JSON per lo stato:
~~~json
{"ok":true,"campaign":{"id":"campaign-1","state":"running","total":100,"examined":30,"updated":20,"unchanged":8,"errors":2,"remaining":70,"started_at":"2026-11-05T10:00:00Z","updated_at":"2026-11-05T10:01:00Z"}}
~~~
I POST aggiungono request_id uguale a Idempotency-Key e restituiscono la campagna confermata (start/resume: running; pause: paused).
Stati: idle, running, paused, completed, completed_with_errors.
Per idle usare id "none", contatori zero e date null.
Contatori interi non negativi: examined = updated + unchanged + errors; total = examined + remaining.
Le date sono RFC3339 al secondo con fuso esplicito; per campagne avviate sono obbligatorie.
Gli stati completati richiedono remaining=0; completed richiede errors=0; completed_with_errors richiede errors>0.
Campi aggiuntivi non vengono esposti.

## Concorrenza e conferme perse
Mutex MySQL/MariaDB GET_LOCK, specifico per sito, acquisito senza attesa, rilasciato in finally; se indisponibile nessun comando parte. Richiede connessione DB persistente per la durata della richiesta (nessun proxy che sposti le query tra connessioni).
Una chiave e il payload sono salvati prima del POST nell'opzione privata non autoload oap_regeneration_pending.
Timeout o ACK non valido non vengono trattati come successo.
GET stato non invia comandi. Quando rileva una conferma pendente, il browser effettua al successivo intervallo un POST locale retry, autenticato e protetto da nonce, che ripete la stessa chiave verso VPS2 e poi rilegge lo stato.
Non eliminare manualmente l'opzione pending senza aver riconciliato la ricevuta su VPS2.
Il blocco WordPress limita le richieste concorrenti del sito; la garanzia globale contro duplicati richiede l'atomicità e l'idempotenza VPS2 descritte sopra.

## Pannello e indisponibilità
Sottomenu Rigenerazione schede, solo manage_options. POST comandi, GET stato, nonce su entrambi.
Lotti 10/25/50/100, predefinito 25. Conferma browser prima dell'avvio.
Polling senza sovrapposizioni ogni 20 secondi durante esecuzione, pausa ed errori.
Il pannello mantiene l'ultimo snapshot valido in oap_regeneration_snapshot (non autoload), segnala dati obsoleti e disabilita i comandi durante indisponibilità.
Mostra date in Europe/Rome. Il plugin continua le altre operazioni se API/configurazione non disponibili.

## Verifica di rete prima del rilascio
La disponibilità WordPress → VPS2 NON è verificata da questa modifica: l'ambiente Codex non è partito (servizio 503), e non è disponibile una sessione sul server WordPress né un endpoint configurato con credenziali.
Su staging con lo stesso percorso di uscita di WordPress:
1. Configurare DNS, firewall e allowlist in uscita TCP/443 verso il nome VPS2; pubblicare un certificato con catena attendibile e nome corretto.
2. Aprire il pannello: il GET autenticato deve produrre uno snapshot valido. 401/403 indicano autenticazione non accettata; 404/501 indicano API non disponibili; timeout/TLS vanno risolti sul server/rete senza sslverify=false.
3. Eseguire start/pausa/riprendi solo sul dataset di staging, verificando conferme e contatori VPS2. Simulare conferma persa e doppio avvio simultaneo: una sola campagna deve esistere.
4. Disabilitare temporaneamente l'API e verificare dati obsoleti, conservazione snapshot e funzionamento del ricevitore.

## Test
tests/regeneration.php è incluso in tests/run.sh: permessi admin/editor/subscriber/anonimo, nonce, GET di mutazione vietato, autenticazione Bearer, metodi upstream, TLS/redirect/timeout, errori HTTP/JSON, snapshot obsoleto, ACK invalido, replay idempotente, contatori, campi sensibili e mutex tra connessioni DB.
Eseguire l'intera suite isolata seguendo tests/README.md.
Test aggiunti ma NON eseguiti in questa sessione a causa dell'indisponibilità dell'ambiente. Nessuna verifica di produzione effettuata.
