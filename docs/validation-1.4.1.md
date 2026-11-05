# Verifica rigenerazione polling — 1.4.1

Eseguita il 5 novembre 2026 in ambiente isolato: WordPress 6.8.3, WooCommerce
10.3.5, PHP 8.3, MariaDB 11.4. Nessun merge, deploy, accesso o comando produttivo.

## Risultati

- `bash occhioaiprezzi-bridge-receiver/tests/run.sh`: **superato**, 367 verifiche
  PASS, lint di tutti i PHP e tutte le suite esistenti incluse.
- Suite PHP rigenerazione: **63 verifiche superate**; configurazione dedicata:
  **6 scenari superati** (assente, riusata, token/worker invalidi, vecchio token,
  configurazione valida).
- `node occhioaiprezzi-bridge-receiver/tests/regeneration-ui.cjs`: **superato**;
  doppio clic, timeout/retry con stessa chiave, attesa ricevuta, avanzamento su
  esiti terminali, conservazione contatori durante indisponibilità e polling
  anche dopo completamento.
- `tests/regeneration_vps2.py` con il Python frozen di VPS2: **superato** contro
  REST e AJAX WordPress reali. Verificati login amministratore e nonce, campagna
  unica, start/pause/resume, doppio clic con chiave uguale/diversa, ACK perso
  dopo commit WordPress, riavvio del client e connessione DB VPS2, replay della
  ricevuta storica senza ripetere la pausa, snapshot identico al servizio VPS2.
- Nel clone VPS2 invariato, `uv run pytest -q
  tests/integration/test_regeneration_control.py
  tests/integration/test_regeneration.py tests/unit/test_regeneration_poll_config.py`:
  **68 passed**, un avviso preesistente Starlette/httpx. Inclusi scadenze,
  concorrenza, priorità dei job ordinari, ricevute durevoli e contatori reali.
- `node --check`, `bash -n` setup/run/teardown e `git diff --check`: **superati**.

Output della suite completa: [test-results-1.4.1.txt](test-results-1.4.1.txt).
Il log `regeneration_poll_failed` del collaudo VPS2 è l'errore atteso nella
simulazione della risposta ACK persa, seguito dal recupero riuscito.

La prima preparazione ha rilevato una race del setup: il ping MariaDB sul socket
poteva riuscire durante l'inizializzazione prima della disponibilità TCP. Il setup
ora attende TCP. Un primo passaggio del test preesistente public_base ha fallito
il confronto complessivo dello snapshot; il confronto diagnostico successivo non
ha mostrato differenze e le esecuzioni complete successive sono passate. Nessuna
modifica al ricevitore o al test di percorso pubblico è stata necessaria.
Il router locale di test è stato esteso per servire gli script core WordPress
esistenti: necessario per provare login, pagina amministrativa e AJAX reali.

## Compatibilità VPS2

Riferimento: `alessandroadseo-ctrl/vps-ponte`, commit
`67f1885574bfc4055ff09fc650a14d058c9f816d`, protocollo
`oap-regeneration-poll-1`. Sorgenti VPS2 invariati (`git status --porcelain` vuoto).
Il client reale `RegenerationPoller`, il modello Command e l'esecuzione
transazionale VPS2 attraversano il plugin PHP aggiornato; nessun peer di protocollo
è simulato. Solo il trasporto del test mappa HTTPS receiver.test al listener HTTP
locale, dove wp-config simula terminazione TLS fidata. Il test fissa il commit per
rendere riproducibile la verifica. Configurazione temporanea ripristinata in finally.

Questo certifica il contratto e l'integrazione dei due repository sul commit
indicato. Restano verifiche operative del futuro rilascio TLS/DNS/firewall dal
server VPS2 reale al sito WordPress; non sono state eseguite in produzione.

## File modificati

Nella directory `occhioaiprezzi-bridge-receiver/`:

- `includes/class-regeneration-client.php`: validazione del protocollo e configurazione.
- `includes/class-regeneration-store.php` (nuovo): tabelle dedicate, mutex e transazioni.
- `includes/class-regeneration-rest.php` (nuovo): poll/ack privati autenticati.
- `includes/class-regeneration-admin.php`: accodamento locale, nonce e stato asincrono.
- `assets/regeneration.js`: comandi persistenti nel browser, contatori e indisponibilità.
- `occhioaiprezzi-bridge-receiver.php`: caricamento componenti e versione 1.4.1.
- `tests/regeneration.php`: suite PHP aggiornata.
- `tests/regeneration-config.php`, `tests/regeneration-ui.cjs`,
  `tests/regeneration_vps2.py` (nuovi): configurazione, browser e integrazione reale.
- `tests/run.sh`, `tests/setup.sh`: esecuzione suite e harness isolato.
- `README.md`, `docs-regeneration.md`, `tests/README.md`: documentazione.

In root: `README.md`, `docs/validation-1.4.1.md` e
`docs/test-results-1.4.1.txt`. Nessuna modifica ai file del ricevitore,
GET/PUT pages, storage delle schede, pubblicazione, affiliati, SEO o sitemap.

## Endpoint e configurazione

- POST `/wp-json/oap-bridge/v1/regeneration/poll`: riceve lo snapshot e consegna
  al massimo il comando più vecchio non confermato.
- POST `/wp-json/oap-bridge/v1/regeneration/ack`: conserva la ricevuta immutabile
  prima della conferma; supporta replay anche dopo comandi successivi.

HTTPS/Bearer dedicato e binding worker. WordPress richiede
`OAP_REGENERATION_POLL_TOKEN`, `OAP_REGENERATION_WORKER_ID`; opzionale
`OAP_REGENERATION_STALE_SECONDS`. VPS2 richiede
`BRIDGE_REGENERATION_POLL_URL`, `BRIDGE_REGENERATION_POLL_TOKEN`,
`BRIDGE_REGENERATION_WORKER_ID`. Token separati dalle pubblicazioni.

Procedura completa di aggiornamento/rollback:
[docs-regeneration.md](../occhioaiprezzi-bridge-receiver/docs-regeneration.md).
In particolare conservare coda e ricevute su entrambe le basi dati, non
rinumerare comandi e riconciliare eventuali pending 1.4.0 prima del polling.
