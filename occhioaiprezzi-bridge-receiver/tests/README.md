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
