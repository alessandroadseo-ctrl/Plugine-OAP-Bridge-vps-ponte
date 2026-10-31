# OcchioAiPrezzi Bridge Receiver 1.1.0

Ricevitore WordPress esclusivo del servizio VPS Ponte. La VPS decide inventario WooCommerce, ammissibilità GTIN, create/update, contenuti, SEO, eventi e pubblicazione. Il plugin autentica il comando, ne verifica struttura e proprietà, salva e rende i dati ricevuti.

Mantiene indice dedicato e file statici privati secondo l'architettura oap-wp-1: nessun CPT o prodotto WooCommerce per GTIN. remote_id resta `oap:<gtin>`. Nessuna chiamata remota per visita e nessun controllo inventario locale.

**Cambio di contratto:** GET/PUT pages e ACK conservano protocollo e nomi, ma PUT richiede `operation:create|update`. Eligibility è ritirato (410), proof ignorata. Il vecchio client publish deve essere migrato sulla VPS prima dell'uso; vedere [API](docs/api.md) e [interventi precisi sul Ponte](docs/ponte-changes.md). Questa consegna non modifica la VPS né abilita l'invio.

## Installazione iniziale senza pubblicazione

Richiede WordPress 6.6+, PHP 8.1+ a 64 bit con mysqli, MySQL 8/MariaDB 10.6+ con tabelle OAP InnoDB. Collaudato su WordPress 6.8.3, PHP 8.3.36 e MariaDB 11.4.13. **WooCommerce non è richiesto.**

1. Lasciare i worker sospesi (`BRIDGE_PUBLISH_ENABLED=false`). Effettuare backup e provare in staging.
2. Installare lo ZIP `dist/occhioaiprezzi-bridge-receiver-1.1.0.zip` e attivare. L'attivazione crea soltanto `<prefix>oap_pages` e `<prefix>oap_slugs`: nessun contenuto o chiamata esterna.
3. Creare una directory privata POSIX fuori da WordPress **e dal document root**, di proprietà PHP, permessi 0700, con supporto rename atomico/fsync. Non usare uploads o storage non verificato.
4. In wp-config.php, prima di wp-settings.php:

```php
define('OAP_BRIDGE_TOKEN', (string) getenv('OAP_BRIDGE_TOKEN'));
define('OAP_BRIDGE_ARTIFACT_DIR', '/srv/oap-private/receiver');
define('OAP_BRIDGE_PUBLIC_BASE', 'https://IL-TUO-DOMINIO/prodotti-ponte');
define('OAP_BRIDGE_WRITES_ENABLED', false);
```

Token casuale almeno 32 caratteri, configurato fuori dal sorgente e uguale a quello del Ponte. Nessun token nei log del plugin/proxy. Le vecchie impostazioni OAP_BRIDGE_GTIN_ADAPTER_VERIFIED/OAP_BRIDGE_GTIN_META_KEYS/OAP_BRIDGE_JSONLD_ENABLED non hanno più effetto e vanno rimosse: non esiste un adattatore GTIN né una scelta SEO locale.

5. Usare HTTPS reale. Per TLS terminato dal proxy, configurare HTTPS=on solo dal proxy fidato, senza fidarsi di header arbitrari del client.
6. Riservare l'intero namespace pubblico, non la radice del sito. Allineare BRIDGE_PUBLIC_BASE_URL della VPS; questo URL serve per il routing e i redirect locali, **non per rigenerare canonical SEO**.
7. Escludere API e namespace pubblico da cache pagina/CDN; mantenere corretta invalidazione della sitemap. Configurare un limite body di 1 MiB anche sul proxy. Il plugin emette no-store. L'hook oap_bridge_page_changed notifica soltanto invalidazioni tecniche, non gestisce eventi editoriali.
8. Verificare GET pages autenticato: per identità assente versione0/hash vuoto, nessuna scrittura. PUT resta 503 writes_disabled. L'eligibility legacy risponde 410: non è un errore da aggirare con Woo=false.
9. Prima di un'eventuale futura attivazione operativa, completare la migrazione VPS e il collaudo coordinato descritto in ponte-changes.md. In questa attività non vengono abilitate scritture reali o pubblicazione.

## Protezioni tecniche conservate

Bearer privato/HTTPS; owner `oap-wp-1`; unicità GTIN e registro globale slug; confronto versioni/hash; idempotenza; limiti richiesta; validazione HTML/URL/SEO e difesa da open redirect. Mai aggiornare post nativi o dati Woo.

La VPS sceglie create/update. Il ricevitore verifica atomicamente la precondizione: create non modifica identità esistenti, update non crea identità assenti. Retry con stessa versione/hash restituisce l'ACK già installato senza modificare file, lastmod o cache, anche dopo perdita della risposta. Versioni inferiori e hash differenti per la stessa versione vengono rifiutati. Versione maggiore/hash uguale avanza solo il cursore: ogni variazione pubblica deve avere un nuovo hash deciso dalla VPS.

La transazione InnoDB usa LOCK TABLES sui due indici OAP in WRITE e su wp_posts in READ per proteggere collisioni con contenuti estranei. **wp_postmeta non viene letto né bloccato** e non esiste controllo Woo. La serializzazione resta globale, più forte del lock per GTIN; misurare il carico in staging. Attesa lock massima 5 secondi, COMMIT/ROLLBACK e ripristino della sessione; niente riconnessione automatica durante la scrittura. L'utente DB necessita CRUD sulle tabelle OAP, SELECT su wp_posts e LOCK TABLES; CREATE/ALTER per attivazione. Non servono permessi d'inventario sui metadati Woo.

File immutabili JSON con HTML/SEO e hash interno separato dal content_hash opaco VPS: temporaneo, flush/fsync, rename atomico, fsync directory, poi riferimento dall'indice transazionale. Un crash può lasciare file non referenziati ma non un indice parzialmente installato. Nessuna cancellazione automatica di storia, tombstone o prenotazioni degli alias.

## Rendering fedele al comando

HTML accettato conservato byte per byte; markup non sicuro/non conforme rifiutato invece di essere riscritto. Niente shortcode, the_content o codice del payload. Metadati SEO salvati senza derivarli dall'HTML: titolo, description e canonical ricevuti, JSON-LD ricevuto serializzato con escaping sicuro. Nessun Product/Offer generato, nessun titolo o messaggio locale inventato. Corpo vuoto rimane vuoto, anche in inactive.

Documento HTML autonomo e CSS condiviso, indipendente dal tema; non usa wp_head, quindi non duplica SEO/JSON-LD da altri plugin. Non eredita menu/header/footer/analytics del tema. I campi SEO assenti non vengono riempiti. La CSP impedisce JavaScript eseguibile.

State active/inactive e removal_policy vengono applicati al protocollo HTTP: temporary 200/noindex, gone 410. La VPS deve fornire HTML inattivo e rimuovere offerte/schema se necessario: il ricevitore non decide cosa mostrare. Inactive/indexable=true è un payload strutturalmente incoerente, rifiutato con 400.

Gli alias 301 sono esattamente quelli nell'ultimo redirects ricevuto, diretti alla rotta locale dello slug corrente. Nessun alias precedente viene aggiunto automaticamente; uno non più richiesto risponde 404. Le prenotazioni storiche restano per impedire che un altro GTIN se ne appropri. Canonical SEO e destinazione tecnica del redirect sono separati, quindi un canonical esterno non diventa un open redirect.

Sitemap WordPress nativa `/wp-sitemap-oap-1.xml`, aggiunta a `/wp-sitemap.xml`, in pagine di 1000 URL: active/indexable secondo il comando, loc dal canonical ricevuto, lastmod dal bundle. Se il sito usa sitemap di terze parti, collegare il provider prima dell'uso; nessuna integrazione Yoast/Rank Math viene presunta. Nessuna soppressione locale basata su Woo, neppure dopo un import: la VPS deve inviare lo stato successivo.

## Aggiornamento e rollback

Schema delle tabelle invariato: pagine/file/tombstone della versione1.0 restano leggibili; i vecchi file non richiedono operation per il rendering. I nuovi PUT lo richiedono. Durante aggiornamento mantenere entrambi gli invii sospesi e l'interruttore di scrittura falso; non usare il vecchio client contro il nuovo ricevitore.

Per rollback: sospendere worker e scritture, fare backup coerente di entrambe le tabelle e di tutti i file, ripristinare versione plugin e flusso client corrispondenti. Non ridurre versioni/tombstone né ripristinare una sola tabella. Disattivare il plugin interrompe rotte/redirect: predisporre continuità sul server se necessario. Nessun uninstall distruttivo.

## Verifiche

[Test riproducibili](tests/README.md), [rapporto e limiti](docs/validation.md), [API](docs/api.md). Nessuna installazione sul sito reale o modifica della VPS effettuata.
