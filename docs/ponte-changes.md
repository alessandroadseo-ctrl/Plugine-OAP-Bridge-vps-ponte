# Interventi necessari su vps-ponte — NON applicati

Riferimento letto: `alessandroadseo-ctrl/vps-ponte` commit `69a70c13bf92ea3df5a652e1f18b16dd3293c964`. Questa attività cambia soltanto il repository del plugin WordPress. Nessun file/configurazione/worker della VPS viene modificato o abilitato.

## Contratto da coordinare prima dell'uso

Plugin 1.1.0 resta `oap-wp-1` per bundle, endpoint pages e ACK, ma richiede `operation:create|update` e non fornisce più ammissibilità. Il vecchio `WordPressClient.publish()` si ferma con 410 permanente al GET eligibility: comportamento verificato, intenzionale, senza falsi risultati Woo. Non è un aggiornamento trasparente del vecchio ciclo publish.

## 1. Inventario e ammissibilità sulla VPS

Aggiungere un adattatore VPS, per esempio `app/services/woocommerce_inventory.py`, e relativa configurazione in `app/config.py`. Deve ottenere i GTIN autorevoli di prodotti e varianti dalla fonte **effettivamente disponibile** (API/export/DB autorizzato e configurato), normalizzare i formati e decidere l'ammissibilità prima del comando. Non dedurre la fonte o le chiavi meta dal nome del plugin. In caso di dati mancanti, sorgente indisponibile o stato incerto, la VPS deve sospendere il job senza inviare il PUT.

Nel codice di riferimento non esiste questo adattatore: il solo controllo corrente è la chiamata al ricevitore che ora è ritirata. **La fonte autorevole e la gestione della sua consistenza/race devono quindi essere implementate sulla VPS prima di riabilitare il flusso.** Non sostituire il vecchio endpoint con un stub che restituisce Woo=false e non introdurre un'altra decisione Woo nel plugin.

Per un GTIN passato successivamente a Woo, la VPS deve arbitrare gli eventi e inviare update inactive/nuovo hash/versione maggiore per l'identità Ponte, se questa è la decisione richiesta. Il ricevitore non sopprime più autonomamente pagine o sitemap e non blocca il comando per inventario. Gestire sulla VPS anche riattivazione e perdita di ammissibilità successiva a un retry.

## 2. Scelta create/update in app/workers/runner.py

In `Worker.publish()`, mantenere il controllo publish_enabled e la verifica dell'hash dell'artefatto prima di usarlo. Dopo il controllo autorevole VPS e prima della scrittura:

1. Chiamare `remote.installed(gtin)`; validare GTIN, versione/hash e ownership.
2. ownership=absent, version=0/hash vuoto → preparare `operation="create"`.
3. ownership=bridge → preparare `operation="update"`, solo se la versione locale non è obsoleta.
4. Owner estraneo, ACK malformato, versione remota maggiore, stessa versione/hash differente → fermare e riconciliare, senza pubblicare.
5. Stessa versione/hash già installati dopo timeout → recuperare conferma e completare il job; in alternativa ritentare lo stesso comando: il plugin risponde unchanged.
6. Inviare una copia del bundle con l'operation scelta: `command = {**payload, "operation": operation}`. Operation è metadato di trasporto; non riscrivere l'artefatto verificato né cambiare content_hash per la sola scelta create/update.
7. Verificare l'ACK esatto prima di aggiornare Publication e chiudere il job, come già avviene.

Conservare la serializzazione per GTIN e l'ordinamento eventi sulla VPS. La decisione può diventare obsoleta tra GET e PUT: il plugin rifiuta atomicamente page_exists/page_absent invece di fare upsert. La VPS deve riconciliare il conflitto e scegliere di nuovo, senza sostituire automaticamente i dati remoti. Nessuna migrazione dello schema DB VPS è obbligatoria per il flusso minimo: la scelta può essere ricalcolata da GET dopo un retry; l'ACK idempotente non dipende da una nuova colonna.

In `Worker.reconcile()` preservare remote-ahead permanente, tombstone e la verifica delle versioni. GET assente resta 200/versione0/hash vuoto. Se serve audit dell'operation, registrarlo sulla VPS senza loggare payload, offerte o token.

## 3. app/wordpress/client.py

In `WordPressClient.publish(payload)`:

- Eliminare GET `gtins/<gtin>/eligibility`, la lettura `woocommerce_present`, la dipendenza da proof e duplicate_check_unconfirmed.
- Richiedere un comando già deciso dalla VPS con operation create/update; non scegliere l'operazione in WordPress.
- Fare direttamente PUT `pages/<gtin>` con il comando e `Idempotency-Key=content_hash:version`. Non aggiungere eligibility_proof.
- Conservare Bearer da configurazione, base HTTPS fissa, timeout, divieto di seguire redirect e verifica di GTIN/version/installed_hash dell'ACK.
- Estendere `installed()` per validare ownership absent/bridge e la coerenza con versione/hash, non solo il tipo dei tre campi attuali.
- In `request()` leggere unicamente il code stabile dell'errore (se JSON valido) senza esporre corpo/Authorization nei log. Distinguere page_exists/page_absent da remote_ahead, version_conflict, foreign_owner/foreign_page e slug_conflict: non ritentare indefinitamente ogni 409. 410 sull'eligibility segnala client non migrato, non inventario libero.

Il test locale `tests/http_client.py` usa request()/installed() originali e simula soltanto la preparazione VPS del nuovo comando, per verificare il wire. Non equivale a una modifica del client sul repository vps-ponte.

## 4. Contenuti, SEO e redirect

In `app/renderer/render.py` e template generare l'intero frammento HTML e i metadati finali. Il plugin non riscrive HTML valido e non genera titoli, canonical, schema o messaggi inactive:

- Produrre l'HTML del sottoinsieme accettato in docs/api.md. Se il validatore lo modificherebbe, il ricevitore rifiuta: non usare il plugin come normalizzatore/sanitizzatore editoriale.
- Generare title/description/canonical e JSON-LD completi; null per non emettere JSON-LD. BRIDGE_JSONLD_ENABLED resta l'unico switch applicativo da cui deriva il campo jsonld; lo switch omonimo WordPress è ritirato.
- Il ramo inactive attuale restituisce `""` e SEO minimo. È strutturalmente compatibile, ma produce una pagina vuota: se si vuole un messaggio/titolo, produrli **nel renderer VPS**. Rimuovere offerte/schema non desiderati nel bundle inactive e inviare indexable=false. Nessun fallback WordPress li sostituirà.
- Mantenere modified_at del bundle e aggiornare content_hash/revision per qualsiasi variazione pubblica.
- `redirects` deve essere lo snapshot completo degli alias da attivare. `Worker.apply()` già accumula gli slug precedenti in Product.redirects: continuare a passarli esplicitamente. Un alias omesso dall'ultimo bundle non fa più 301, anche se la prenotazione tecnica resta per impedirne il riutilizzo.
- `app/schemas/events.py`/`app/schemas/export.py` restano schemi d'ingresso del catalogo: operation è un comando di dispatch verso WordPress, non un campo da imporre agli eventi sorgente. Se si introduce uno schema outbound, aggiungere operation lì.

## 5. Test e documentazione VPS da aggiornare

Aggiornare `tests/unit/test_components.py` e le sezioni WordPress di `tests/integration/test_pipeline.py`: nessun GET eligibility, operation esplicita, inventario autorevole sulla VPS anche per varianti e indisponibilità, scelta create/update da GET, gestione dei 409, timeout dopo commit con recupero ACK, contenuto/SEO inactive pronto e alias completi. Preservare i test di ACK malformato, versioni e pubblicazione disabilitata.

Aggiornare `docs/wordpress-contract.md`, `docs/architecture.md`, `docs/operations.md` ed esempi di configurazione: rimuovere la garanzia di controllo Woo atomico sul ricevitore, attribuirla al nuovo flusso VPS con la sua fonte autorevole, descrivere operation, 410 legacy, HTML non riscritto e nessuna soppressione locale.

## Attivazione coordinata e rollback

Tenere worker sospesi e OAP_BRIDGE_WRITES_ENABLED=false durante aggiornamento e collaudo. Preparare prima il nuovo flusso VPS senza abilitarlo, validare il nuovo ricevitore in staging e riabilitare solo con un intervento operativo separato. I dati/index/tombstone esistenti restano leggibili senza migrazione; i bundle storici possono non contenere operation e sono comunque renderizzati. Ritornare al plugin 1.0.0 ripristina anche i vecchi controlli Woo/proof e richiede il client corrispondente: non fare rollback di un componente lasciando l'altro in invio.
