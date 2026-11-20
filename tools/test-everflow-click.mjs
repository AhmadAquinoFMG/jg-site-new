import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import vm from 'node:vm';

// Read the actual public config; execute only a stubbed SDK, with no network calls.
const config = JSON.parse(execFileSync('php', ['-r',
    '$c = require "config.php"; $e = $c["everflow"]; echo json_encode(["offerFirstParty"=>$e["offer_first_party"], "offerThirdParty"=>$e["offer_third_party"], "firstPartyAffids"=>$e["first_party_affids"], "clickOfferOverrides"=>$e["click_offer_overrides"]]);'
], { encoding: 'utf8' }));

for (const file of ['assets/js/tracking/everflow.js', 'assets/dist/everflow.min.js']) {
    const source = readFileSync(file, 'utf8');
    const storage = new Map();
    function run(search) {
        const clicks = [];
        const fields = { affid: { value: '' }, efTransactionId: { value: '' } };
        const context = {
            window: { FUNNEL: { everflow: config } },
            location: { search }, URLSearchParams,
            document: {
                getElementById: id => fields[id] || null,
                addEventListener() {}, removeEventListener() {},
            },
            sessionStorage: {
                getItem: key => storage.get(key) || null,
                setItem: (key, value) => storage.set(key, value),
            },
            EF: {
                urlParameter: key => new URLSearchParams(search).get(key),
                click: payload => {
                    clicks.push(payload);
                    return { then(resolve) { resolve('test-transaction'); } };
                },
            },
            setTimeout() {}, setInterval() {}, clearInterval() {},
        };
        vm.runInNewContext(source, context);
        context.window.initEverflow?.();
        if (clicks.length) assert.equal(fields.efTransactionId.value, 'test-transaction');
        return clicks;
    }

    assert.equal(run('?oid=915&affid=300')[0].offer_id, '915');
    const click = run('?oid=1067&affid=300')[0];
    assert.equal(click.offer_id, '1067');
    assert.equal(click.affiliate_id, '300');
    assert.equal(run('?oid=1067&affid=300').length, 0, 'Reload must not duplicate click');
    assert.equal(run('?oid=915&affid=300').length, 0, 'Prior offer remains deduplicated');
    storage.clear();
    assert.equal(run('?affid=300')[0].offer_id, '915');
    storage.clear();
    assert.equal(run('?oid=9999&affid=300')[0].offer_id, '915');
    storage.clear();
    assert.equal(run('?affid=989')[0].offer_id, '914');
    assert.equal(run('?oid=1067&affid=989')[0].offer_id, '1067');
    assert.equal(run('?oid=1067').length, 0, 'An affiliate is required');

    // Cookie fallback (SDK without a promise). Returns the transaction id the
    // form would post, given the cookies present.
    function runCookie(search, cookie) {
        const fields = { affid: { value: '' }, efTransactionId: { value: '' } };
        const context = {
            window: { FUNNEL: { everflow: config } },
            location: { search }, URLSearchParams,
            document: {
                cookie,
                getElementById: id => fields[id] || null,
                addEventListener() {}, removeEventListener() {},
            },
            sessionStorage: { getItem: () => null, setItem() {} },
            EF: { urlParameter: () => null, click: () => undefined },
            setTimeout() {},
            // Run the poll to completion synchronously.
            setInterval(fn) { let live = true; context.clearInterval = () => { live = false; }; for (let i = 0; i < 60 && live; i++) fn(); },
            clearInterval() {},
        };
        vm.runInNewContext(source, context);
        context.window.initEverflow?.();
        return fields.efTransactionId.value;
    }
    const stale915 = 'ef_tid_c_o_915=a|b|old915';
    assert.equal(runCookie('?oid=1067&affid=300', stale915), '', '1067 must never take a 914/915 transaction id');
    assert.equal(runCookie('?oid=1067&affid=300', `${stale915}; ef_tid_c_o_1067=x|new1067`), 'new1067', '1067 uses its own cookie');
    assert.equal(runCookie('?affid=300', 'ef_tid_other=z|any'), 'any', 'Non-override offers keep the any-cookie fallback');
    console.log(`${file}: offer selection, deduplication, and transaction handoff passed.`);
}
