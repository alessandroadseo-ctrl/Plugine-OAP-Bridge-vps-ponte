# Collegamento worker: modifiche proposte, non applicate

Riferimento analizzato: vps-ponte `69a70c13bf92ea3df5a652e1f18b16dd3293c964`.

## Nessuna modifica obbligatoria per il normale ciclo attivo

Il client Python originale è stato eseguito contro HTTP reale nel WordPress isolato: eligibility, PUT, ACK, GET reconciliation e retry dopo perdita dell'ACK sono compatibili. Configurare base privata HTTPS `/wp-json/oap-bridge/v1/`, stesso token, e base pubblica corrispondente al namespace riservato del ricevitore. Nessun CPT/ID post: remote_id è una stringa stabile già accettata dal worker.

## Miglioramenti necessari per l'arbitraggio completo

1. **Codici di conflitto.** `WordPressClient.request()` attualmente scarta il JSON d'errore e conserva soltanto lo status. Leggere esclusivamente code da un formato verificato, mantenendo payload/header/URL fuori dai log. Distinguere remote_ahead, version_conflict, ownership, slug e Woo: non ritentare indefinitamente un 409 strutturale. I tre campi ACK esistenti devono continuare a essere verificati.
2. **Creazione/aggiornamento.** Il ricevitore restituisce operation e ownership oltre a remote_id. Il worker può registrarli per audit; il client attuale li passa ma il worker li ignora. GET assente deve continuare a essere versione 0/hash vuoto.
3. **Disattivazione per collisione Woo successiva.** Il client blocca qualsiasi publish (anche inactive) quando l'eligibility restituisce true. Il ricevitore sopprime comunque visualizzazione e sitemap con inventario autorevole locale. Per inviare un tombstone persistente occorre progettare un flusso esplicito: confermare ownership con GET e ottenere una proof limitata alla sola disattivazione di quella pagina. Non far passare indiscriminatamente Woo=true e non usare proof di un altro GTIN. Questa estensione **non è stata implementata** nel protocollo attuale.
4. **Proof scaduta.** Il client tratta 403 come permanente. Per carichi con attese prolungate, riconoscere oap_invalid_proof e rifare eligibility entro un numero limitato di tentativi; non rilassare la scadenza o il controllo DB.
5. **Remote-ahead in riconciliazione.** Il worker già genera remote_ahead permanente se GET.version è maggiore della revisione locale. Preservare questo comportamento e non azzerare tombstone/versioni sul ricevitore.
6. **Disattivazione mai pubblicata senza slug.** Il plugin richiede uno slug valido per ogni bundle, come il registro previsto dal contratto. Se il runner produce una deactivation con product.slug nullo per un GTIN mai pubblicato, evitare il publish di quella identità assente o definire prima un'estensione del tombstone senza slug. Non inventare URL pubblici derivandoli dal GTIN.
7. **Temporary e SEO.** Il contratto definisce temporary 200/noindex come proposta da confermare con SEO. Il plugin la implementa letteralmente; mantenere invio sospeso finché la policy del sito non è validata.

Non sono stati modificati Python, configurazioni operative o flag di pubblicazione del repository vps-ponte. Nessuna chiamata al sito reale.
