# Verifica ricevitore esclusivo — versione 1.1.0

## Ambito

Branch creato da main dopo merge PR#1 (`d81305a`). Modificato soltanto `alessandroadseo-ctrl/Plugine-OAP-Bridge-vps-ponte`. Il checkout del Ponte `69a70c13bf92ea3df5a652e1f18b16dd3293c964` è stato letto per documentare gli interventi e importare il client nei test; `git status --short` resta vuoto. Nessuna modifica o attivazione sulla VPS o sul sito reale.

Rimossi classe/adattatore GTIN, letture Woo/postmeta, proof, veto di pubblicazione, soppressione per visita/sitemap, generazione canonical e fallback inactive, switch JSON-LD locale e aggiunta automatica di redirect. Il plugin non gestisce eventi di catalogo. Aggiunto comando create/update obbligatorio dalla VPS con precondizioni atomiche, mantenendo ownership, unicità, versioni e retry.

HTML accettato e SEO sono salvati come ricevuti; gli HTML che richiederebbero riscrittura di sicurezza vengono rifiutati. Gli stati applicano gli status del contratto; gli alias sono soltanto quelli richiesti. Non viene inventato un nuovo ciclo di pubblicazione nel ricevitore.

## Ambiente e risultato effettivi

WordPress 6.8.3, PHP 8.3.36, MariaDB 11.4.13; WooCommerce 10.3.5 usato solo come fixture e anche disattivato durante i test. Database isolato `oap_test`, host fittizio `receiver.test`, porta esposta soltanto 127.0.0.1:18086. HTTPS è simulato dal fixture per rappresentare terminazione fidata; nessun TLS del sito reale è stato collaudato.

- Lint PHP valido per tutti i file e fixture.
- **55 esiti PASS** nella suite completa: integrazione WordPress reale, processi concorrenti, HTTP reale.
- Sei create concorrenti: una creazione, cinque ACK identici. Sei update concorrenti: un aggiornamento, cinque ACK identici. Stessa identità remota, nessun duplicato.
- Un writer SQL su wp_postmeta completa la scrittura mentre il ricevitore tiene i propri lock: il ricevitore non blocca né arbitra l'inventario Woo.
- Comando senza operation e automatic upsert rifiutati; create non sovrascrive esistente, update non crea assente; versioni e proprietà controllate atomicamente.
- Token/HTTPS/body limit/HTML/URL/JSON-LD, owner estraneo e contenuto nativo protetti.
- HTML e metadati memorizzati invariati; canonical diverso dallo slug conservato anche nella sitemap; JSON-LD ricevuto reso nonostante il vecchio switch locale false.
- GTIN presente in prodotto semplice o variante non pone veto alla VPS; modifiche Woo successive non sopprimono pagine/sitemap; rendering, sitemap e scrittura funzionano senza Woo caricato.
- Redirect soltanto espliciti, alias omesso 404, alias ricevuto 301 locale indipendente da canonical SEO, inactive temporary/gone applicati, nessun titolo/corpo di fallback.
- ACK perso dopo commit reale: retry create restituisce unchanged. Il client originale conserva GET/request/ACK compatibili, mentre il suo vecchio publish si ferma correttamente al 410 eligibility senza scrivere.

Il flusso nuovo nel test HTTP è un preparatore locale rappresentativo della VPS, non una modifica del repository vps-ponte. Il transport cambia l'indirizzo verso localhost e inietta soltanto la perdita della risposta dopo una scrittura realmente avvenuta. Tutte le risposte applicative provengono da WordPress reale.

Log della suite in `docs/test-results.txt`. Setup e teardown riproducibili sotto tests/, senza accesso alla produzione.

## Limiti e attivazione

Il plugin non garantisce più ammissibilità o assenza di duplicati **fra** catalogo Ponte e catalogo Woo: quella responsabilità è della VPS. Continua a garantire unicità **interna** per GTIN e protezione di pagine estranee. Nel Ponte di riferimento il nuovo adattatore inventario non esiste ancora: fonte autorevole, consistenza, race ed eventi devono essere gestiti lì prima di riabilitare il flusso.

Il contratto di pubblicazione richiede migrazione coordinata: operation obbligatoria, nessun GET eligibility/proof. Non dichiarare il vecchio client publish già compatibile. Vedere ponte-changes.md per file e modifiche esatte.

Non collaudati MySQL8, altre versioni PHP, carico catalogo, tema, proxy/TLS, cache/CDN e sitemap SEO terze parti del sito reale. Lock globali sugli indici e sui post nativi mantengono un costo di serializzazione; la tabella metadati non è più inclusa. Nessuna migrazione distruttiva del DB/plugin e nessuna rimozione di tombstone/artefatti storici.

OAP_BRIDGE_WRITES_ENABLED rimane false per default. Il valore true esiste solo nella fixture del DB usa e getta. Worker e VPS non sono stati modificati né abilitati.
