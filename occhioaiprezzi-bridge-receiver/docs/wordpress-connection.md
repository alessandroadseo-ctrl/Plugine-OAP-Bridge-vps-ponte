# Collegamento WordPress ↔ OAP Bridge — 1.1.1

## Token e base REST

1. Installare lo ZIP dal pannello Plugin → Aggiungi plugin → Carica plugin (sostituire la versione esistente). Conservare le configurazioni e i dati esistenti.
2. Aprire **OcchioAiPrezzi Bridge → Impostazioni**, come amministratore con `manage_options`.
3. Preparare un token casuale dedicato, ad esempio con `openssl rand -hex 32` su una macchina fidata. Copiarlo nel gestore dei segreti del Ponte come `BRIDGE_WP_TOKEN`, incollarlo in **Nuovo token condiviso**, poi premere **Salva token**. Il token richiede 32–4096 byte senza spazi; quello dell'esempio è lungo 64 caratteri. Non salvarlo in Git.
4. Dopo il salvataggio il valore non è visualizzabile: WordPress conserva solo SHA-256, opzione non autoload. Campo vuoto = nessuna modifica. Un nuovo token sostituisce immediatamente quello precedente: coordinare la rotazione con il Ponte.
5. Se è definita `OAP_BRIDGE_TOKEN` in wp-config.php/configurazione del server, resta autoritativa: il pannello lo segnala e non consente sostituzioni inefficaci. Per passare al pannello, rimuovere la costante in una manutenzione coordinata e salvare il token.

Copiare **esattamente l'URL mostrato dal pannello** in `BRIDGE_WP_BASE_URL`. Per WordPress nella root con permalink REST standard:

```text
https://<dominio-wordpress>/wp-json/oap-bridge/v1/
```

Il namespace è `oap-bridge/v1`; il client deve aggiungere `pages/{gtin}`, senza duplicare `/wp-json` o `pages`. Se WordPress è in sottocartella, anche quella appartiene alla base. Con permalink semplici WordPress può mostrare `?rest_route=...`: un client che concatena percorsi richiede permalink REST standard; non concatenare alla cieca una query string. Il dominio effettivo e l'eventuale sottocartella non sono presenti nel repository: non è possibile certificare qui un URL di produzione completo.

## Verifica senza pubblicare

Mantenere `BRIDGE_PUBLISH_ENABLED=false`; non consumare i job pending. Anche `OAP_BRIDGE_WRITES_ENABLED` resta disabilitato se non già configurato. GET autenticato a:

```text
<BRIDGE_WP_BASE_URL>pages/03253561929809
<BRIDGE_WP_BASE_URL>pages/03253561756726
```

Usare HTTPS verificato e `Authorization: Bearer <token>`. GET legge solo l'indice WordPress, non importa le offerte e non cambia i job. Un prodotto assente restituisce HTTP 200, ownership/operation `absent`, version 0 e remote_id/installed_hash vuoti. Una pagina gestita restituisce ownership `bridge`, operation `managed` e i valori correnti. Header assente/errato: 401; configurazione token mancante: 503 (con Bearer sintatticamente valido); HTTP: 403.

WordPress acquisisce `HTTP_AUTHORIZATION` e `REDIRECT_HTTP_AUTHORIZATION` in `WP_REST_Server::get_headers()`, quindi `WP_REST_Request::get_header('authorization')` li legge senza distinguere maiuscole/minuscole. Il plugin usa questa API nativa. Il web server deve inoltrare l'header a PHP: se necessario su nginx/FastCGI usare `fastcgi_param HTTP_AUTHORIZATION $http_authorization;`, su Apache/CGI `CGIPassAuth On` nella configurazione appropriata. Non accettare token da query, cookie o header alternativi. TLS dietro proxy deve essere configurato dal gestore del sito con proxy fidati; il plugin non si fida di X-Forwarded-Proto arbitrario.

## Contratto esistente e prerequisiti

Nessuna modifica alla struttura ACK: `gtin`, `version`, `installed_hash`, `remote_id`, `ownership`, `operation`. Sono preservati tutti i controlli di proprietà/versione/hash/conflitto, gli slug storici e le pagine/prodotti nativi. Il ricevitore usa l'indice e gli artefatti esistenti: nessun secondo sistema.

Il client Python non è in questo repository: la compatibilità del suo payload non è verificabile soltanto conoscendo HTTPS/Bearer/PUT. Il contratto **già presente** richiede `protocol=oap-wp-1`, `operation=create|update` esplicita, HTML/SEO finali e `Idempotency-Key: <content_hash>:<version>`; non offre l'endpoint eligibility né un upsert implicito. Confrontare [api.md](api.md) e [ponte-changes.md](ponte-changes.md) prima di abilitare il mittente. Questa release non introduce altre differenze di contratto.

`offers[].availability="unknown"` è accettato e preservato come metadato del documento, senza deduzioni o generazione di offerte/HTML/schema da parte di WordPress. Il Ponte deve fornire HTML prudente (es. “Disponibilità non verificata”) e omettere disponibilità confermate nel JSON-LD. Il campo JSON-LD `availability`, se inviato, richiede già un URL valido: il letterale `unknown` non è un URL schema.org e viene rifiutato. Non trasformarlo in InStock. Il plugin non interpreta la semantica dell'HTML finale ricevuto.

Per un futuro invio autorizzato restano necessari `OAP_BRIDGE_PUBLIC_BASE` (namespace pubblico HTTPS riservato, stesso host) e `OAP_BRIDGE_ARTIFACT_DIR` (directory privata fuori document root), permessi DB InnoDB/LOCK TABLES e abilitazione esplicita delle scritture. Nessuna di queste impostazioni è attivata dal pannello token o da questa consegna.
