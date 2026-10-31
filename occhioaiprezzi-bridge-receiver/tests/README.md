# Test del ricevitore esclusivo

Richiedono Docker locale, Bash, Git, curl, Python 3 e httpx. Usano WordPress 6.8.3, PHP 8.3.36, MariaDB 11.4.13; WooCommerce 10.3.5 è una **fixture opzionale per provare l'assenza di decisioni locali**, non una dipendenza runtime.

```sh
# Il repository Ponte è soltanto letto, mai modificato.
git clone https://github.com/alessandroadseo-ctrl/vps-ponte.git /workspace/vps-ponte
export OAP_REFERENCE_CLIENT=/workspace/vps-ponte/app/wordpress/client.py
bash tests/setup.sh
bash tests/run.sh
bash tests/teardown.sh
```

setup.sh crea i container oap-receiver-php/oap-receiver-db e rete oap-receiver-test, etichettati per teardown selettivo. Nessun prune. File sotto ../work/oap-receiver-tests oppure OAP_TEST_ROOT; sola porta 127.0.0.1:18086. Il fixture wp-config.php contiene credenziali fittizie per DB oap_test/host receiver.test, simula TLS e abilita le scritture **soltanto sul banco di prova**. Mai copiarlo sul sito reale. Il vecchio OAP_BRIDGE_JSONLD_ENABLED è deliberatamente false: non deve più decidere se mostrare lo schema ricevuto.

`integration.php` cancella le righe OAP del DB di test e verifica:

- operation obbligatoria dalla VPS, update non crea e create non sovrascrive;
- retry identico dopo ACK perso, versioni e conflitti, unicità GTIN;
- owner estraneo e collisioni con post nativi protetti;
- Bearer/HTTPS/limite body e validazione HTML/URL/SEO;
- HTML e SEO memorizzati invariati, canonical non derivato dallo slug, proof legacy inerte;
- stati/indicizzazione applicati, sitemap alimentata dai metadati ricevuti;
- GTIN realmente presente in prodotti/varianti Woo non può porre veto al comando, né il plugin ne modifica i metadati;
- endpoint eligibility ritirato senza alcuna dichiarazione di ammissibilità.

`concurrency.py` avvia sei processi indipendenti di creazione e poi sei di aggiornamento sullo stesso GTIN: un'unica scrittura e cinque ACK per ogni fase. Un INSERT SQL su metadati Woo deve completarsi **durante** il lock del ricevitore: nessun lock wp_postmeta. Una scrittura separata con WooCommerce non caricato deve riuscire.

`http_client.py` importa client.py originale solo per verificare che GET/request/ACK restino compatibili e che il vecchio publish fallisca al 410 senza scrivere. Un piccolo preparatore **locale ai test** rappresenta la VPS che sceglie operation da GET e invia PUT diretto: non modifica né simula l'esistenza di un client aggiornato in produzione. HTTP applicativo reale su WordPress; il transport cambia soltanto destinazione rete verso localhost e inietta un ACK perso dopo commit effettivo. Verifica HTML/SEO ricevuti, JSON-LD sicuro senza switch locale, sitemap, assenza di soppressione dopo modifica Woo e con Woo disattivato, alias solo espliciti, 200/410, riattivazione, noindex e nessun fallback inventato.

La suite è distruttiva per **i soli dati OAP del DB di test**; rifiuta altri nomi DB/home host. Eseguire run.sh completo prima di ripetere HTTP, perché il fixture HTTP si aspetta inizialmente GTIN assente. Non usare su copie con dati da preservare.

Non dimostra che la VPS disponga già dell'inventario autorevole o del client migrato: quei lavori sono elencati in docs/ponte-changes.md. Non collauda TLS, CDN, tema, SEO del sito reale, MySQL8 o un catalogo completo.
