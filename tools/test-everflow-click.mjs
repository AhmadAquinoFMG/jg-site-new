import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import vm from 'node:vm';

// Read the actual public config; execute only a stubbed SDK, with no network calls.
const config = JSON.parse(execFileSync('php', ['-r',
    '$c = require "config.php"; $e = $c["everflow"]; echo json_encode(["offerId"=>$e["offer_id"]]);'
], { encoding: 'utf8' }));
assert.equal(config.offerId, '1072', 'This site serves offer 1072 only');

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

    const click = run('?oid=1072&affid=1225')[0];
    assert.equal(click.offer_id, '1072');
    assert.equal(click.affiliate_id, '1225');
    assert.equal(run('?oid=1072&affid=1225').length, 0, 'Reload must not duplicate click');
    storage.clear();
    // Any other oid, or none, still clicks on 1072.
    for (const search of ['?affid=300', '?oid=914&affid=989', '?oid=915&affid=300', '?oid=1067&affid=300', '?oid=9999&affid=300']) {
        storage.clear();
        assert.equal(run(search)[0].offer_id, '1072', `${search} must click on 1072`);
    }
    assert.equal(run('?oid=1072').length, 0, 'An affiliate is required');
    assert.equal(run('').length, 0, 'No params, no Everflow');

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
    assert.equal(runCookie('?affid=1225', stale915), '', '1072 must never take another offer\'s transaction id');
    assert.equal(runCookie('?affid=1225', 'ef_tid_other=z|any'), '', 'No any-cookie fallback');
    assert.equal(runCookie('?affid=1225', `${stale915}; ef_tid_c_o_1072=x|new1072`), 'new1072', '1072 uses its own cookie');
    console.log(`${file}: offer selection, deduplication, and transaction handoff passed.`);
}
