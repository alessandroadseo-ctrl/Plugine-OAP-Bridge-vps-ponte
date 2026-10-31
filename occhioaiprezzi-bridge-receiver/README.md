# OcchioAiPrezzi Bridge Receiver 1.0.0

Plugin WordPress autonomo per `oap-wp-1`. Non è installato sul sito reale e non attiva i worker.

Il riferimento è [vps-ponte](https://github.com/alessandroadseo-ctrl/vps-ponte), commit `69a70c13bf92ea3df5a652e1f18b16dd3293c964`.
Sono stati esaminati contratto WordPress, client, runner, renderer, schema export, schema eventi e test relativi.
Il contratto dice esplicitamente **«Non creare post o prodotti per ogni GTIN»**: questo plugin usa due tabelle dedicate e file JSON statici immutabili privati, senza CPT o prodotti WooCommerce. `remote_id=oap:<gtin>` è un identificativo stabile dell'indice, non un ID post. Il frammento HTML viene servito localmente senza richieste a Ponte/Django e senza caricare il catalogo in RAM.

## Requisiti

- WordPress 6.6+, PHP 8.1+ a 64 bit con mysqli, MySQL 8 o MariaDB 10.6+; tabelle del ricevitore **InnoDB**.
- WooCommerce attivo; metadati GTIN verificati dall'amministratore.
- Directory privata locale POSIX fuori da WordPress e dal document root, scrivibile da PHP. Supporto rename atomico e fsync di file/directory; niente object storage/NFS non verificato.
- Utente DB con SELECT sulle tabelle posts/postmeta, CRUD sulle due tabelle dedicate, CREATE/ALTER per attivazione e privilegio `LOCK TABLES` nel DB WordPress. La sessione deve essere in autocommit e non dentro una transazione esterna.
- HTTPS reale. Se termina sul reverse proxy, configurare `HTTPS=on` dal solo proxy fidato: il plugin non si fida direttamente di `X-Forwarded-Proto` inviato dal chiamante.
- Namespace pubblico **riservato** e non radice, per esempio `/prodotti-ponte`. Allineare `BRIDGE_PUBLIC_BASE_URL` del Ponte allo stesso URL.
- Escludere API, namespace pubblico e sitemap OAP da cache pagina, reverse proxy e CDN. Una cache esterna potrebbe servire offerte rimosse. Il plugin invia `no-store`; per integrazioni puntuali è disponibile `oap_bridge_page_changed`.

## Installazione e prima attivazione senza pubblicazione

1. Eseguire backup coerente di DB e file; provare prima in staging. Lasciare `BRIDGE_PUBLISH_ENABLED=false` sul Ponte.
2. Installare lo ZIP da **Plugin → Aggiungi plugin → Carica plugin**. L'attivazione crea soltanto `<prefix>oap_pages` e `<prefix>oap_slugs`; nessun contenuto viene pubblicato e nessuna chiamata esterna viene inviata.
3. Creare la directory privata con proprietario PHP e permessi 0700. Non metterla in uploads o in una directory web servita. Esempio `/srv/oap-private/receiver`.
4. Configurare in `wp-config.php`, prima di `wp-settings.php`, usando il secret manager dell'host:

```php
// Impostare OAP_BRIDGE_TOKEN nell'ambiente del processo PHP; generare almeno 32 byte casuali.
define('OAP_BRIDGE_TOKEN', (string) getenv('OAP_BRIDGE_TOKEN'));
define('OAP_BRIDGE_ARTIFACT_DIR', '/srv/oap-private/receiver');
define('OAP_BRIDGE_PUBLIC_BASE', 'https://IL-TUO-DOMINIO/prodotti-ponte');
define('OAP_BRIDGE_WRITES_ENABLED', false);
define('OAP_BRIDGE_GTIN_ADAPTER_VERIFIED', false);
define('OAP_BRIDGE_GTIN_META_KEYS', []);
define('OAP_BRIDGE_JSONLD_ENABLED', false);
```

Il token manca o ha meno di 32 caratteri: autenticazione/configurazione chiusa. Nessun token va inserito nel repository o nei log. Il codice non scrive log di request o header; disabilitare anche il logging Authorization sul proxy.

5. Verificare configurazione e proprietario directory. `GET pages/<gtin>` autenticato deve restituire versione 0 per un GTIN assente, senza creare file o pagine. Un `PUT` restituisce `503 oap_writes_disabled`.
6. Verificare l'adattatore GTIN come descritto sotto. Solo dopo impostare le chiavi effettive e `OAP_BRIDGE_GTIN_ADAPTER_VERIFIED=true`; provare l'eligibility su prodotto semplice, variante e GTIN assente. Questo endpoint rimane di sola lettura rispetto ai dati prodotto.
7. Verificare privilegi DB/lock nell'ambiente isolato con la suite. Le chiavi GTIN e il namespace reali non sono stati dedotti da questo repository, che non contiene il sito.
8. Dopo il collaudo del sito e l'arbitraggio SEO della policy temporary, l'amministratore può abilitare separatamente `OAP_BRIDGE_WRITES_ENABLED=true` e, successivamente, i worker. **Questa consegna non esegue questi passi sul sito reale.**

## Adattatore WooCommerce

Non esiste una chiave universale. Cercare nei plugin/importer del sito chi scrive GTIN, poi confrontare il dato di un prodotto semplice e di una variante noti con `wp post meta list <ID>` in staging (non condividere dump con credenziali). Per esempio `_global_unique_id` è una possibile chiave, non una configurazione automatica. Elencare tutte le chiavi autorevoli, al massimo 20.

L'adattatore incluso supporta **soltanto metadati scalari in wp_postmeta**: numeri GTIN, zeri iniziali, spazi ASCII e trattini. Il protocollo usa 14 cifre. Il confronto normalizza gli zeri, quindi EAN-13 e GTIN-14 con prefisso zero corrispondono. Non applica checksum GS1 perché nemmeno lo schema del Ponte lo impone. Controlla product e product_variation, anche draft/private/trash: scelta conservativa.

Dati serializzati/JSON, tabelle custom, indici remoti o separatori diversi richiedono un altro adattatore **e il suo schema di lock**. In questi casi mantenere `OAP_BRIDGE_GTIN_ADAPTER_VERIFIED=false`. Non autorizzare l'adattatore incluso se non rappresenta l'intero inventario. Non usa cache negative o funzioni WooCommerce che potrebbero restituire dati obsoleti.

Per GTIN Woo presenti l'eligibility restituisce `woocommerce_present:true` e proof vuota. PUT attivo o prima creazione, anche inattiva, viene bloccato. Un tombstone su un'identità già gestita può essere scritto con proof valida anche quando Woo è presente, per consentire soppressione controllata. Il client attuale si ferma prima del PUT in questo caso: vedere [modifiche Ponte](docs/ponte-changes.md).

## Concorrenza, file e proprietà

`LOCK TABLES posts READ, postmeta READ, oap_pages WRITE, oap_slugs WRITE`, con autocommit disabilitato, blocca anche writer WooCommerce che ignorano il plugin. Le due tabelle del ricevitore sono aggiornate nella stessa transazione InnoDB. COMMIT precede UNLOCK; eccezioni causano ROLLBACK. Una connessione interrotta non viene riconnessa automaticamente nel blocco critico.

La serializzazione è globale, quindi più forte del solo lock per GTIN. Semplifica la garanzia con import WooCommerce arbitrari, ma limita il throughput e blocca brevemente scritture del sito: fare benchmark in staging prima di abilitare cataloghi grandi. Attesa lock limitata a 5 secondi; lo stato di sessione viene ripristinato. Richieste ≤1 MiB e ≤500 alias. Configurare anche il limite del body sul reverse proxy: il limite applicativo viene verificato dopo che WordPress ha letto la richiesta.

File immutabili: JSON con HTML filtrato e SEO, nome SHA-256 **interno**, scrittura temporanea, flush/fsync, rename e fsync directory. Solo dopo il file è referenziato dalla transazione dell'indice. Un crash può lasciare un file non referenziato, ma non un indice parzialmente installato; nessun garbage collector cancella la cronologia. Conservare tutti i file nei backup. `content_hash` del Ponte è opaco (digest di dati sorgente/template), distinto dall'hash interno del file: non è ricalcolabile dal solo HTML.

Stessa versione/hash: ACK senza scritture o invalidazioni. Stessa versione/hash differente: 409. Versione inferiore: 409. Versione superiore/hash uguale: solo cursore, file pubblico e modified_at invariati. Versione superiore/hash diverso: aggiornamento atomico. Il registro conserva tutti gli alias e ne vieta il riutilizzo da un altro GTIN. Non modifica mai wp_posts né dati WooCommerce; il campo owner deve essere `oap-wp-1`.

Le visite e la sitemap ricontrollano l'inventario locale e sopprimono anche GTIN aggiunti successivamente da import SQL che saltano gli hook. Non c'è una chiamata remota per visita. Questa verifica SQL conservativa può essere costosa su cataloghi grandi: misurare prima di attivare. Se l'inventario non è verificabile, la pagina risponde 503/noindex e la sitemap non emette gli URL.

## HTML e SEO

HTML filtrato con allowlist e `wp_kses`; script, iframe, form, SVG, eventi inline, PHP e URL non HTTP(S) vengono rifiutati. Nessun PHP, shortcode, JavaScript o filtro the_content viene eseguito sui frammenti. URL canonical deve coincidere esattamente con base configurata + slug. Redirect accettati soltanto come slug e risolti su host/path locali: nessun open redirect.

Documento HTML autonomo, indipendente dal tema, con CSS condiviso `oap-1.css` una volta. Non esegue `wp_head`: titolo, description, canonical, robots e JSON-LD sono emessi dal solo adattatore nativo. Nessuna integrazione Yoast/Rank Math viene dichiarata. Il JSON-LD è serializzato come dato con escaping dei delimitatori script e Unicode separatori; attivarlo soltanto dopo il collaudo SEO. La CSP vieta JavaScript eseguibile. La pagina non eredita header/footer, menu o analytics del tema: eventuale integrazione grafica è lavoro successivo.

Sitemap WordPress nativa `/wp-sitemap-oap-1.xml`, pagine da 1000 URL, aggiunta all'indice `/wp-sitemap.xml`. Richiede sitemap native abilitate; se un plugin SEO le disabilita, collegare esplicitamente il provider all'infrastruttura sitemap del sito prima di attivare. Nessuna integrazione con sitemap di terze parti è già presente. `lastmod` proviene da `seo.modified_at` (epoch del bundle), mai dall'ACK.

Inactive temporary: 200/noindex, messaggio locale senza offerte/schema/canonical. Inactive gone: 410/noindex, anche sugli alias. Gli alias di prodotti temporanei/attivi rispondono 301 diretto al canonical; riattivazione richiede versione maggiore. Il namespace dedicato non può essere la radice del sito.

## Rollback

1. Sospendere worker (`BRIDGE_PUBLISH_ENABLED=false`) e impostare `OAP_BRIDGE_WRITES_ENABLED=false`.
2. Backup coerente delle due tabelle **insieme** e della directory artefatti; non cancellare tombstone/alias o ridurre versioni.
3. Ripristinare la precedente versione del plugin compatibile con lo stesso schema. Questa versione non ha migrazioni distruttive né uninstall che elimina dati.
4. Se occorre tornare a un backup DB, ripristinare indice e alias insieme ai file, sospendere la pubblicazione e riconciliare le versioni sul Ponte prima della ripartenza. Non forzare un downgrade di versione tramite PUT.
5. Disattivare il plugin interrompe rotte pubbliche e redirect: predisporre prima un servizio di continuità o tombstone sul server se le pagine erano già indicizzate. I dati restano nel DB e su disco.

## Test e API

Vedere [test automatici](tests/README.md), [API](docs/api.md), [rapporto verifiche](docs/validation.md) e [modifiche Ponte](docs/ponte-changes.md). Lo ZIP contiene anche una copia della documentazione in `docs/`.
