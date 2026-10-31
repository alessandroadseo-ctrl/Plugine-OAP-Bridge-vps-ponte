# API privata OAP Bridge Receiver

Base fissa HTTPS: `/wp-json/oap-bridge/v1/`. Autenticazione `Authorization: Bearer <segreto>` su tutte le rotte. Non sono accettati cookie/nonce come alternativa. Ogni risposta privata, inclusi errori, usa `Cache-Control: no-store, private`.

## GET gtins/{gtin}/eligibility

GTIN: stringa di 14 cifre, secondo lo schema reale del Ponte.

```json
{"gtin":"00012345678905","woocommerce_present":false,"proof":"base64.hmac"}
```

200 con `woocommerce_present:true` e `proof:""` se inventario occupato. 503 se Woo, adattatore o lock SQL non sono disponibili. Proof HMAC legata a protocollo, metodo PUT, risorsa pages, GTIN, istanza WordPress ed expiry di 120 secondi. La proof non sostituisce il ricontrollo SQL durante la scrittura. Rotazione token invalida le proof.

## PUT pages/{gtin}

`Content-Type: application/json`; `Idempotency-Key: <content_hash>:<version>`. Massimo 1 MiB. Campi richiesti:

| Campo | Tipo/semantica |
| --- | --- |
| protocol | esattamente `oap-wp-1` |
| gtin | stringa, coincide con path, 14 cifre |
| slug | `[a-z0-9]+(?:-[a-z0-9]+)*`, massimo 180 caratteri |
| version | intero JSON positivo, entro PHP_INT_MAX su runtime 64 bit |
| content_hash | 64 caratteri esadecimali minuscoli, digest opaco del Ponte |
| html | stringa, frammento filtrato con allowlist; non codice |
| seo | oggetto; title/description/canonical/h1/gtin/brand/image/category/indexable/modified_at/jsonld |
| state | `active` oppure `inactive` |
| redirects | array di slug storici, massimo 500, nessun URL |
| removal_policy | `temporary` oppure `gone` |
| eligibility_proof | proof recente per questo GTIN/operazione |

SEO attivo: title non vuoto; canonical esattamente `OAP_BRIDGE_PUBLIC_BASE/<slug>/`; indexable booleano; modified_at intero epoch >=0. Image opzionale HTTP(S). JSON-LD oggetto opzionale, serializzato in modo sicuro; non stringa HTML. Inactive accetta il SEO minimo del renderer `{gtin,indexable:false,modified_at}`. HTML inactive non viene mostrato.

Risposta creazione 201, aggiornamento/ritentativo/cursore 200:

```json
{"gtin":"00012345678905","version":2,"installed_hash":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","remote_id":"oap:00012345678905","ownership":"bridge","operation":"updated"}
```

`operation`: `created`, `updated`, `unchanged`, `cursor_advanced`. Campi aggiuntivi sono compatibili col client originale, che verifica GTIN/version/installed_hash. Per un retry identico viene restituito lo stesso stato installato, con operation unchanged. Nessuna seconda identità viene creata.

Versione superiore/hash uguale conserva il documento pubblico precedente (inclusi slug/stato/lastmod), avanza soltanto il cursore. Non riutilizzare un hash per un cambiamento pubblico.

Proof verificata anche all'interno del lock. Un GTIN Woo blocca prima creazione e qualunque scrittura attiva; è consentito solo disattivare una pagina già gestita, con versione maggiore e proof valida. Il client attuale non invia questa disattivazione quando l'eligibility è occupata: serve l'arbitraggio descritto in ponte-changes.md.

## GET pages/{gtin}

Assente è **200**, non 404 (il client richiede un oggetto di riconciliazione):

```json
{"gtin":"00012345678905","version":0,"installed_hash":"","remote_id":"","ownership":"absent","operation":"absent"}
```

Gestita: GTIN, versione massima anche se inactive, installed_hash, remote_id, ownership bridge e operation managed. Owner differente nell'indice: 409 foreign_owner. Un post nativo estraneo non viene cercato per GTIN in questo endpoint: la collisione sullo slug viene scoperta nel PUT. Non c'è adozione automatica di contenuti esterni.

## Errori stabili

Formato WordPress `{code,message,data:{status}}`. Message generico, nessun SQL, percorso, stack trace o segreto. Classificazione:

| HTTP | Codici principali | Interpretazione worker |
| --- | --- | --- |
| 400 | oap_invalid_gtin, oap_invalid_slug, oap_invalid_payload, oap_missing_field, oap_invalid_json, oap_invalid_idempotency_key, oap_unsafe_html, oap_invalid_url, oap_invalid_seo, oap_invalid_canonical, oap_invalid_jsonld | payload da correggere |
| 401 | oap_unauthorized | token assente/errato |
| 403 | oap_https_required, oap_invalid_proof | HTTPS/proof da correggere o rinnovare |
| 409 | oap_remote_ahead | stato remoto più recente, intervento/reconcile |
| 409 | oap_version_conflict | stessa versione con hash differente |
| 409 | oap_foreign_owner, oap_foreign_page | ownership estranea, non sovrascrivere |
| 409 | oap_slug_conflict | slug/alias di altro GTIN |
| 409 | oap_woocommerce_present | inventario Woo occupato |
| 413 | oap_request_too_large | superato limite |
| 415 | oap_json_required | media type errato |
| 503 | oap_writes_disabled, oap_not_configured, oap_inventory_unavailable, oap_database_unsupported, oap_database_unavailable, oap_transaction_in_progress, oap_storage_unavailable, oap_storage_not_private, oap_artifact_integrity, oap_invalid_public_base, oap_unavailable | sospeso/non disponibile, fail closed |

Il client originale conserva solo `remote_http_<status>` e tratta tutti i 409 come ritentabili: la distinzione dei codici è disponibile sul wire, ma richiede un miglioramento del client per l'arbitraggio automatico. Non è stato cambiato alcun file Python del Ponte.
