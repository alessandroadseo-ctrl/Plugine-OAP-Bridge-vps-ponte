# Cancellazione definitiva — Receiver 1.5.0

Questa modifica è solo al ricevitore. Non installa plugin e non chiama il sito reale.
Il worker VPS2 attuale (`app/workers/runner.py`) pubblica ancora disattivazioni con
PUT: deve adottare esplicitamente il contratto DELETE qui descritto. `inactive`
e `noindex` restano compatibili con il protocollo precedente e **non** richiedono
implicitamente una cancellazione. Il ricevitore non decide l'idoneità del prodotto
e non sostituisce i controlli feed, inventario o protezione eseguiti da VPS2.

## Richiesta

`DELETE /wp-json/oap-bridge/v1/pages/{gtin}` con HTTPS, Bearer dedicato,
`Content-Type: application/json`, gate `OAP_BRIDGE_WRITES_ENABLED=true` e
`Idempotency-Key: delete:{gtin}:{version}`. Limite corpo: 1 MiB.

```json
{
  "protocol": "oap-wp-1",
  "operation": "delete",
  "gtin": "00012345678905",
  "version": 42,
  "media_policy": {
    "delete_products": [],
    "protected_products": []
  }
}
```

VPS2 deve congelare richiesta e versione prima dell'invio e ritrasmettere lo
stesso evento fino all'ACK. `delete_products` contiene gli hash SHA-256 delle
immagini prodotto che VPS2 autorizza a rimuovere; `protected_products` contiene
quelle protette. Sono liste obbligatorie, massimo 500 hash ciascuna. La protezione
prevale sempre, anche se un hash compare in entrambe. Liste vuote non autorizzano
la cancellazione di immagini. I loghi merchant non sono cancellati.

VPS2 deve applicare le sue protezioni prima di inviare DELETE; non deve spedire
HTML, offerte o destinazioni affiliate nel tombstone. Il ricevitore non dispone
di un inventario dei file in corso di trasferimento: VPS2 deve proteggere anche
i media destinati a pubblicazioni pendenti. Sono ammesse soltanto immagini nella
cartella privata Bridge `media/products`, appartenenti a un artefatto rimosso e
senza riferimenti da pagine Bridge correnti o contenuti/metadati WordPress.
Nessuna cancellazione di attachment, post, postmeta, file uploads o URL esterni.

## Risposte e versioni

- HTTP 200: `operation=deleted`, GTIN, versione, `installed_hash=""`,
  `remote_id=oap:{gtin}`, `ownership=bridge`. Anche GET restituisce questo ACK.
- DELETE ripetuto alla stessa versione restituisce lo stesso ACK e ritenta
  pulizia/invalidation. Una versione superiore aggiorna il tombstone.
- HTTP 409: evento vecchio, versione uguale a una pubblicazione, owner estraneo,
  oppure identità mai gestita (nessuno slug viene inventato).
- HTTP 401/403: Bearer/TLS; 503: scritture disabilitate o errore recuperabile.
- Una pubblicazione attiva con versione superiore può usare `create` o `update`:
  crea una nuova riga e rimuove il tombstone nella stessa transazione. Versioni
  precedenti, anche DELETE in ritardo, non possono cancellarla o ripristinarla.
  Un PUT inattivo non può sostituire un tombstone con l'intera vecchia pagina.

Il client VPS2 deve riconoscere l'ACK `deleted` e l'hash vuoto, senza trattarli
come un documento pubblicato o un conflitto hash. Il vecchio client richiede
quindi un aggiornamento coordinato prima di attivare l'invio DELETE.

## Persistenza, HTTP e cache

La transazione elimina `oap_pages` e tutte le righe affiliate del GTIN. Conserva
solo `oap_tombstones(gtin,version,slug)` e i claim URL storici già presenti in
`oap_slugs(slug,gtin)`, necessari a rispondere 410 anche sugli alias. Non conserva
HTML, SEO, offerte, hash del documento o destinazioni affiliate nel tombstone.
La migrazione aggiunge la tabella anche durante un aggiornamento del plugin.

Dopo il commit vengono rimossi tutti gli artefatti privati non referenziati del
GTIN, comprese le versioni storiche. Una risposta 200 viene emessa solo dopo la
pulizia. Un errore restituisce 503 e lascia già attivo il tombstone: ritentare lo
stesso DELETE completa la pulizia. Le scritture e i controlli di condivisione
sono serializzati con i lock esistenti; un rollback DB non può ripristinare una
pagina il cui file sia già stato eliminato. Il filesystem non è transazionale:
in caso di interruzione dopo commit, il retry è necessario per completare il lavoro.

GET e HEAD sugli URL correnti, storici e alias restituiscono direttamente 410,
`no-cache`, `noindex, nofollow`, senza tema, canonical, redirect, HTML o JSON-LD
preesistenti. Le destinazioni `/go` eliminate restituiscono 404. La sitemap legge
solo righe attive e quindi esclude immediatamente il GTIN. Canonical di altre
pagine Bridge che puntano agli URL eliminati ripiegano sul proprio URL, anche
nella sitemap; vengono invalidati i relativi URL pubblici.

L'invalidazione avviene dopo commit, anche sui retry, prima della pulizia dei
file. Include tutti gli alias, i media indicati, l'indice sitemap e il provider
Bridge; integra LiteSpeed, WP Rocket e W3 Total Cache se presenti. Il sito può
collegare la propria CDN/reverse proxy all'hook `oap_bridge_purge_url($url)`;
resta disponibile anche `oap_bridge_page_changed`. Un servizio esterno privo di
adapter non può essere svuotato dal solo plugin. Il reverse proxy deve rispettare
le esclusioni cache documentate per le rotte Bridge. Nessuna chiamata alla CDN
reale viene eseguita dai test.

## Verifica isolata

`tests/run.sh` include `deletion.php` e `deletion_http.py`: cancellazione fisica,
retry, versioni, owner estraneo, immagini condivise/protette/native, errore di
pulizia e ripresa, canonical/sitemap, autenticazione e GET/HEAD 410, 405,
assenza di Location/HTML/SEO, ripubblicazione e rifiuto di eventi vecchi.
