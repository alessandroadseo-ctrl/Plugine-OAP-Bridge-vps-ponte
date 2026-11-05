# Rigenerazione schede — 1.4.1

Il pannello registra comandi in WordPress; esclusivamente VPS2 esegue la rigenerazione.
Nessuna connessione WordPress → VPS2, nessun WP-Cron, nessuna modifica a ricevitore,
GET/PUT pages, pubblicazioni, affiliati, canonical, sitemap, SEO o tabelle delle schede.

## Analisi e compatibilità

Base WordPress: `d97fca42654de6f644c56ee51f9470e7c79ff96e`, merge PR #13, plugin 1.4.0.
Esaminati struttura, sorgenti del plugin, caricamento/migrazioni, test, documentazione,
cronologia e PR #13. La versione 1.4.0 richiedeva API outbound non verificate,
uno schema diverso da VPS2 e uguaglianze dei contatori non applicabili.

Contratto adottato senza modifiche: **`oap-regeneration-poll-1`**, da
[`vps-ponte/docs/regeneration-polling.md`](https://github.com/alessandroadseo-ctrl/vps-ponte/blob/67f1885574bfc4055ff09fc650a14d058c9f816d/docs/regeneration-polling.md).
Riferimento VPS2 verificato: `67f1885574bfc4055ff09fc650a14d058c9f816d`.
Confrontati `app/wordpress/regeneration.py`, `app/services/regeneration_control.py`,
`app/services/regeneration.py`, configurazione, modelli, migrazioni e test.
Il collaudo usa il client Python reale di quel commit contro REST e AJAX WordPress
reali su database usa-e-getta; non un peer WordPress simulato.
Il repository VPS2 è consultato e testato senza modifiche ai suoi sorgenti.
Questo verifica compatibilità di codice/protocollo, non la rete di produzione.

## Configurazione

Nel solo wp-config.php/configurazione server protetta, prima di caricare il plugin:

```php
define('OAP_REGENERATION_POLL_TOKEN', 'SEGRETO_ASCII_CASUALE_DEDICATO_ALMENO_32_CARATTERI');
define('OAP_REGENERATION_WORKER_ID', 'vps2');
// Facoltativo: default 90 secondi, intervallo consentito 30–3600.
define('OAP_REGENERATION_STALE_SECONDS', 90);
```

Il token richiede 32–4096 caratteri ASCII stampabili senza spazi/controlli.
Non riusare il token del ricevitore o quello del vecchio controllo inbound VPS2.
WordPress rifiuta il riuso della costante/token digest del ricevitore e della
vecchia `OAP_REGENERATION_TOKEN`, se ancora definita. VPS2 verifica anche gli
altri propri segreti. Nessun token è salvato in JS, HTML, log o risposte API.
Le vecchie `OAP_REGENERATION_URL` e `OAP_REGENERATION_TOKEN` non sono più usate
per comunicare. Nessuna modifica a `OAP_BRIDGE_WRITES_ENABLED` o ai token pages.

Sul processo regenerate VPS2, nell'env protetto:

```text
BRIDGE_REGENERATION_POLL_URL=https://HOST-WORDPRESS/wp-json/oap-bridge/v1/regeneration/
BRIDGE_REGENERATION_POLL_TOKEN=<stesso nuovo segreto>
BRIDGE_REGENERATION_WORKER_ID=vps2
BRIDGE_REGENERATION_POLL_INTERVAL_SECONDS=10
BRIDGE_REGENERATION_POLL_TIMEOUT_SECONDS=5
```

Usare la base REST effettiva del sito, inclusa eventuale sottocartella. Sono
necessari permalink REST standard per il client che concatena poll/ack.
TLS verificato, nessun redirect; il server inoltra Authorization a PHP.
Dietro proxy TLS usare la configurazione fidata del sito; il plugin non si fida
di header forwarded arbitrari. Non servono porte/API inbound VPS2.
Il worker ID resta stabile attraverso restart e rotazione del token.
Dimensione lotti/backpressure restano `BRIDGE_REGENERATION_BATCH_SIZE` e
`BRIDGE_REGENERATION_MAX_PENDING` su VPS2: il selettore 1.4.0 viene rimosso.

## Endpoint privati e payload

Entrambi accettano esclusivamente POST JSON su HTTPS, Bearer dedicato, worker
associato alla configurazione, massimo 65536 byte; rispondono HTTP 200 JSON con
`Cache-Control: no-store, private`. Cookie/nonce amministrativi o token pages
non sostituiscono il Bearer. Errori standard WP con messaggi fissi, senza body remoti.

### POST `/wp-json/oap-bridge/v1/regeneration/poll`

VPS2 invia:

```json
{"protocol":"oap-regeneration-poll-1","worker_id":"vps2","last_sequence":0,"observed_at":1793872800,"campaign":{"state":"idle","campaign_id":null}}
```

WordPress salva lo snapshot e restituisce:

```json
{"protocol":"oap-regeneration-poll-1","worker_id":"vps2","command":null}
```

Oppure `command` contiene esattamente:
`command_id`, `idempotency_key` (UUID), `sequence` (intero consecutivo da 1),
`action` (start/pause/resume), `campaign_id` (UUID canonico o null per idle),
`issued_at`, `expires_at` (Unix UTC; durata 600 secondi).
Start dopo completed porta l'ultima campagna come precondizione; pause/resume
portano sempre l'ID attivo. Nessun batch_size o campo aggiuntivo.
Un comando scaduto resta immutabile e viene consegnato: VPS2 lo rifiuta con ricevuta.
`last_sequence` non sostituisce l'ACK: si riconsegna il più vecchio non confermato.
Cursori oltre la sequenza emessa, regressioni del cursore o osservazioni precedenti
all'ultimo snapshot accettato sono rifiutati con 409. Non si rinumera la coda.

### POST `/wp-json/oap-bridge/v1/regeneration/ack`

Ricevuta completa del client VPS2:
`protocol`, `worker_id`, `command_id`, `idempotency_key`, `sequence`,
`outcome` (applied/rejected), `error_code` (null oppure codice previsto dal contratto),
`processed_at`, `campaign`, `receipt_hash` (SHA256 canonico generato da VPS2).
Il digest viene trattato come identificatore opaco, come consentito dal contratto;
TLS e Bearer autenticano il messaggio. Si controllano identità e associazione con
il comando, schema ed esito applicato. La ricevuta viene committata prima del 200.
La risposta riporta soltanto protocol, worker_id, command_id, idempotency_key,
sequence, receipt_hash. Stesso ACK restituisce stessa conferma; stesso ID con
ricevuta modificata, anche a hash invariato, produce 409. Anche rejected chiude
il comando. Lo snapshot storico della ricevuta non sovrascrive lo stato poll.

Non si introduce un terzo formato per pubblicare lo stato: l'endpoint poll riceve
lo stato aggiornato, come implementato dal client VPS2 condiviso.

## Persistenza, concorrenza e aggiornamento da 1.4.0

Due tabelle dedicate InnoDB create con dbDelta su plugins_loaded (anche negli
aggiornamenti senza riattivazione):

- `<prefix>oap_regeneration_workers`: worker, ultima sequenza emessa, snapshot con
  observed_at, received_at e last_sequence.
- `<prefix>oap_regeneration_commands`: worker/sequence, command_id e chiave unici,
  payload immutabile e ricevuta immutabile. Nessuna cancellazione automatica.

Mutex DB specifico per sito e transazione sulla stessa connessione mysqli,
senza riconnessione automatica. Inserimento comando e avanzamento sequenza sono
atomici; errore/crash prima del commit esegue rollback senza buchi. Database o
mutex indisponibile: nessun nuovo comando. Richiesta connessione MySQL/MariaDB
stabile durante la transazione; proxy che cambiano connessione per query non supportati.

Il browser crea la chiave prima del POST locale e la conserva in sessionStorage
(e in memoria se storage non disponibile) per ritentare lo stesso intento dopo
errori di rete/5xx. PHP riutilizza il comando per la stessa chiave/precondizioni;
nuove chiavi sono bloccate finché il precedente comando non è confermato e un
poll successivo non sincronizza il cursore. Questo copre doppio clic, schede
concorrenti, timeout e risposte locali perse. Scadenze non eliminano comandi.

L'opzione `oap_regeneration_pending` della 1.4.0, se presente, blocca il pannello
con messaggio di riconciliazione: non viene cancellata o convertita in un nuovo
comando. Prima di usare il polling riconciliare manualmente la vecchia chiave
con la ricevuta VPS2 e conservare un backup dell'opzione; rimuoverla soltanto
quando l'esito è stato accertato. Anche il vecchio snapshot resta nel DB.
Non ripristinare soltanto una delle due basi dati dopo aver emesso comandi.

## Pannello e contatori

Solo utenti `manage_options`, metodi controllati e nonce `oap_regeneration`.
GET stato legge il DB locale; solo POST admin espliciti creano comandi. Nessun
comando da poll, attivazione, deploy o WP-Cron. Avvio con conferma browser.
Polling del pannello ogni 20 secondi anche in idle/completed per rilevare
indisponibilità o cambiamenti esterni. Un comando accodato non è mostrato come
eseguito: si attende ricevuta e successivo stato corrente VPS2.

`campaign` conserva esattamente lo stato VPS2: idle minimal oppure running,
paused, completed con date Unix UTC, checkpoint e contatori originali.

| Contatore | Semantica |
| --- | --- |
| total | Prodotti nello snapshot VPS2 |
| examined | Render conclusi o falliti definitivamente; può includere pubblicazioni pendenti |
| updated | Manutenzione confermata dopo chiusura della pubblicazione |
| unchanged | Nessuna nuova revisione |
| superseded | Aggiornamento ordinario successivo confermato |
| errors | Prodotti con errore corrente, anche transitorio; può diminuire |
| remaining | Prodotti senza esito terminale, comprese pubblicazioni pendenti |
| to_examine | selected + queued |
| awaiting_publication | awaiting + confirmed |
| queued | Cumulativo dei prodotti con job associato |

Errori terminali derivati: `total - remaining - updated - unchanged - superseded`.
Non si sommano errors agli altri contatori: non sono categorie disgiunte.
Avanzamento: `(total - remaining) / total`, zero per idle/vuoto in corso e 100%
per completed vuoto. `completed` può includere errori terminali.
Il pannello distingue errori correnti/terminali e superseded.

Snapshot assente, ricezione vecchia o observed_at vecchio oltre la soglia:
"VPS2 non comunica: dati non aggiornati", ultima ricezione e ultimo stato
conservati, comandi disabilitati. Anche una ritrasmissione vecchia appena ricevuta
non appare aggiornata. Solo presentazione delle date in Europe/Rome.
La pausa ferma nuovi lotti; i job già accodati possono finire e completare la campagna.

## Test riproducibili

Solo ambiente usa-e-getta, mai server o credenziali reali:

```sh
bash occhioaiprezzi-bridge-receiver/tests/setup.sh
bash occhioaiprezzi-bridge-receiver/tests/run.sh
node occhioaiprezzi-bridge-receiver/tests/regeneration-ui.cjs
# Clone separato vps-ponte al commit verificato, poi uv sync --frozen in quel clone.
/PERCORSO/vps-ponte/.venv/bin/python occhioaiprezzi-bridge-receiver/tests/regeneration_vps2.py /PERCORSO/vps-ponte
bash occhioaiprezzi-bridge-receiver/tests/teardown.sh
```

Il collaudo Python rifiuta commit VPS2 diversi dal riferimento; usa vere richieste
HTTP locali, REST WP e login/nonce/AJAX admin. Il trasporto mappa soltanto l'host
HTTPS di test sul listener HTTP locale con terminazione TLS fidata simulata dal
wp-config fixture; non collauda certificati o firewall di produzione. Configurazione
temporanea ripristinata con finally; tabelle di test pulite. La suite generale
copre il ricevitore, pubblicazioni/idempotenza pages, SEO, sitemap e affiliati.
Risultati finali in `../docs/validation-1.4.1.md`.

## Aggiornamento futuro (non eseguito)

1. Backup plugin/configurazione e DB WordPress, incluso pending/snapshot 1.4.0;
   backup VPS2 di campagne, ricevute, cursori e checkpoint. Riconciliare eventuali
   vecchi comandi incerti, senza rigenerare chiavi.
2. Fermare solo regenerate per il cambio coordinato; distribuire il plugin e il
   commit VPS2 compatibile con polling inizialmente disabilitato. Le migrazioni
   sono additive e non toccano schede/artefatti. Nessun auto-start.
3. Configurare token/worker su WordPress; verificare TLS, Authorization, migrazione
   delle due tabelle e i normali GET/PUT pages con la configurazione esistente.
4. Configurare le tre variabili polling su VPS2 e riavviare solo regenerate.
   Con coda vuota verificare snapshot nel pannello e assenza di nuove campagne.
   Verificare VPS2 → WordPress sul percorso effettivo; nessun test produttivo qui.
5. Avviare una campagna solo quando deliberato dall'amministratore; monitorare
   ricevuta, stato, remaining e checkpoint. Non usare il deploy come avvio.

## Rollback

Fermare regenerate, disabilitare insieme URL/token/worker polling su VPS2.
Conservare entrambe le nuove tabelle WordPress, tutte le ricevute/cursori VPS2,
le vecchie opzioni e i dati delle campagne. Tornare al codice precedente senza
cancellare/rinumerare comandi; il pannello 1.4.0 necessita della vecchia rete/API
outbound, quindi lasciarlo inutilizzato durante il rollback. Nessun downgrade
DB o ripristino parziale della coda. Prima di riabilitare il polling riconciliare
la sequenza e le ricevute nelle due basi dati, riutilizzando le identità originali.
Il lavoro già accodato su VPS2 segue le regole esistenti; il rollback WordPress
non lo annulla e non modifica i contenuti già ricevuti.
