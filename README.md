# OcchioAiPrezzi Bridge Receiver

Plugin WordPress ricevente del servizio VPS Ponte. La VPS decide ammissibilità WooCommerce/GTIN, creazione o aggiornamento, contenuti, SEO, eventi e pubblicazione; il plugin valida tecnicamente, salva e rende HTML e metadati già pronti, stati e redirect ricevuti.

Mantiene Bearer HTTPS, proprietà delle pagine, GTIN unico, idempotenza, versioni e serializzazione delle scritture. Non crea post o prodotti WooCommerce. Scritture disabilitate per default.

- [API e variazioni oap-wp-1](docs/api.md)
- [Interventi obbligatori nel repository vps-ponte, non applicati](docs/ponte-changes.md)
- [Configurazione](occhioaiprezzi-bridge-receiver/README.md)
- [Test isolati](occhioaiprezzi-bridge-receiver/tests/README.md)
- [Verifiche e limiti](docs/validation.md)

Nessuna installazione sul sito reale o modifica alla VPS. Nessun flag operativo di pubblicazione abilitato.
