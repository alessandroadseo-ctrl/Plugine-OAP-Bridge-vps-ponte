# OcchioAiPrezzi Bridge Receiver

Plugin WordPress autonomo per il protocollo `oap-wp-1` del repository separato [vps-ponte](https://github.com/alessandroadseo-ctrl/vps-ponte).

Implementa indice dedicato e artefatti statici secondo il contratto reale, API Bearer private, controllo WooCommerce atomico, idempotenza/versioni, registro slug, SEO nativa, sitemap e tombstone. Non crea post o prodotti WooCommerce per ogni GTIN. Scritture e adattatore non sono abilitati per default.

- [Installazione, configurazione e rollback](occhioaiprezzi-bridge-receiver/README.md)
- [API](docs/api.md)
- [Modifiche proposte al Ponte, non applicate](docs/ponte-changes.md)
- [Verifiche e limiti](docs/validation.md)
- [Test isolati riproducibili](occhioaiprezzi-bridge-receiver/tests/README.md)

Non installato sul sito reale. Nessun invio prodotti o worker attivato.
