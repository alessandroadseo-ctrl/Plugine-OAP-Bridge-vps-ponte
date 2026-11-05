const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(__dirname + '/../assets/regeneration.js', 'utf8');
const storage = new Map();
const flush = async () => { for (let n = 0; n < 5; n++) await new Promise(setImmediate); };
function harness(responses) {
    const nodes = new Map(), timers = new Map(), requests = [];
    const buttons = ['start','pause','resume'].map(command => ({dataset:{command}, addEventListener(_, fn) {this.click = fn;}}));
    const root = {querySelectorAll: () => buttons};
    let id = 0;
    const context = {
        document:{getElementById(name) {if (name === 'oap-regeneration') return root; if (!nodes.has(name)) nodes.set(name, {}); return nodes.get(name);}},
        window:{oapRegeneration:{url:'/ajax',nonce:'nonce',storageKey:'test'}, confirm:()=>true},
        sessionStorage:{getItem:key=>storage.get(key)||null, setItem:(key,value)=>storage.set(key,value), removeItem:key=>storage.delete(key)},
        crypto:{randomUUID:()=> '00000000-0000-0000-0000-000000000001'}, URLSearchParams, AbortController,
        setTimeout(fn,delay) {timers.set(++id,{fn,delay}); return id;}, clearTimeout:key=>timers.delete(key),
        async fetch(url,args) {requests.push({url,args}); const response = responses.shift(); if (response instanceof Error) throw response; return {ok:true,json:async()=>({success:true,data:response})};},
    };
    vm.runInNewContext(source, context);
    return {nodes,buttons,timers,requests};
}
const snapshot = (state = 'idle', changes = {}) => ({campaign:{state,campaign_id:state==='idle'?null:'00000000-0000-0000-0000-000000000002', total:100,examined:80,remaining:70,updated:20,unchanged:5,superseded:3,errors:10},stale:false,pending:false,synchronized:true,last_sequence:0,received_at:100,observed_at:100,...changes});
(async () => {
    const first = harness([snapshot(), new Error('network unavailable'), snapshot('idle',{pending:true,synchronized:false})]);
    await flush();
    assert.equal(first.buttons[0].disabled,false);
    first.buttons[0].click(); first.buttons[0].click();
    await flush();
    assert.equal(first.requests.length,2,'double click emits one POST');
    const key = new URLSearchParams(first.requests[1].args.body).get('idempotency_key');
    assert(storage.has('test'),'uncertain intent survives local response loss');
    const retry = [...first.timers.values()].find(t=>t.delay===20000);
    retry.fn(); await flush();
    assert.equal(new URLSearchParams(first.requests[2].args.body).get('idempotency_key'),key,'retry preserves key');
    assert(!storage.has('test'));
    assert(first.buttons.every(button=>button.disabled),'pending disables all commands');
    const running = harness([snapshot('running')]); await flush();
    assert.equal(running.nodes.get('oap-regen-percent').textContent,'30.0%','progress counts terminal outcomes, not examined/errors');
    assert.equal(running.nodes.get('oap-regen-terminal_errors').textContent,'2');
    const stale = harness([snapshot('running',{stale:true})]); await flush();
    assert(stale.nodes.get('oap-regen-state').textContent.includes('non aggiornati'));
    assert.equal(stale.nodes.get('oap-regen-examined').textContent,'80','outage retains counters');
    assert(stale.buttons.every(button=>button.disabled));
    const completed = harness([snapshot('completed')]); await flush();
    assert([...completed.timers.values()].some(t=>t.delay===20000),'continue polling terminal state to detect outage');
    console.log('PASS: browser double click, same-key retry, pending commands, terminal progress, stale snapshot and continued polling');
})().catch(error=>{console.error(error); process.exitCode=1;});
