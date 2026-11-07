# Receiver 1.5.1: diagnostica dei rifiuti e preflight

Base a45cdcd622c1dd14217c671b1a888d110fa3923e (1.5.0). Nessun cambio al
contratto DELETE, schema, allowlist HTML, percorsi pubblici o proprietà delle pagine.

`OAP_Failure` porta diagnostica da vocabolari tecnici: regola HTML, posizione della
prima differenza KSES, ruolo/indice dello slug e tipo di proprietà. Il client VPS2
filtra ancora questi campi. Nessun contenuto HTML, URL, titolo o ID di post viene
restituito nei dettagli. HTTP 400 per unsafe HTML e 409 per conflitti rimangono.

GET autenticato `pages/{gtin}?slugs=["primary","alias"]` esegue gli stessi
controlli conservativi del PUT. La lista va JSON URL-encoded: 1–501 slug, massimo
100000 byte. Se libera, aggiunge `slug_preflight: {clear: true, count: N}`;
altrimenti restituisce il conflitto. Il GET non prenota gli slug e non autorizza
scritture successive; PUT ripete il controllo sotto i lock esistenti.
Gli slug possono comparire negli access log: non registrare interi URL di richiesta.

Distribuire questo repository, non la copia storica nel repository VPS2. Prima
confrontare l'export del plugin installato, unendo eventuali personalizzazioni.
Deploy plugin prima, poi VPS2 derivata da 7636e12. Nessuna riapertura automatica
dei vecchi rifiuti. Vedere `vps-ponte/docs/sync-continuity-rollout.md` per piani,
controlli e recupero selettivo.

`tests/sync-contract.php WORDPRESS_CORE` usa il vero validatore/PUT e KSES con
storage isolato in memoria. È invocato dalla suite Python VPS2 tramite
`tools/test_sync_contract.sh`, con OAP_TEST_RECEIVER diretto a questo plugin.
La suite copre entrambi i rifiuti prima di qualsiasi scrittura e replay/versioni.
Eseguire anche le suite Docker esistenti del plugin in staging/CI.
