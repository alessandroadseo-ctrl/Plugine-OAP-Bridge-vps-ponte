# OAP Bridge Receiver 1.1

Ricevitore WordPress di documenti finali della VPS Ponte. Requisiti: WordPress >=6.6, PHP >=8.1 64 bit con mysqli, MySQL/MariaDB con tabelle dedicate InnoDB e LOCK TABLES, filesystem POSIX locale con fsync e rename atomico.

La VPS è responsabile di inventario WooCommerce, ammissibilità GTIN, scelta create/update, HTML/SEO, eventi, stati e pubblicazione. Il plugin non richiede WooCommerce e non lo consulta. Non crea o modifica post/prodotti: conserva un indice dedicato e artefatti privati immutabili.

## Configurazione per un futuro rilascio separato

Questa attività non installa o abilita nulla sul sito. Per ora usare solo i test isolati. Le scritture sono disabilitate per default.

Le costanti di configurazione sono `OAP_BRIDGE_TOKEN` (segreto dedicato almeno 32 byte), `OAP_BRIDGE_PUBLIC_BASE` (URL HTTPS sullo stesso host WordPress, namespace non root riservato), `OAP_BRIDGE_ARTIFACT_DIR` (directory scrivibile fuori ABSPATH e document root). `OAP_BRIDGE_WRITES_ENABLED` deve essere esattamente true per accettare PUT; non abilitarlo sul sito in questa attività.

Nessuna configurazione GTIN_META_KEYS o GTIN_ADAPTER_VERIFIED è necessaria. JSON-LD è reso solo se inviato dalla VPS, senza flag SEO locale. Il Bearer autorizza solo l'API dedicata, non le API WordPress/WooCommerce.

## Persistenza e rendering

Unicità GTIN e slug garantita dalle chiavi primarie e lock serializzati. I post nativi sono protetti da collisioni slug e lock READ; nessuna adozione automatica. Gli artefatti sono scritti e verificati con SHA-256 e rename atomico, poi referenziati transazionalmente dall'indice. Una scrittura fallita può lasciare artefatti non referenziati; non cancellare artefatti senza una procedura di backup/retention separata.

Il documento pubblico è un wrapper tecnico con il frammento HTML e SEO ricevuti: niente shortcode, wp_head/the_content, generazione di offerte o tombstone. Il plugin rifiuta HTML non conforme invece di riscriverlo. Gli stati ricevuti determinano 200/410 e index/noindex; gli alias ricevuti determinano 301. Gli alias omessi restano riservati ma non producono redirect. Sitemap e rendering non cambiano in seguito a variazioni WooCommerce: deve intervenire la VPS.

Escludere il namespace pubblico dalla cache di reverse proxy/CDN in un eventuale rilascio futuro; sono emessi nocache_headers. L'hook tecnico oap_bridge_page_changed permette invalidazione mirata, non esegue decisioni di catalogo.

## Contratto e rollback

Leggere [api.md](docs/api.md) e [ponte-changes.md](docs/ponte-changes.md): il vecchio client che chiama eligibility deve essere aggiornato prima di questo ricevitore. Gli inactive legacy devono essere rigenerati dalla VPS con SEO completo prima di un rollout coordinato. Non usare il vecchio archivio 1.0.0 della storia Git.

In una futura manutenzione autorizzata, disabilitare prima le scritture e fermare il mittente; backup coerente di indice, registro slug e artefatti. Non eliminare tabelle/versioni/tombstone per forzare un retry. Disattivare il plugin rimuove le sue rotte ma non i dati; un rollback al vecchio contratto richiede anche coordinamento del client e ripristino dei dati/configurazioni compatibili. Nessun rollback o rollout viene eseguito da questa attività.
