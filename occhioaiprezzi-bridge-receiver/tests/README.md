# Test isolati

Solo ambiente usa-e-getta: WordPress 6.8.3, WooCommerce 10.3.5, MariaDB 11.4, PHP 8.3, host receiver.test, DB oap_test. Il test crea prodotti Woo esclusivamente per dimostrare che non influenzano le decisioni ricevute. Non usare contro il sito o la VPS reali.

Richiesti Docker, git, curl e Python 3 (solo libreria standard). Da root repository:

```sh
bash occhioaiprezzi-bridge-receiver/tests/setup.sh
bash occhioaiprezzi-bridge-receiver/tests/run.sh
bash occhioaiprezzi-bridge-receiver/tests/teardown.sh
```

I dati restano in work/oap-receiver-tests; setup rifiuta di sostituire container esistenti. wp-config.php abilita scritture e simula TLS **solo nel container locale**; non distribuirlo. Il test HTTP usa il contratto aggiornato direttamente, non il client Ponte originale che richiede la migrazione documentata.

Copertura: Bearer/TLS, request size, validazione HTML/URL/SEO, operazione esplicita, update assente/create esistente, versioni/hash/cursore e ACK perso, owner estraneo/pagine native/alias storici, GTIN unico, sei scritture concorrenti identiche e sei create concorrenti in conflitto, ricevitore senza WooCommerce, catalogo Woo che non condiziona creazione o rendering, HTML/SEO active e inactive preservati, canonical VPS, JSON-LD escapato, sitemap, 301/410 e alias rimossi.

La suite `admin.php` (inclusa in `run.sh`) verifica elenco e ricerca su 25 artefatti, pagine senza sovrapposizioni, estremi di paginazione, ricerca letterale e senza risultati, menu e permessi amministrativi, rifiuto di editor/subscriber/anonimi su lista e dettaglio, contatori, URL da slug corrente distinto dal canonical SEO, escaping, isolamento dell'anteprima, versione dell'indice, artefatti corrotti, owner estranei e assenza di modifiche alle righe originali o duplicazioni in wp_posts.

La suite token-option.sh rimuove temporaneamente la sola costante del token nel wp-config usa-e-getta e la ripristina con trap; verifica digest, token errati/corti, rotazione, ACK, header HTTP_AUTHORIZATION/REDIRECT_HTTP_AUTHORIZATION, salvataggio con nonce e mancata visualizzazione del segreto. Le fixture integration verificano offers[].availability unknown senza promozione a InStock.

La suite `public_base.py` pubblica una fixture con il vecchio override `/prodotti-ponte`, poi rimuove la costante e verifica `/prodotti/` senza reinviare il prodotto: righe, claim, artefatto, versione, identità e numero di post devono restare identici. Controlla GET/HEAD, normalizzazione dello slash, precedenza di un namespace personalizzato, root e pagina figlia native sotto `/prodotti/`, slug sconosciuti o malformati e rotte REST. La configurazione isolata è ripristinata con `finally`; `wp-config.php` non imposta la base per esercitare il vero default.

Le suite `affiliates.php` e `affiliates_http.py`, incluse in run.sh, verificano i redirect locali con entrambi i GTIN richiesti. Le destinazioni sintetiche usano un IP pubblico senza effettuare connessioni ad esso: il DNS esterno non è disponibile nei container. La risoluzione di domini merchant reali va verificata su staging. Il test di rollback crea e rimuove un trigger nella sola base oap_test.

Versione 1.3.1: test diretti su `/go` e `/go/`, GET/HEAD, 405, query malformate/duplicate/aggiuntive, open redirect e URL pericolosi nel DB. `compara_http.py`, inclusa in run.sh, confronta 20 risposte del gestore WordPress `/compara` simulato con entrambi gli hook Bridge disabilitati e abilitati; status 301, Location, altri header e body devono restare identici. Le fixture temporanee sono rimosse in finally.

Versione 1.3.2: `unsafe-html.php`, inclusa in `integration.php`, verifica CTA con `target="_blank"` e rifiuto REST 400 `oap_unsafe_html` per script, PHP, eventi anche mixed case, iframe, SVG, form, tag/attributi non autorizzati e normalizzazione di entità da KSES. URL pericolosi con target restano rifiutati. La fixture `fixtures/vps2-product.html` è generata con Jinja2 dal template VPS2 `app/renderer/templates/product.html` (blob `42183afeae848c0a716ae14a8185941a3407833e`), con la normalizzazione delle entità prevista dal renderer `render.py` (blob `ee1068c11fcc8169ae76c9f6e8d8be8bf642c0af`). Copre tutte le tipologie di tag/attributi del template, immagini, testo escapato, offerte con/senza CTA, spedizione nota/ignota e descrizione. VPS2 è stato consultato soltanto in lettura. La fixture attraversa validazione, REST, archiviazione, retry e rendering HTTP; SEO e hash ricevuti restano identici. Anche la suite affiliati pubblica CTA con target.

Versione 1.4.1: `regeneration.php` sostituisce i test outbound 1.4.0 e verifica il
protocollo privato poll/ack, permessi/nonce, TLS/token/worker, persistenza,
ritrasmissioni, doppio clic, ACK perso/conflittuale/storico, ricevute rejected,
contatori VPS2, snapshot obsoleto, mutex tra connessioni e rollback transazionale.
`node tests/regeneration-ui.cjs` (Node 18+) verifica gli stati del browser e il
retry con chiave invariata. `regeneration_vps2.py` collauda il client Python reale
al commit fissato contro REST e AJAX WordPress reali; eseguirlo dopo `run.sh`,
usando il Python dell'ambiente frozen di vps-ponte e passando il percorso del clone.
Non eseguire contemporaneamente suite che cambiano il wp-config di test.
Vedere [docs-regeneration.md](../docs-regeneration.md) per comandi e limiti.

Versione 1.5.0: `deletion.php` e `deletion_http.py`, incluse in `run.sh`, verificano
cancellazione fisica, tombstone minimo, retry e pulizia recuperabile, eventi vecchi,
owner estranei, immagini condivise/protette/native, canonical/sitemap, cache,
GET/HEAD 410 senza contenuti e redirect, autenticazione, 405 e ripubblicazione.

## Toggle «Includi spedizione»

Contratto VPS2 concordato (fixture `fixtures/shipping-toggle.html`): nella sezione
`.oap-bridge-offers`, prima delle righe, inserire
`<button type="button" class="oap-bridge-shipping-toggle" aria-pressed="false">Includi spedizione</button>`.
Ogni `div.oap-bridge-offer-row` espone `data-oap-price="8.99"`; per spedizioni note
espone anche `data-oap-shipping="5.00"` e `data-oap-total="13.99"`. Per spedizione
ignota omettere entrambi gli ultimi attributi. Valori decimali non negativi con
punto e due cifre, in euro; il totale deve coincidere con prezzo più spedizione.
Conservare la struttura del prezzo principale `.oap-bridge-price > p:first-child > strong`,
merchant `.oap-bridge-merchant` e CTA `.oap-bridge-offer`. Le righe sono figlie dirette
della sezione e arrivano ordinate per prezzo prodotto. Attributi estranei restano
vietati; `aria-pressed` è autorizzato esclusivamente sul pulsante dedicato, con
valore iniziale `false`. Nessuna wildcard `data-*` o `aria-*`.

`shipping-toggle.php` e `shipping-toggle-http.py` sono incluse in `run.sh` e possono
essere lanciate separatamente nel setup usa-e-getta:

```sh
docker exec oap-receiver-php wp --allow-root eval-file wp-content/plugins/occhioaiprezzi-bridge-receiver/tests/shipping-toggle.php
python3 occhioaiprezzi-bridge-receiver/tests/shipping-toggle-http.py
```

La suite browser richiede Node 18+, il modulo `playwright` risolvibile da Node e
Chromium (nessuna dipendenza o libreria aggiunta al sito):

```sh
OAP_CHROMIUM=/usr/bin/chromium node occhioaiprezzi-bridge-receiver/tests/shipping-toggle.cjs
```

Omettere `OAP_CHROMIUM` per usare il browser installato da Playwright. Il test
blocca tutte le risorse esterne e non usa servizi di produzione. Copre tastiera,
aria-pressed, assenza di spostamenti iniziali inutili, riordini ripetuti, centesimi,
spedizione gratuita/ignota, nessun vincitore se tutti i totali sono ignoti, prezzi,
badge e CTA, conservazione degli stessi nodi e degli attributi affiliati esatti,
loghi/descrizioni, 1 e 100 offerte, dati invalidi/incompleti/incoerenti, vecchio HTML,
assenza di richieste HTTP e isolamento tra prodotti e markup esterno.
La preferenza non viene memorizzata. Una sezione incompleta resta statica.

Verifica locale 2026-11-08: suite PHP (29 verifiche), suite HTTP dedicata, suite
Chromium, regressioni esistenti `unsafe-html.php` e `http_client.py`, lint PHP e
`git diff --check` superati su WordPress 6.8.3, PHP 8.3 e MariaDB 11.4.
La suite completa con WooCommerce non è stata eseguita: il download del pacchetto
WooCommerce dal setup ha restituito HTTP 504 due volte; i test dedicati sono stati
eseguiti su un setup isolato equivalente senza WooCommerce. Il nuovo contratto è
verificato sulla fixture concordata, senza attendere o modificare il deploy VPS2.
