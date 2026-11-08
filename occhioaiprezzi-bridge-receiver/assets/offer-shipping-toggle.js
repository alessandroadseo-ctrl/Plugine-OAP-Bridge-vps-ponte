/* Bridge-only enhancement. VPS2 remains the source of markup and offer data. */
(() => {
    'use strict';
    const cents = value => {
        if (typeof value !== 'string' || value.trim() !== value || !/^[0-9]+\.[0-9]{2}$/.test(value)) return null;
        const [units, fraction] = value.split('.');
        const amount = Number(units) * 100 + Number(fraction);
        return Number.isSafeInteger(amount) ? amount : null;
    };
    const money = amount => Math.floor(amount / 100).toLocaleString('it-IT')
        + ',' + String(amount % 100).padStart(2, '0') + ' €';
    for (const product of document.getElementsByClassName('oap-product')) {
        for (const offers of product.querySelectorAll('.oap-bridge-offers')) {
            if (offers.closest('.oap-product') !== product) continue;
            const buttons = offers.querySelectorAll(':scope > button.oap-bridge-shipping-toggle');
            const lists = offers.querySelectorAll(':scope > .oap-bridge-offer-list');
            if (buttons.length !== 1 || lists.length !== 1) continue;
            const list = lists[0];
            const rows = Array.from(list.querySelectorAll(':scope > .oap-bridge-offer-row'));
            if (!rows.length || offers.querySelectorAll('.oap-bridge-offer-row').length !== rows.length) continue;
            const button = buttons[0];
            if (button.type !== 'button' || button.getAttribute('aria-pressed') !== 'false' || button.textContent.trim() !== 'Includi spedizione'
                || rows.some(row => !(button.compareDocumentPosition(row) & Node.DOCUMENT_POSITION_FOLLOWING))) continue;
            const entries = rows.map((row, index) => {
                const price = cents(row.getAttribute('data-oap-price'));
                const shippingText = row.getAttribute('data-oap-shipping');
                const totalText = row.getAttribute('data-oap-total');
                const unknown = shippingText === null && totalText === null;
                const shipping = cents(shippingText);
                const total = cents(totalText);
                const main = row.querySelector(':scope > .oap-bridge-price > p:first-child > strong');
                const merchant = row.querySelector(':scope > .oap-bridge-merchant');
                const cta = row.querySelector(':scope > .oap-bridge-offer');
                if (price === null || !main || !merchant || !cta || (!unknown &&
                    (shipping === null || total === null || !Number.isSafeInteger(price + shipping) || total !== price + shipping))) return null;
                return {row, index, price, total: unknown ? null : total, main, merchant, cta,
                    originalPrice: main.textContent, originalClass: cta.className,
                    totals: Array.from(row.querySelectorAll(':scope > .oap-bridge-price > .oap-bridge-total')),
                    badges: Array.from(merchant.querySelectorAll(':scope > .oap-bridge-best'))};
            });
            // Legacy or incomplete markup stays static, with all links intact.
            if (entries.some(entry => entry === null)) continue;
            let included = false;
            const originalNodes = Array.from(list.childNodes);
            const originalHidden = new Map(entries.flatMap(e => [...e.totals, ...e.badges]).map(n => [n, n.hidden]));
            const render = () => {
                const ordered = included ? entries.slice().sort((a, b) => {
                    if ((a.total === null) !== (b.total === null)) return a.total === null ? 1 : -1;
                    if (a.total !== null && a.total !== b.total) return a.total - b.total;
                    return a.price - b.price || a.index - b.index;
                }) : entries;
                const best = included && ordered[0].total === null ? null : ordered[0];
                // Avoid moving nodes at initialization or when the order is already correct.
                const current = Array.from(list.querySelectorAll(':scope > .oap-bridge-offer-row'));
                if (ordered.some((entry, index) => entry.row !== current[index])) {
                    if (included) {
                        for (const entry of ordered) list.appendChild(entry.row);
                    } else {
                        for (const node of originalNodes) list.appendChild(node);
                    }
                }
                for (const entry of entries) {
                    if (!included) {
                        entry.main.textContent = entry.originalPrice;
                        entry.cta.className = entry.originalClass;
                        for (const badge of entry.merchant.querySelectorAll(':scope > .oap-bridge-best')) {
                            if (!entry.badges.includes(badge)) badge.remove();
                        }
                        for (const node of [...entry.totals, ...entry.badges]) node.hidden = originalHidden.get(node);
                        continue;
                    }
                    entry.main.textContent = money(entry.total !== null ? entry.total : entry.price);
                    for (const total of entry.totals) total.hidden = entry.total !== null;
                    const badges = entry.row.querySelectorAll('.oap-bridge-best');
                    let badge = badges[0];
                    if (entry === best && !badge) {
                        badge = document.createElement('p');
                        badge.className = 'oap-bridge-best';
                        badge.textContent = '✓ Migliore offerta';
                        entry.merchant.prepend(badge);
                    }
                    for (const existing of badges) existing.hidden = existing !== badge || entry !== best;
                    if (badge) badge.hidden = entry !== best;
                    entry.cta.classList.toggle('oap-bridge-offer-best', entry === best);
                }
                button.setAttribute('aria-pressed', String(included));
            };
            button.addEventListener('click', () => { included = !included; render(); });
            button.disabled = false;
        }
    }
})();
