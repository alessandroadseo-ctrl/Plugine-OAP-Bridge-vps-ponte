(() => {
    'use strict';
    const root = document.getElementById('oap-regeneration');
    if (!root) return;
    const config = window.oapRegeneration;
    const buttons = [...root.querySelectorAll('[data-command]')];
    const labels = {idle:'Non avviata', running:'In esecuzione', paused:'In pausa',
        completed:'Completata', completed_with_errors:'Completata con errori'};
    let snapshot = null, stale = true, busy = false, timer;
    const set = (id, value) => { document.getElementById('oap-regen-' + id).textContent = value; };
    const date = value => value ? new Date(value).toLocaleString('it-IT', {timeZone:'Europe/Rome'}) + ' (Roma)' : '—';
    function draw() {
        const c = snapshot && snapshot.campaign;
        set('state', stale ? 'Non raggiungibile' : labels[c.state]);
        document.getElementById('oap-regen-last').textContent = stale
            ? 'Dati non aggiornati. Ultimo stato ricevuto: ' + (c ? labels[c.state] : 'nessuno') : '';
        for (const key of ['total','examined','updated','unchanged','errors','remaining']) set(key, c ? String(c[key]) : '—');
        for (const key of ['started_at','updated_at']) set(key, c ? date(c[key]) : '—');
        const pct = c ? (c.total ? Math.min(100, c.examined / c.total * 100) : (c.state.startsWith('completed') ? 100 : 0)) : 0;
        document.getElementById('oap-regen-progress').value = pct;
        set('percent', c ? pct.toFixed(1) + '%' : '—');
        if (snapshot && snapshot.received_at) {
            document.getElementById('oap-regen-last').textContent += ' Ultima ricezione: ' + date(snapshot.received_at * 1000);
        }
        for (const button of buttons) {
            const allowed = c && {start:['idle','completed','completed_with_errors'],pause:['running'],resume:['paused']}[button.dataset.command].includes(c.state);
            button.disabled = busy || stale || !allowed;
        }
        document.getElementById('oap-regen-size').disabled = busy || !!(c && ['running','paused'].includes(c.state));
    }
    async function request(command = 'status') {
        if (busy) return;
        busy = true;
        clearTimeout(timer);
        draw();
        const args = new URLSearchParams({action:'oap_regeneration', command, nonce:config.nonce,
            batch_size:document.getElementById('oap-regen-size').value});
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 35000);
        try {
            const response = await fetch(config.url + (command === 'status' ? '?' + args.toString() : ''), {
                method:command === 'status' ? 'GET' : 'POST',
                body:command === 'status' ? undefined : args, credentials:'same-origin',
                cache:'no-store', signal:controller.signal
            });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.data && result.data.message || 'Richiesta non confermata.');
            snapshot = result.data;
            stale = snapshot.stale || !snapshot.campaign;
            set('message', snapshot.message || (snapshot.confirmed ? 'Comando confermato da VPS2.' : 'Stato aggiornato.'));
        } catch (error) {
            stale = true;
            set('message', error.name === 'AbortError' ? 'Tempo di attesa superato. Esito del comando non confermato.' : error.message);
        } finally {
            clearTimeout(timeout);
            busy = false;
            draw();
            // Continue retries during outages and pauses; stop after a fresh terminal state.
            if (stale || (snapshot && ['running','paused'].includes(snapshot.campaign.state))) {
                timer = setTimeout(() => request(snapshot && snapshot.pending ? 'retry' : 'status'), 20000);
            }
        }
    }
    buttons.forEach(button => button.addEventListener('click', () => {
        if (button.dataset.command === 'start' && !window.confirm('Avviare la rigenerazione completa delle schede su VPS2?')) return;
        request(button.dataset.command);
    }));
    request();
})();
