# Rapporto di verifica — 31 ottobre 2026

## Riferimento e ambito

Ponte: `alessandroadseo-ctrl/vps-ponte`, commit `69a70c13bf92ea3df5a652e1f18b16dd3293c964`. Lettura di docs/wordpress-contract.md, app/wordpress/client.py, app/workers/runner.py, app/renderer/render.py, app/schemas/export.py, schema GTIN/events, template/CSS, tests/unit/test_components.py e sezioni WordPress di tests/integration/test_pipeline.py. Repository di riferimento lasciato invariato (`git status --short` vuoto).

Architettura: indice dedicato + artefatti statici, come richiesto dal contratto reale. Non viene creato un post per GTIN. Codice nuovo nel repository separato del plugin, nessuna modifica Python del Ponte.

## Ambiente realmente usato

- WordPress 6.8.3, sorgente ufficiale GitHub tag 6.8.3.
- WooCommerce 10.3.5, ZIP ufficiale release GitHub; SHA-256 `8f720a1a614ef9ba7e6f71766a9f3ef972f01afa0312dfcd6259613c0db495cf`.
- PHP 8.3.36, immagine wordpress:cli-php8.3 digest `sha256:6bcc7bb91f5157c4b915e74ee131873e99c1c1ff48f602e4a6f6cd17827305cb`.
- MariaDB 11.4.13, immagine mariadb:11.4 digest `sha256:d1891ede5a559f1086f55053bb8cc1569b0298338c4d72d71725a7f74f6ba620`.
- Database oap_test; host virtuale receiver.test; HTTP solo localhost:18086. TLS simulato esclusivamente dal fixture di test; nessun certificato o proxy reale collaudato.

Le immagini WordPress complete hanno inizialmente superato lo spazio Docker disponibile. La suite è stata poi eseguita con WP-CLI ridotto e sorgenti WordPress montati; non si tratta di test mock WordPress/WooCommerce.

## Esiti

- Lint PHP: tutti i file del plugin e i fixture validi.
- Integrazione: **35 verifiche PASS**, con WordPress reale e prodotti/varianti WooCommerce reali.
- Concorrenza: sei processi indipendenti, una creazione e cinque ACK invariati sul medesimo GTIN; writer SQL Woo esterno bloccato per l'intero intervallo di installazione; WooCommerce assente rifiutato.
- HTTP/client: **7 gruppi PASS** usando il client Python originale e risposte HTTP applicative reali. Il transport reindirizza il solo indirizzo di rete verso localhost e inietta una perdita dell'ACK dopo installazione effettiva.
- Controllo separato di scrittura di un nuovo file: flush/fsync del file e della directory completati sul filesystem di test.

| Requisiti richiesti | Copertura |
| --- | --- |
| 1–3, 8–10, 17 | creazione, aggiornamento stessa identità, retry identico, obsolete/conflict/foreign owner, ACK perso |
| 4 | sei processi sullo stesso MariaDB e writer Woo SQL concorrente |
| 5–7 | Woo semplice, variante, indisponibilità, normalizzazione e inserimento dopo eligibility |
| 11–13 | token assente/errato, HTTP rifiutato, proof scaduta/altro GTIN, HTML/URL/canonical pericolosi, limite body |
| 14–16 | registro alias univoco, 301 reale, temporary 200/noindex, gone 410, riattivazione, sitemap/canonical/lastmod |
| 18 | app/wordpress/client.py originale contro il server locale, nessun cambio nomi campo |

## Limiti espliciti prima dell'attivazione sul sito

Non verificati: configurazione reale GTIN, tema, altri plugin SEO/sitemap, cache/CDN, TLS del sito, privilegi DB di produzione, MySQL 8, varianti PHP, carico da catalogo completo, interruzione fisica del server o del disco. La garanzia transazionale è stata verificata su MariaDB 11.4.13; non si dichiara un collaudo MySQL già svolto.

La fixture WooCommerce/WordPress ha prodotto un avviso di traduzioni WooCommerce durante wp rewrite; non un errore del ricevitore. I deprecated iniziali per header/body null sono stati corretti e la suite è stata rieseguita senza quei messaggi.

Scritture del plugin disabilitate per default, adattatore GTIN non verificato per default. Il ciclo normale è compatibile; l'arbitraggio permanente di collisioni Woo e la classificazione dei conflitti richiedono i miglioramenti descritti in ponte-changes.md. La policy temporary richiede il collaudo SEO del sito, come già specificato dal contratto.

Non sono state fatte chiamate al sito WordPress reale, installazioni in produzione, modifiche del DB reale, pubblicazioni prodotto o attivazioni dei worker.
