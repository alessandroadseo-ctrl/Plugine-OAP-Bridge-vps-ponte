# Redirect affiliati locali — versione 1.3.0

## Installazione manuale

Installare lo ZIP come aggiornamento del plugin esistente. Non viene eseguito alcun deploy automatico. Non cambiano token, credenziali, endpoint autenticato o impostazioni esistenti. Effettuare il normale backup prima dell'aggiornamento.

La prima richiesta dopo l'aggiornamento esegue `dbDelta`: aggiunge la tabella `${prefisso}oap_affiliate_destinations` InnoDB. Non elimina né converte tabelle o dati. Servono i permessi CREATE/ALTER già richiesti dall'attivazione del ricevitore. Le scritture falliscono con 503 se una tabella coinvolta non è InnoDB. Non viene creato alcun post WordPress.

Escludere `/compara` dalla cache del CDN/hosting, che deve rispettare `Cache-Control: no-store`. Non sono necessarie connessioni WireGuard o chiamate alla VPS2 durante il clic. Le precedenti impostazioni `OAP_BRIDGE_REDIRECT_BASE` e `OAP_BRIDGE_REDIRECT_TOKEN` rimangono intatte ma non vengono utilizzate. Le vecchie rotte `/compara/UUID`, prive di una mappatura locale nel contratto, restituiscono 404.

## Contratto VPS2

Aggiungere al payload JSON del PUT autenticato esistente `/wp-json/oap-bridge/v1/pages/GTIN`:

```json
{
  "affiliate_destinations": [
    {
      "code": "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA",
      "destination_url": "https://example.org/offerta?tag=originale&click=abc%2F123&x=1&x=2"
    }
  ]
}
```

Questo è un frammento da aggiungere al payload completo del prodotto, non un payload autonomo. Il GTIN è quello del prodotto nel payload e nella rotta: non va duplicato nelle singole voci. `code` è il codice CTA esistente, sensibile a maiuscole e minuscole, di 43 caratteri `[A-Za-z0-9_-]`. Ogni voce contiene esattamente `code` e `destination_url`; massimo 500 voci, nessun codice duplicato. Non generare nuovi codici per CTA già pubblicate.

- Campo assente: mantiene tutte le destinazioni del prodotto, anche nei retry e negli avanzamenti del cursore compatibili.
- Campo presente: lista completa delle destinazioni attive. Inserisce le nuove, aggiorna gli URL, revoca le mancanti nella stessa transazione del prodotto.
- Lista vuota `[]`: revoca tutte le destinazioni del prodotto. `null` non è valido.
- I codici restano riservati al GTIN originario anche dopo la revoca. Un altro prodotto non può riutilizzarli (409). Lo stesso GTIN può riattivarli.
- Anche un aggiornamento dei soli affiliati richiede una versione superiore e un nuovo `content_hash`. L'header `Idempotency-Key` rimane `content_hash:version`. Un hash invariato non può nascondere una modifica delle destinazioni.
- Per un prodotto esistente usare `operation: update`, preservando HTML, slug, canonical, SEO, stato e alias. Non reinviare `create`. Il ricevitore non ricalcola né inventa l'hash opaco di VPS2: includere questo nuovo campo nella logica di cambiamento del produttore.

Gli URL vengono validati e salvati come byte originali in `VARBINARY(4096)`: niente `esc_url`, decodifica, riordino dei parametri o rimozione dei duplicati. HTTPS obbligatorio, porta assente o 443, nessuna credenziale nell'URL, nessun carattere di controllo/spazio/backslash. Sono esclusi host interni del sito, localhost, domini locali/riservati, IP privati/riservati e multicast. All'ingestione vengono controllate anche le risoluzioni DNS IPv4/IPv6: un nome non risolvibile o con indirizzi interni viene rifiutato. È richiesta una risoluzione IPv4 pubblica; host esclusivamente IPv6 non sono accettati. Il DNS non viene interrogato al clic. Questi controlli riguardano il primo URL, non gli eventuali redirect successivi del merchant o cambiamenti futuri del DNS.

Gli URL originali sono solo nella tabella privata, non negli artefatti (che ne registrano l'impronta SHA-256 per l'idempotenza), nei post, nelle risposte ACK o in nuove API pubbliche. La destinazione è necessariamente visibile nell'header `Location` al visitatore che clicca. Il ricevitore non inserisce il campo nell'HTML o nel JSON-LD: VPS2 deve continuare a inviare CTA locali, senza URL affiliati nel contenuto pubblico.

## Verifica con i due GTIN richiesti

Ripetere per `03253561929809` e `03253561756726` su staging, usando i loro codici CTA e URL affiliati originali ricevuti da VPS2. Le fixture della suite locale usano questi stessi GTIN con URL sintetici; non attestano dati del sito reale.

1. Annotare versione/hash/remote_id tramite il GET autenticato già esistente, URL `/prodotti/SLUG/`, HTML CTA, canonical, sitemap e numero di prodotti. I segreti devono restare nel normale client VPS2, senza inserirli in log o comandi condivisi.
2. Reinviare il payload completo con `operation: update`, versione superiore, hash aggiornato e lista completa `affiliate_destinations`. Il GTIN deve restare lo stesso, l'ACK deve indicare `updated` e lo stesso `remote_id`.
3. Richiedere il codice effettivo senza seguire il redirect:

   ```sh
   curl -sS -D - -o /dev/null 'https://DOMINIO/compara?id=CODICE_ESISTENTE'
   ```

   Attesi: HTTP 302, `Location` uguale byte per byte all'originale, `Cache-Control` contenente `no-store`, `Referrer-Policy: no-referrer`. Ripetere con `curl -I` per HEAD.
4. Modificare un URL nella lista, incrementare versione e hash e reinviare. Il codice deve puntare al nuovo URL. Ripetere lo stesso invio: `unchanged`, senza duplicazioni. Un cambio URL con stessa versione/hash deve essere rifiutato con 409.
5. Rimuovere un codice dalla lista completa, incrementare versione/hash e reinviare. Quel codice e un codice inesistente devono restituire 404 senza `Location`. Gli altri codici del prodotto e quelli dell'altro GTIN devono restare funzionanti.
6. Inviare un aggiornamento precedente senza `affiliate_destinations`: nessuna destinazione deve essere cancellata. Provare anche `[]` su staging: tutti e soli i codici di quel GTIN devono diventare 404.
7. Verificare che HTML, CTA, slug `/prodotti/`, canonical e sitemap siano rimasti identici; cercare nell'HTML gli URL originali e verificarne l'assenza. Controllare che ACK autenticati e API REST pubbliche non li espongano. Controllare il numero dei prodotti e l'identità per GTIN: nessun nuovo post e nessuna duplicazione.

Prima di rendere operativo il nuovo gestore inviare da VPS2 le associazioni dei prodotti già pubblicati: il plugin non può recuperarle dalla rete privata. I codici non ancora sincronizzati restituiscono 404. Un aggiornamento senza questo nuovo campo conserva il contenuto preesistente ma non può creare destinazioni sconosciute.

## Test riproducibili

La suite usa WordPress 6.8.3, WooCommerce 10.3.5, MariaDB 11.4 e PHP 8.3 in container locali usa e getta. Non eseguirla sul sito reale. Vedere `tests/README.md`. I test `affiliates.php` e `affiliates_http.py` verificano creazione, modifica, revoca, lista vuota, payload legacy, idempotenza, isolamento tra GTIN, rollback su errore SQL, HTTP 302/404, header, contenuti e assenza di nuovi post.
