# Verifica 1.3.2 — 5 novembre 2026

Correzione runtime limitata a `target=>true` nell'allowlist degli anchor. Versione plugin 1.3.2; schema DB resta 1.3.0. Storage, tabelle, redirect, SEO, hash e protocollo REST non modificati.

## Eseguito

Ambiente usa-e-getta: WordPress 6.8.3, WooCommerce 10.3.5, PHP 8.3.36, MariaDB 11.4. Comando: `bash occhioaiprezzi-bridge-receiver/tests/run.sh`. Esito finale: exit 0, 298 righe PASS e 18 file PHP senza errori sintattici.

- Fixture derivata dal template VPS2: tutte le tipologie di tag/attributi prodotte accettate; CTA con target, rel, title e URL /go/ preservata byte per byte attraverso REST, artefatto e rendering HTTP.
- Regressioni oap_unsafe_html: script, PHP, on* anche mixed case, iframe, SVG, form, h4, attributi id/style, target su altri tag rifiutati con REST 400 e codice atteso.
- Entità `&#39;` rifiutata perché KSES la riscrive: confronto byte per byte attivo. URL javascript/data con target rifiutati.
- SEO e hash preservati; retry, conflitti hash/versioni, ACK, autenticazione, concorrenza, routing, sitemap, amministrazione e token superati.
- Suite affiliati PHP/HTTP superata con CTA target: /go e /go/, GET/HEAD, 302, revoca, query e URL pericolosi, rollback; 20 risposte /compara identiche con/senza hook Bridge.
- ZIP: integrità CRC, struttura installabile, 18 file identici ai sorgenti, header 1.3.2, esclusi test e wp-config di test. `git diff --check` superato.

Il setup iniziale ha incontrato una race di avvio MariaDB, risolta completando l'installazione locale a DB pronto. Una prima esecuzione ha rilevato una variazione dello snapshot nella fixture public_base; la riesecuzione completa dopo l'inizializzazione è passata senza modifiche alla suite di routing. Il log allegato registra l'esecuzione finale completa.

## Non eseguito

Nessun deploy, test sul WordPress reale o replay dei job VPS2 dead. Nessuna modifica al repository VPS2: template e renderer consultati in sola lettura. Non verificati altri PHP/WordPress, tema e plugin del sito, CDN, DNS merchant reali o risoluzione dei job in produzione. Nessuna suite locale disponibile è stata omessa nell'esecuzione finale.
