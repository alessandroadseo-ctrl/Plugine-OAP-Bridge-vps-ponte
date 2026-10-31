# Test isolati

Richiedono Docker locale, Bash, Git, curl, Python 3 e `httpx` (`python3 -m pip install httpx`). Nessun accesso al sito reale. Le versioni collaudate sono WordPress 6.8.3, WooCommerce 10.3.5, PHP 8.3.36 e MariaDB 11.4.13; i tag runtime possono ricevere patch successive. Non è una matrice completa MySQL/PHP.

```sh
# Da un checkout del plugin; il repository Ponte viene soltanto letto.
git clone https://github.com/alessandroadseo-ctrl/vps-ponte.git /workspace/vps-ponte
export OAP_REFERENCE_CLIENT=/workspace/vps-ponte/app/wordpress/client.py
bash tests/setup.sh
bash tests/run.sh
bash tests/teardown.sh
```

setup.sh crea container `oap-receiver-db`, `oap-receiver-php`, rete `oap-receiver-test`, WordPress e file sotto `../work/oap-receiver-tests` (oppure OAP_TEST_ROOT). Porta **127.0.0.1:18086** solamente. Credenziali nel fixture wp-config.php sono fittizie e solo per DB `oap_test`, host `receiver.test`. Non copiare il fixture nel sito reale. I container sono etichettati; teardown rifiuta di eliminare risorse non etichettate. Non esegue prune. Il checkout del Ponte non viene modificato.

`integration.php` usa WordPress e oggetti WooCommerce reali. Verifica creazione/aggiornamento, ACK identico dopo risposta persa, cursore, versioni, owner e post nativi, token, HTTPS, proof, HTML/URL, body limit, alias, prodotti/varianti, verifica ripetuta dopo nuova creazione Woo, tombstone, riattivazione, sitemap/lastmod, soppressione dopo import Woo e escaping JSON-LD.

`concurrency.py` avvia sei processi WordPress separati contro lo stesso MariaDB: uno crea e cinque recuperano lo stesso ACK. Poi un INSERT SQL Woo indipendente viene tentato durante un lock del ricevitore: deve restare bloccato fino al rilascio. Verifica anche WooCommerce non caricato. Non è un mock di database o una simulazione sequenziale della concorrenza.

`http_client.py` importa **il vero** app/wordpress/client.py dal checkout del Ponte e lo esegue su HTTP reale del WordPress locale. Il transport cambia solo la destinazione HTTPS fittizia in localhost e simula TLS terminato dal proxy nel fixture. Verifica una risposta persa *dopo* l'installazione reale, retry senza duplicazione, canonical unico, JSON-LD unico e sicuro, sitemap pubblica, 301, temporary 200/noindex, gone 410 su canonical/alias e riattivazione. L'unico errore di trasporto iniettato è la perdita dell'ACK; tutte le risposte applicative provengono da WordPress reale.

La suite cancella **tutte le righe delle due tabelle OAP del DB di test** all'inizio, poi crea/elimina prodotti Woo di fixture. Rifiuta DB diversi da oap_test o home host diverso da receiver.test. Non eseguirla su copie contenenti dati da preservare.

Il test HTTP non è ripetibile da solo dopo aver già installato il suo GTIN: eseguire run.sh completo, che ripulisce prima l'indice. Gli artefatti di test restano per permettere l'analisi e non vengono cancellati dal plugin.

Non sono stati collaudati reverse proxy, TLS, CDN, tema, metadati GTIN e plugin SEO del sito reale. La fixture HTTPS non equivale a una verifica di certificati o del proxy di produzione.
