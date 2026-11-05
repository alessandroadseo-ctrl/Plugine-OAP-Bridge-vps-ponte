(() => {
    'use strict';
    const root = document.getElementById('oap-regeneration');
    if (!root) return;
    const config = window.oapRegeneration;
    const buttons = [...root.querySelectorAll('[data-command]')];
    const labels = {idle:'Non avviata', running:'In esecuzione', paused:'In pausa', completed:'Completata'};
    let snapshot = null, stale = true, busy = false, timer, intent = null;
    try { intent = JSON.parse(sessionStorage.getItem(config.storageKey)); } catch (_) { /* Storage is optional. */ }
    const set = (id, value) => { document.getElementById('oap-regen-' + id).textContent = value; };
    const date = value => value == null ? '—' : new Date(value * 1000).toLocaleString('it-IT', {timeZone:'Europe/Rome'}) + ' (Roma)';
    function draw() {
        const c = snapshot && snapshot.campaign;
        set('state', stale ? 'VPS2 non comunica: dati non aggiornati' : labels[c.state]);
        document.getElementById('oap-regen-last').textContent = (stale ? 'Ultimo stato ricevuto: ' + (c ? labels[c.state] : 'nessuno') + '. ' : '') +
            (snapshot && snapshot.received_at ? 'Ultima ricezione: ' + date(snapshot.received_at) : '');
        for (const key of ['total','examined','updated','unchanged','errors','remaining','superseded']) set(key, c ? String(c[key] || 0) : '—');
        set('terminal_errors', c ? String((c.total || 0) - (c.remaining || 0) - (c.updated || 0) - (c.unchanged || 0) - (c.superseded || 0)) : '—');
        set('created_at', c ? date(c.created_at) : '—');
        set('observed_at', snapshot ? date(snapshot.observed_at) : '—');
        const pct = c ? (c.total ? (c.total - c.remaining) / c.total * 100 : (c.state === 'completed' ? 100 : 0)) : 0;
        document.getElementById('oap-regen-progress').value = pct;
        set('percent', c ? pct.toFixed(1) + '%' : '—');
        for (const button of buttons) {
            const allowed = c && {start:['idle','completed'],pause:['running'],resume:['paused']}[button.dataset.command].includes(c.state);
            button.disabled = busy || stale || !!intent || snapshot.pending || !snapshot.synchronized || !allowed;
        }
    }
    async function request(command = 'status') {
        if (busy) return;
        busy = true;
        clearTimeout(timer);
        draw();
        const args = new URLSearchParams({action:'oap_regeneration', command, nonce:config.nonce});
        if (command !== 'status') {
            args.set('idempotency_key', intent.key);
            args.set('campaign_id', intent.campaign_id || '');
            args.set('sequence', String(intent.sequence));
        }
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 15000);
        try {
            const response = await fetch(config.url + (command === 'status' ? '?' + args : ''), {
                method:command === 'status' ? 'GET' : 'POST', body:command === 'status' ? undefined : args,
                credentials:'same-origin', cache:'no-store', signal:controller.signal
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                // Definitive rejection: do not keep retrying an unauthorized/obsolete intent.
                if (command !== 'status' && response.status >= 400 && response.status < 500) {
                    intent = null; try { sessionStorage.removeItem(config.storageKey); } catch (_) { /* Optional storage. */ }
                }
                throw new Error(result.data && result.data.message || 'Richiesta non confermata.');
            }
            snapshot = result.data;
            stale = snapshot.stale || !snapshot.campaign;
            if (command !== 'status') {
                intent = null; try { sessionStorage.removeItem(config.storageKey); } catch (_) { /* Optional storage. */ }
            }
            const receipt = snapshot.receipt;
            set('message', snapshot.message || (snapshot.pending ? 'Comando in attesa di conferma VPS2.' :
                !snapshot.synchronized ? 'Conferma ricevuta; attesa dello stato corrente VPS2.' :
                receipt && receipt.outcome === 'rejected' ? 'Comando rifiutato da VPS2: ' + receipt.error_code : 'Stato ricevuto da VPS2.'));
        } catch (error) {
            stale = true;
            set('message', error.name === 'AbortError' ? 'Tempo di attesa superato. Il comando sarà ritrasmesso con la stessa chiave.' : error.message);
        } finally {
            clearTimeout(timeout);
            busy = false;
            draw();
            // Always poll, including idle/completed: detect communication loss and external changes.
            timer = setTimeout(() => request(intent ? intent.action : 'status'), 20000);
        }
    }
    buttons.forEach(button => button.addEventListener('click', () => {
        if (button.disabled || busy || intent) return;
        if (button.dataset.command === 'start' && !window.confirm('Avviare la rigenerazione completa delle schede su VPS2?')) return;
        intent = {key:crypto.randomUUID(), action:button.dataset.command, campaign_id:snapshot.campaign.campaign_id, sequence:snapshot.last_sequence};
        try { sessionStorage.setItem(config.storageKey, JSON.stringify(intent)); } catch (_) { /* Same key remains in memory for retries. */ }
        request(intent.action);
    }));
    request();
})();
