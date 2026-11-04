## Versione 1.3.1

Redirect affiliati locali e sincronizzazione transazionale: vedere [REDIRECT-AFFILIATI-1.3.1.md](REDIRECT-AFFILIATI-1.3.1.md) per contratto VPS2, aggiornamento e verifiche sui GTIN richiesti.

# OAP Bridge Receiver 1.1.1

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

## Pannello prodotti

In **OcchioAiPrezzi Bridge → Prodotti**, gli utenti con `manage_options` possono consultare le pagine del ricevitore (20 per pagina), cercare titolo o GTIN e aprire il dettaglio. Non sono disponibili operazioni di scrittura. Titolo: `seo.h1`, in alternativa `seo.title` e infine GTIN. Stato, versione corrente e ultimo aggiornamento provengono dall'indice esistente; la data è quella dichiarata dalla VPS (`modified_at`), visualizzata nel fuso WordPress, non una nuova data locale di ricezione.

I link pubblici usano la base pubblica configurata e lo slug corrente, come il renderer; anche le pagine disattivate hanno il link (possono rispondere 410 secondo la politica VPS). Il canonical SEO ricevuto resta un metadato distinto. L'anteprima HTML usa KSES, iframe sandbox e CSP senza risorse esterne; immagini e collegamenti possono quindi non essere disponibili nell'anteprima.

Nessuna nuova tabella, opzione persistente o copia in `wp_posts`. Senza ricerca vengono letti solo gli artefatti della pagina corrente; la ricerca consulta gli artefatti in batch di 100, con memoria limitata alla pagina richiesta. Il costo della ricerca cresce con il catalogo perché il titolo non è indicizzato nell'archivio originale. Gli artefatti mancanti/non integri non bloccano la lista: compare un avviso e il GTIN sostituisce il titolo.

## Collegamento e token amministrativo (1.1.1)

Aprire **OcchioAiPrezzi Bridge → Impostazioni** per salvare un token dedicato da almeno 32 caratteri senza spazi. Il pannello conserva solo SHA-256 e non mostra il valore salvato. La costante OAP_BRIDGE_TOKEN, se definita, mantiene la precedenza. La pagina mostra la base REST effettiva; non abilita scritture. Vedere `docs/wordpress-connection.md` nel repository per la procedura completa. Le offerte con availability unknown sono preservate senza generare disponibilità o schema.

Il percorso pubblico predefinito è `/prodotti/slug-prodotto/`; `OAP_BRIDGE_PUBLIC_BASE`, se definita, continua a prevalere. Per adottare il nuovo default su un sito con la costante impostata a `/prodotti-ponte`, rimuovere tale override o impostarlo a `https://www.occhioaiprezzi.com/prodotti`. I prodotti già registrati vengono risolti dagli slug esistenti senza ricreazione, migrazioni DB o reinvii VPS2. Il canonical SEO fornito dalla VPS resta invariato. Non sono necessarie nuove regole rewrite o un flush dei permalink: il plugin usa `template_redirect`. Verificare in staging che il namespace non sia già riservato da altre integrazioni; le rotte senza claim Bridge continuano nella normale gestione WordPress.
