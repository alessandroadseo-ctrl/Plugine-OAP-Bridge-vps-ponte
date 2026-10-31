# API — ricevitore esclusivo VPS, plugin 1.1.0

Il plugin esegue comandi autenticati della VPS Ponte. Non interroga WooCommerce, non decide l'ammissibilità, non sceglie l'operazione e non genera contenuti o SEO. La VPS è responsabile di queste decisioni, dei dati di inventario autorevoli e della gestione eventi.

## Compatibilità e migrazione

Base HTTPS invariata `/wp-json/oap-bridge/v1/`; `protocol:"oap-wp-1"`, GTIN, slug, version, content_hash, html, seo, state, redirects, removal_policy e ACK rimangono invariati. **Il flusso di pubblicazione del client precedente NON è compatibile senza modifiche**:

- `GET gtins/{gtin}/eligibility` è una rotta privata ritirata: **410 `oap_eligibility_moved_to_vps`**, senza proof o risultati Woo sintetici. Non autorizza alcuna scrittura.
- PUT richiede il nuovo campo **`operation:"create"|"update"`**, scelto dalla VPS.
- `eligibility_proof` non è più richiesto né verificato. Un valore legacy stringa ≤512 caratteri o null viene tollerato, ignorato e non salvato. Non ha valore di autorizzazione.

Non abilitare pubblicazione finché non sono stati applicati gli interventi in [ponte-changes.md](ponte-changes.md). La stringa del protocollo resta invariata per conservare i bundle e l'ACK; questa estensione del comando deve essere coordinata fra i due componenti.

## Autenticazione e limiti

`Authorization: Bearer <segreto>` su tutte le rotte; solo HTTPS e confronto hash_equals. Nessuna alternativa cookie/nonce. Token fuori dal sorgente, almeno 32 caratteri. Tutte le risposte API, inclusi errori, sono no-store/private. Scritture disabilitate per default (`OAP_BRIDGE_WRITES_ENABLED=false`), come interruttore tecnico di manutenzione, non come motore di pubblicazione.

PUT: `Content-Type: application/json`; `Idempotency-Key: <content_hash>:<version>`; massimo 1 MiB. GTIN stringa di 14 cifre; slug ASCII `[a-z0-9]+(?:-[a-z0-9]+)*`, massimo 180 caratteri; al massimo 500 alias. Impostare anche il limite body sul proxy, prima del caricamento WordPress.

## GET pages/{gtin}

Riporta lo stato tecnico; la scelta successiva compete alla VPS. Assente è 200, non 404:

```json
{"gtin":"00012345678905","version":0,"installed_hash":"","remote_id":"","ownership":"absent","operation":"absent"}
```

Gestita: versione massima (anche inactive), installed_hash, remote_id `oap:<gtin>`, ownership `bridge`, operation `managed`. Owner estraneo nell'indice: 409 `oap_foreign_owner`. Questo endpoint non inventaria i prodotti Woo e non adotta post esistenti. Una collisione con un post nativo viene rifiutata durante PUT.

## PUT pages/{gtin}

Campi obbligatori: `protocol`, `operation`, `gtin`, `slug`, `version`, `content_hash`, `html`, `seo`, `state`, `redirects`, `removal_policy`.

```json
{
  "protocol":"oap-wp-1",
  "operation":"create",
  "gtin":"00012345678905",
  "slug":"prodotto-esempio",
  "version":1,
  "content_hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "html":"<article><h1>Titolo pronto dalla VPS</h1></article>",
  "seo":{"title":"SEO pronto dalla VPS","description":"Descrizione pronta","canonical":"https://receiver.test/prodotti-ponte/prodotto-esempio/","indexable":true,"modified_at":1700000000,"jsonld":null},
  "state":"active",
  "redirects":[],
  "removal_policy":"temporary"
}
```

`create`: deve mancare l'identità. `update`: deve esistere un'identità appartenente al Ponte. Nessuna conversione automatica fra le due operazioni. Il controllo è dentro la stessa transazione/lock della scrittura, quindi una decisione remota divenuta obsoleta viene rifiutata invece di sovrascrivere o duplicare.

ACK 201 per create confermata; 200 per update, retry e avanzamento cursore:

```json
{"gtin":"00012345678905","version":1,"installed_hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","remote_id":"oap:00012345678905","ownership":"bridge","operation":"created"}
```

`operation` nell'ACK: created/updated/unchanged/cursor_advanced. La proprietà viene sempre verificata prima di confermare un retry. Stessa versione/hash già installati → unchanged anche se il comando ritentato è create dopo un ACK perso (o la VPS ha riconciliato verso update). Nessuna scrittura o invalidazione. Stessa versione/hash diverso → 409 version_conflict. Versione minore → 409 remote_ahead. Successivamente si controlla la precondizione create/update: create su esistente → 409 page_exists, update su assente → 409 page_absent. Versione maggiore/hash uguale → solo cursore, documento pubblico invariato. **La VPS deve cambiare hash per qualunque cambiamento pubblico**, inclusi HTML, SEO, stato o redirect.

## Contenuto, SEO, stati e redirect

HTML è un frammento già pronto, conservato byte per byte dopo validazione. La allowlist include article/section/div/span/p/h1–h3/strong/em/ul/ol/li/br/table/caption/thead/tbody/tr/th/td (class), a (href/rel/title) e img (src/alt/loading/width/height). Se wp_kses lo modificherebbe, il payload viene **rifiutato**, non riscritto. Script/PHP/iframe/form/SVG/event handler e URL non HTTP(S) non sono consentiti. La VPS deve produrre questo sottoinsieme HTML; markup innocuo ma non conforme può ricevere 400. Nessun the_content, shortcode o PHP del payload viene eseguito.

SEO: oggetto, indexable booleano e modified_at epoch intero >=0; GTIN eventuale coincidente col path. Active richiede title e canonical non vuoti. Canonical è l'URL HTTP(S) sicuro **ricevuto**, può differire dalla rotta pubblica: non viene rigenerato o usato per redirect. Title/description/h1/brand/image/category sono conservati, mai dedotti dal corpo. JSON-LD oggetto opzionale, serializzato con escaping HTML/script/Unicode, senza generare Product/Offer. Campi omessi non vengono riempiti; un corpo vuoto resta vuoto. Il vecchio switch locale OAP_BRIDGE_JSONLD_ENABLED è ignorato: la VPS invia oggetto o null.

Inactive richiede indexable=false come invariante strutturale del contratto. `temporary` applica HTTP 200, `gone` applica 410; vengono resi HTML e SEO ricevuti, senza messaggio locale o contenuto di fallback. Per non mostrare offerte la VPS deve rimuoverle dal bundle: il ricevitore non interpreta né rigenera le offerte. Il vecchio renderer inactive con HTML vuoto e SEO minimo resta valido, ma produrrà un corpo vuoto.

Sitemap nativa WordPress: soltanto state=active/indexable=true, loc dal canonical SEO ricevuto e lastmod dal modified_at ricevuto. Nessun controllo inventario per visita o sitemap. In presenza di WooCommerce, in sua assenza o dopo modifiche dei suoi metadati, l'esito rimane quello dell'ultimo comando VPS.

`redirects` è l'elenco **completo** degli alias attualmente richiesti, non un delta. Soltanto gli alias ricevuti fanno 301 diretto a base pubblica configurata + slug corrente, su host locale. Nessun redirect a canonical esterni. Un cambio slug non aggiunge automaticamente il precedente all'elenco. Le vecchie prenotazioni restano nel registro globale per impedire appropriazioni da altri GTIN; alias non più richiesti rispondono 404. Gone applica 410 alla rotta corrente e agli alias ancora richiesti. URL o slug di un altro GTIN vengono rifiutati. La normalizzazione tecnica della slash finale è invariata.

## Errori stabili

Formato WordPress `{code,message,data:{status}}`, messaggio generico senza SQL/token/stack trace.

| HTTP | Codici principali |
| --- | --- |
| 400 | oap_missing_field, oap_invalid_payload, oap_invalid_gtin, oap_invalid_slug, oap_invalid_json, oap_invalid_idempotency_key, oap_unsafe_html, oap_invalid_url, oap_invalid_seo, oap_invalid_jsonld |
| 401 | oap_unauthorized |
| 403 | oap_https_required |
| 409 | oap_page_exists, oap_page_absent, oap_remote_ahead, oap_version_conflict, oap_foreign_owner, oap_foreign_page, oap_slug_conflict |
| 410 | oap_eligibility_moved_to_vps (solo rotta eligibility ritirata) |
| 413 / 415 | oap_request_too_large / oap_json_required |
| 503 | oap_writes_disabled, oap_not_configured, oap_database_unsupported, oap_database_unavailable, oap_transaction_in_progress, oap_storage_unavailable, oap_storage_not_private, oap_artifact_integrity, oap_invalid_public_base, oap_unavailable |

Non esistono più rifiuti oap_woocommerce_present/oap_inventory_unavailable/oap_invalid_proof. I 409 sono conflitti tecnici da riconciliare sulla VPS, non decisioni editoriali del ricevitore.
