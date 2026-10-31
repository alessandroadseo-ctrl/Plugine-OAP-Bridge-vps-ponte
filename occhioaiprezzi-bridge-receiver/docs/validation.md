# Verifiche del ricevitore 1.1

La suite riproducibile è descritta in [tests/README.md](../tests/README.md). I risultati di questa revisione sono in test-results.txt; non riutilizzare quelli della versione precedente come prova del nuovo contratto.

La protezione dai duplicati riguarda identità GTIN nell'indice ricevitore, slug e proprietà WordPress. L'assenza di un prodotto WooCommerce non è una precondizione locale: la VPS deve verificare e riconciliare l'inventario. I lock serializzano scritture ricevitore e collisioni slug native; non bloccano postmeta né offrono atomicità rispetto al catalogo Woo.

La suite è isolata: nessuna installazione sul sito reale, nessun worker VPS eseguito, nessuna pubblicazione operativa abilitata. Non certifica permessi DB, filesystem, CDN, plugin/tema o volumi di traffico del sito reale. Il nuovo client VPS non è implementato né testato in questo repository; i cambiamenti obbligatori sono elencati in ponte-changes.md. Gli artefatti inactive legacy vanno rigenerati prima di qualsiasi rilascio coordinato.

Gli archivi binari 1.0.0 sono rimossi perché rappresentano il vecchio contratto; nessun nuovo pacchetto installabile è distribuito da questa attività.
