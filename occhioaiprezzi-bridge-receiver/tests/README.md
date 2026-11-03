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
