# OcchioAiPrezzi Bridge Receiver

Plugin WordPress **esclusivamente ricevente** del servizio VPS Ponte. WooCommerce, ammissibilità GTIN, scelta create/update, contenuti/SEO, eventi e decisioni di pubblicazione appartengono alla VPS. Il plugin conserva autenticazione, proprietà, unicità, versioni, idempotenza e serializzazione delle scritture.

## Versione 1.1.0

Il nuovo PUT richiede `operation:create|update`; il vecchio endpoint eligibility risponde 410 e non produce risultati Woo/proof. HTML/SEO pronti vengono salvati e resi senza generazione o riscrittura; stati e alias ricevuti vengono applicati. **Il client publish del Ponte deve essere migrato prima dell'uso.**

- [ZIP installabile](dist/occhioaiprezzi-bridge-receiver-1.1.0.zip), sorgenti e test inclusi; [checksum](dist/SHA256SUMS.txt).
- [Configurazione senza pubblicazione, aggiornamento e rollback](occhioaiprezzi-bridge-receiver/README.md).
- [API e cambiamenti di contratto](docs/api.md).
- [Interventi esatti richiesti sulla VPS, non applicati](docs/ponte-changes.md).
- [Verifiche](docs/validation.md) e [test isolati](occhioaiprezzi-bridge-receiver/tests/README.md).

Scritture disabilitate per default. Nessuna installazione sul sito reale, invio prodotti, modifica VPS o abilitazione worker.
