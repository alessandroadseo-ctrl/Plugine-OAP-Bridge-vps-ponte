// Browser tests: Node 18+, playwright and Chromium. No production services used.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {chromium} = require('playwright');
const fixture = fs.readFileSync(__dirname + '/fixtures/shipping-toggle.html', 'utf8');
const legacy = fs.readFileSync(__dirname + '/fixtures/vps2-product.html', 'utf8');
const source = fs.readFileSync(__dirname + '/../assets/offer-shipping-toggle.js', 'utf8');
(async () => {
    const browser = await chromium.launch({headless:true, ...(process.env.OAP_CHROMIUM ? {executablePath:process.env.OAP_CHROMIUM} : {})});
    try {
        const page = await browser.newPage();
        const errors = [], requests = [];
        page.on('pageerror', e => errors.push(e.message));
        await page.route('**/*', route => route.abort());
        const load = async html => {
            await page.setContent(html);
            await page.evaluate(() => {
                window.originalRows = Array.from(document.querySelectorAll('.oap-product .oap-bridge-offer-row'));
                window.originalLinks = originalRows.map(row => row.querySelector('a')?.outerHTML);
                window.originalDetails = originalRows.map(row => Array.from(row.querySelectorAll('img, .oap-bridge-merchant strong, .oap-bridge-merchant > p:not(.oap-bridge-best), .oap-bridge-price > p:not(:first-child)')).map(n => n.outerHTML));
                window.moves = 0;
                window.observer = new MutationObserver(records => { window.moves += records.filter(r => r.target.matches?.('.oap-bridge-offers') && Array.from(r.addedNodes).some(n => n.matches?.('.oap-bridge-offer-row'))).length; });
                observer.observe(document.body, {childList:true,subtree:true});
                window.networkCalls = 0;
                window.fetch = () => { networkCalls++; throw new Error('unexpected fetch'); };
                XMLHttpRequest.prototype.open = () => { networkCalls++; throw new Error('unexpected XHR'); };
                navigator.sendBeacon = () => { networkCalls++; return false; };
            });
            await page.addScriptTag({content:source});
        };
        const state = () => page.evaluate(() => {
            const root = document.querySelector('.oap-product');
            const rows = Array.from(root.querySelectorAll('.oap-bridge-offer-row'));
            return {order:rows.map(r => r.getAttribute('data-oap-price')),
                prices:rows.map(r => r.querySelector('.oap-bridge-price strong').textContent),
                best:rows.filter(r => r.querySelector('.oap-bridge-best:not([hidden])')).map(r => r.getAttribute('data-oap-price')),
                cta:rows.filter(r => r.querySelector('.oap-bridge-offer-best')).map(r => r.getAttribute('data-oap-price')),
                pressed:root.querySelector('button')?.getAttribute('aria-pressed')};
        });
        const preserved = async () => assert.equal(await page.evaluate(() => originalRows.every((r,i) => r.isConnected &&
            r.querySelector('a')?.outerHTML === originalLinks[i] &&
            JSON.stringify(Array.from(r.querySelectorAll('img, .oap-bridge-merchant strong, .oap-bridge-merchant > p:not(.oap-bridge-best), .oap-bridge-price > p:not(:first-child)')).map(n=>n.outerHTML)) === JSON.stringify(originalDetails[i]))), true, 'same nodes, exact affiliate href/target/rel, logos, descriptions, shipping and totals');
        const outside = fixture.replace('class="oap-product"', 'id="outside"') + '<div id="legacy">' + legacy + '</div>';
        await load(fixture + outside + fixture);
        const untouched = await page.locator('#outside').evaluate(n=>n.outerHTML);
        const untouchedLegacy = await page.locator('#legacy').evaluate(n=>n.outerHTML);
        assert.equal(await page.evaluate(()=>moves), 0, 'no initial reorder');
        assert.deepEqual(await state(), {order:['7.99','8.99','9.99'],prices:['7,99 €','8,99 €','9,99 €'],best:['7.99'],cta:['7.99'],pressed:'false'});
        // Native button keyboard activation also updates assistive technology state.
        const button = page.locator('.oap-product button').first();
        await button.focus();
        for (let cycle=0; cycle<3; cycle++) {
            await page.keyboard.press(cycle % 2 ? 'Space' : 'Enter');
            assert.deepEqual(await state(), {order:['9.99','8.99','7.99'],prices:['9,99 €','13,99 €','7,99 €'],best:['9.99'],cta:['9.99'],pressed:'true'});
            await preserved();
            await page.keyboard.press('Space');
            assert.deepEqual(await state(), {order:['7.99','8.99','9.99'],prices:['7,99 €','8,99 €','9,99 €'],best:['7.99'],cta:['7.99'],pressed:'false'});
        }
        assert.equal(await page.locator('#outside').evaluate(n=>n.outerHTML), untouched);
        assert.equal(await page.locator('#legacy').evaluate(n=>n.outerHTML), untouchedLegacy);
        assert.equal(await page.locator('.oap-product').last().locator('button').getAttribute('aria-pressed'), 'false', 'products independent');
        await preserved();
        assert.equal(await page.evaluate(()=>networkCalls), 0);
        page.on('request', r => requests.push(r.url()));
        // Generate cases from the contractual markup using real DOM nodes.
        const variant = async (count, unknown=false) => {
            await page.setContent(fixture);
            return page.evaluate(({count,unknown}) => {
                const root = document.querySelector('.oap-product');
                const offers = root.querySelector('.oap-bridge-offers');
                const template = offers.querySelector('.oap-bridge-offer-row').cloneNode(true);
                offers.querySelectorAll('.oap-bridge-offer-row').forEach(r=>r.remove());
                for(let i=0;i<count;i++) {
                    const row = template.cloneNode(true);
                    row.setAttribute('data-oap-price', (i+1)+'.01');
                    if (!unknown) {
                        row.setAttribute('data-oap-shipping', (2*(count-i))+'.00');
                        row.setAttribute('data-oap-total', (2*count-i+1)+'.01');
                    }
                    offers.appendChild(row);
                }
                return root.outerHTML;
            },{count,unknown});
        };
        for (const count of [1,100]) {
            await load(await variant(count));
            for(let i=0;i<3;i++) {
                await page.locator('button').click();
                let s = await state();
                assert.equal(s.order.length,count); assert.equal(s.order[0], count+'.01');
                assert.equal(s.prices[0],(count+2)+',01 €'); assert.deepEqual(s.cta,[count+'.01']);
                await page.locator('button').click();
                s=await state(); assert.equal(s.order[0],'1.01'); assert.deepEqual(s.best,['1.01']);
            }
            await preserved(); assert.equal(await page.evaluate(()=>networkCalls),0);
        }
        await load(await variant(3,true));
        await page.locator('button').click();
        assert.deepEqual((await state()).order,['1.01','2.01','3.01']);
        assert.deepEqual((await state()).best,[]); assert.deepEqual((await state()).cta,[]);
        await page.locator('button').click(); assert.deepEqual((await state()).best,['1.01']);
        const invalid = ['NaN','-1.00','1,00','1.000','1e2','', '1.00\n', '90071992547409.92'];
        for (const value of invalid) {
            await load(fixture.replace('data-oap-price="7.99"', `data-oap-price="${value}"`));
            const before=await page.locator('article').evaluate(n=>n.outerHTML);
            await page.locator('button').click();
            assert.equal(await page.locator('article').evaluate(n=>n.outerHTML),before,'invalid data ignored: '+value);
        }
        for(const html of [fixture.replace(' data-oap-price="7.99"',''),fixture.replace(' data-oap-total="13.99"',''),fixture.replace('data-oap-total="13.99"','data-oap-total="14.00"'),fixture.replace('class="oap-product"','class="other"'),legacy]) {
            await load(html);
            const before=await page.locator('body').evaluate(n=>n.innerHTML);
            if(await page.locator('button').count()) await page.locator('button').click();
            assert.equal(await page.locator('body').evaluate(n=>n.innerHTML),before);
        }
        await load(fixture.replace('data-oap-price="7.99"','data-oap-price="90071992547409.91"'));
        assert.equal((await state()).prices.at(-1),'90.071.992.547.409,91 €','integer cents formatting');
        assert.deepEqual(errors,[],'no browser errors');
        // Asset requests from fixture images are aborted; no request can be triggered by clicks.
        assert(requests.every(url=>url==='https://images.test/logo.png' || url==='https://images.test/product.jpg'));
        console.log('PASS: Chromium shipping toggle (keyboard, repeated states, ordering, free/unknown shipping, prices, badges/CTA, exact affiliate attributes, 1/100 offers, invalid/legacy markup, isolation, no HTTP)');
    } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
