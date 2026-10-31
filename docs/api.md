# API privata OAP Bridge Receiver 1.1

Il ricevitore salva e serve documenti già decisi dalla VPS Ponte. Non consulta WooCommerce, non verifica l'ammissibilità commerciale dei GTIN, non genera HTML/SEO, non gestisce eventi di catalogo e non decide se pubblicare.

Base HTTPS `/wp-json/oap-bridge/v1/`; `Authorization: Bearer <segreto>` obbligatorio. Cookie/nonce non sostituiscono il Bearer. Risposte private ed errori: `Cache-Control: no-store, private`. Limite richiesta 1 MiB. Scritture disabilitate per default (`OAP_BRIDGE_WRITES_ENABLED` deve essere esattamente true).

## Compatibilità e migrazione

Restano `protocol: oap-wp-1`, GET/PUT pages, GTIN a 14 cifre, ACK, versioni, hash e Idempotency-Key. Cambiamenti intenzionalmente incompatibili: il PUT richiede `operation`; GET `gtins/{gtin}/eligibility` è rimosso (404), non emette attestazioni di inventario. `eligibility_proof` eventualmente presente viene ignorata e non salvata. Nessun fallback upsert. Il vecchio client Ponte deve essere aggiornato prima di usare questa versione: vedere [ponte-changes.md](ponte-changes.md).

## GET pages/{gtin}

Assente: 200 con `{"gtin":"00012345678905","version":0,"installed_hash":"","remote_id":"","ownership":"absent","operation":"absent"}`.

Gestita: versione massima installata (anche inattiva), hash, remote_id stabile `oap:<gtin>`, ownership `bridge`, operation `managed`. Proprietario estraneo nell'indice: 409 `oap_foreign_owner`. GET non adotta né modifica post WordPress.

## PUT pages/{gtin}

`Content-Type: application/json`, `Idempotency-Key: <content_hash>:<version>`.

| Campo richiesto | Contratto |
| --- | --- |
| protocol | `oap-wp-1` |
| operation | `create` oppure `update`, scelto dalla VPS |
| gtin | stringa di 14 cifre coincidente con path; solo validazione strutturale, senza checksum/ammissibilità |
| slug | slug ASCII `[a-z0-9]+(?:-[a-z0-9]+)*`, massimo 180 caratteri |
| version | intero positivo entro PHP_INT_MAX |
| content_hash | 64 caratteri hex minuscoli, digest opaco del Ponte |
| html | frammento HTML finale pronto, anche per inactive; non viene rigenerato o filtrato in scrittura |
| seo | oggetto finale descritto sotto |
| state | `active` / `inactive` |
| redirects | elenco completo degli alias da redirigere, massimo 500 slug, nessun URL |
| removal_policy | `temporary` / `gone` |

SEO: `title` stringa non vuota, `canonical` URL HTTP(S) assoluto sicuro, `indexable` booleano e `modified_at` epoch intero >=0 obbligatori per **entrambi** gli stati. `inactive` richiede `indexable:false`. Il canonical è quello ricevuto, anche se diverso dall'URL locale; non viene ricostruito. Opzionali: description, h1, brand, image, category (stringhe <=4096 byte), gtin coincidente con path, jsonld oggetto o null. Image e URL JSON-LD sono validati. JSON-LD viene serializzato con escape sicuri, senza deduzioni o generazione schema. Il vecchio flag locale `OAP_BRIDGE_JSONLD_ENABLED` non condiziona più il rendering: l'omissione/null di jsonld si decide sulla VPS.

HTML: la validazione tecnica rifiuta PHP, script, handler on*, URL pericolosi e markup fuori allowlist. Se `wp_kses` cambierebbe il frammento, il PUT fallisce con 400 `oap_unsafe_html`: non viene salvata una versione riscritta. Allowlist: article, section, div, span, p, h1/h2/h3, strong, em, ul/ol/li, br, table/caption/thead/tbody/tr/th/td con class; a con href/rel/title; img con src/alt/loading/width/height. URL assoluti HTTP(S), nessuna credenziale. La VPS deve produrre HTML conforme.

### Precondizioni e retry

Sotto lock SQL, il ricevitore verifica proprietà, versione, GTIN unico, slug e pagine native estranee. `create` su identità esistente: 409 `oap_page_exists`; `update` su assente: 409 `oap_page_absent`. Non sceglie un'operazione alternativa.

Eccezione per recuperare un ACK perso: stessa versione, stesso hash e stesso documento restituiscono `unchanged`, anche per un retry create. Stessa versione/hash diverso: `oap_version_conflict`; versione inferiore: `oap_remote_ahead`. Versione maggiore/hash uguale richiede `update` e documento identico: avanza solo il cursore (`cursor_advanced`). Hash riutilizzato con HTML, SEO, stato, slug o alias diversi: `oap_hash_conflict`. Per il confronto del documento si escludono solo operation, version, content_hash ed eligibility_proof; ordine delle chiavi JSON irrilevante. La VPS deve cambiare hash per ogni cambiamento del documento, incluso modified_at.

Creazione: 201; aggiornamento/retry/cursore: 200. ACK esempio:

```json
{"gtin":"00012345678905","version":2,"installed_hash":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","remote_id":"oap:00012345678905","ownership":"bridge","operation":"updated"}
```

Le tabelle dedicate hanno GTIN e slug come chiavi primarie. La serializzazione delle scritture e il lock READ sui post proteggono da collisioni native concorrenti; non viene letto/bloccato postmeta. Non vengono mai creati o aggiornati post/prodotti WordPress. Alias storici restano riservati al GTIN anche dopo la rimozione dall'elenco redirects.

## Rendering e sitemap

Il documento usa HTML, title, description, canonical e jsonld ricevuti, anche se inactive. Nessun messaggio o offerta viene generato localmente. `active`: 200; `inactive/temporary`: 200 noindex; `inactive/gone`: 410 noindex, anche sugli alias esplicitamente ricevuti. Un alias in redirects produce 301 diretto all'URL dello slug corrente, salvo gone (410). Un vecchio slug omesso dall'elenco resta riservato ma non redirige più (404 normale WP). Il semplice cambio slug non aggiunge redirect: deve inviarlo la VPS. La normalizzazione tecnica dello slash resta 301.

Sitemap: solo righe active e indexable, URL canonical e modified_at ricevuti. Nessuna consultazione WooCommerce in lettura, sitemap o scrittura. Le pagine restano nello stato ricevuto fino al prossimo PUT della VPS. Il wrapper HTML e gli escape degli attributi sono tecnici; non vengono eseguiti shortcode, wp_head o the_content.

## Errori

Formato WordPress `{code,message,data:{status}}`, messaggio generico senza SQL/segreti.

| HTTP | Codici |
| --- | --- |
| 400 | oap_missing_field, oap_invalid_payload, oap_invalid_gtin, oap_invalid_slug, oap_invalid_json, oap_invalid_idempotency_key, oap_unsafe_html, oap_invalid_url, oap_invalid_seo, oap_invalid_jsonld |
| 401 | oap_unauthorized |
| 403 | oap_https_required |
| 409 | oap_page_exists, oap_page_absent, oap_remote_ahead, oap_version_conflict, oap_hash_conflict, oap_foreign_owner, oap_foreign_page, oap_slug_conflict |
| 413 / 415 | oap_request_too_large / oap_json_required |
| 503 | oap_writes_disabled, oap_not_configured, oap_database_unsupported, oap_database_unavailable, oap_transaction_in_progress, oap_storage_unavailable, oap_storage_not_private, oap_artifact_integrity, oap_invalid_public_base, oap_unavailable |

Non vengono più emessi oap_woocommerce_present, oap_inventory_unavailable o errori proof. Un 409 richiede riconciliazione sulla VPS, mai conversione automatica dell'operazione nel plugin.
