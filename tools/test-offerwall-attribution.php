<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/routing.php';
$offers = require __DIR__ . '/../includes/offerwall-campaigns.php';
$cfg = [
    'first_party_affids' => ['989', '995', '1024'],
    'offer_first_party' => '914',
    'offer_third_party' => '915',
    'offer_names' => ['914' => 'jg_1st_party', '915' => 'jg_3rd_party', '1067' => 'jg_email'],
    'click_offer_overrides' => ['1067'],
    'partner_click_subs' => ['819' => 'sub1', '1024' => 'sub2', '464' => 'sub5', '666' => 'sub6'],
];
$check = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};
$query = static function (string $url): array {
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    return $q;
};

foreach (array_merge($offers['campaigns'], $offers['student_campaigns']) as $offer) {
    $cases = [
        '989' => ['1550', '914_jg_1st_party'],
        '995' => ['1550', '914_jg_1st_party'],
        '1024' => ['1550', '914_jg_1st_party'],
        '777' => ['1551', '915_jg_3rd_party'],
    ];
    foreach ($cases as $affid => [$event, $parent]) {
        $lead = ['oid' => '914', 'affid' => (string) $affid, 'ef_transaction_id' => 'parent-click'];
        $q = $query(decline_offerwall_cta_url($offer['cta_link'], $lead, $cfg));
        $check(($q['sub7'] ?? '') === 'parent-click' && ($q['sub8'] ?? '') === $event && ($q['sub9'] ?? '') === $parent, $offer['id'] . ': wrong attribution');
        // The visit has no inbound subs, so nothing may land in sub1/sub2
        // (click id and event moved to sub7/sub8).
        $check(!isset($q['sub1']) && !isset($q['sub2']), $offer['id'] . ': click/event must no longer use sub1/sub2');
        foreach (['oid', 'affid', 'ef_transaction_id'] as $missing) {
            $incomplete = $lead;
            unset($incomplete[$missing]);
            $check(decline_offerwall_cta_url($offer['cta_link'], $incomplete, $cfg) === $offer['cta_link'], 'Missing ' . $missing . ' must leave link unchanged');
            $incomplete[$missing] = '   ';
            $check(decline_offerwall_cta_url($offer['cta_link'], $incomplete, $cfg) === $offer['cta_link'], 'Blank ' . $missing . ' must leave link unchanged');
        }
    }
}

// Lead 31629's shape: first-party affid 995 arriving with ?oid=915. The click
// lands on 914 (affid decides), so sub9 must say 914, not the URL's 915.
$mismatch = ['oid' => '915', 'affid' => '995', 'ef_transaction_id' => 'x'];
$check(str_contains(decline_offerwall_cta_url('https://example.com/o', $mismatch, $cfg), 'sub9=914_jg_1st_party'), 'sub9 follows affid, not ?oid=');

// Allowlisted email offer: ?oid=1067 wins over the affid split, for any affid.
foreach (['300', '989'] as $emailAffid) {
    $email = ['oid' => '1067', 'affid' => $emailAffid, 'ef_transaction_id' => 'x'];
    $check(str_contains(decline_offerwall_cta_url('https://example.com/o', $email, $cfg), 'sub7=x&sub8=1567&sub9=1067_jg_email'), 'Allowlisted oid 1067 must set sub8=1567 and sub9=1067_jg_email');
}
$check(everflow_offer_for_affid('300', $cfg, '1067') === '1067', 'oid 1067 selects offer 1067');
$check(everflow_offer_for_affid('300', $cfg, '999') === '915', 'Non-allowlisted oid is ignored');
$check(everflow_offer_for_affid('', $cfg, '1067') === null, 'No affid still means no Everflow');

$lead = ['oid' => '915', 'affid' => '989', 'transaction_id' => 'click +/id'];
$url = 'https://example.com/offer?keep=a%20b&sub7=old&sub8=old&sub9=old&sub7=duplicate&sub1=untouched#details';
$expected = 'https://example.com/offer?keep=a%20b&sub1=untouched&sub7=click%20%2B%2Fid&sub8=1550&sub9=914_jg_1st_party#details';
$check(decline_offerwall_cta_url($url, $lead, $cfg) === $expected, 'Replace subs, encode click ID, preserve query/fragment, classify by affiliate');
$check(decline_offerwall_cta_url($expected, $lead, $cfg) === $expected, 'Repeated application must not duplicate subs');

$unnamed = $cfg;
unset($unnamed['offer_names']);
$check(decline_offerwall_cta_url('https://example.com/o?sub9=keep', $lead, $unnamed) === 'https://example.com/o?sub9=keep&sub7=click%20%2B%2Fid&sub8=1550', 'Unnamed offer must not set or strip sub9');

$lead['ef_transaction_id'] = 'preferred';
$check(str_contains(decline_offerwall_cta_url($url, $lead, $cfg), 'sub7=preferred&sub8=1550&sub9=914_jg_1st_party'), 'Prefer resolved Everflow transaction ID');
$lead['affid'] = ['989'];
$check(decline_offerwall_cta_url($url, $lead, $cfg) === $url, 'Reject array input');

// ---- Partner's own inbound id, in the sub their global postback reads ----
// Never our ef_transaction_id (ef1) in sub1-sub5.
$base = ['oid' => '915', 'ef_transaction_id' => 'ef1', 'sub1' => 'p1', 'sub2' => 'p2', 'sub5' => 'p5', 'sub6' => 'p6'];
// 819 reads {sub1}: sub1 carries the partner's value, sub7 ours.
$check(decline_offerwall_cta_url('https://example.com/o', ['affid' => '819'] + $base, $cfg)
    === 'https://example.com/o?sub1=p1&sub7=ef1&sub8=1551&sub9=915_jg_3rd_party', '819 -> partner value in sub1');
// 464 reads {sub5}; an existing sub5 on the link is replaced, not duplicated.
$check(decline_offerwall_cta_url('https://example.com/o?sub5=stale', ['affid' => '464'] + $base, $cfg)
    === 'https://example.com/o?sub5=p5&sub7=ef1&sub8=1551&sub9=915_jg_3rd_party', '464 -> partner value in sub5, stale replaced');
// First-party 1024 reads {sub2}.
$check(($query(decline_offerwall_cta_url('https://example.com/o', ['affid' => '1024'] + $base, $cfg))['sub2'] ?? '') === 'p2', '1024 -> partner value in sub2');
// Unmapped partner: no partner sub, sub7 only.
$q = $query(decline_offerwall_cta_url('https://example.com/o', ['affid' => '777'] + $base, $cfg));
$check(!isset($q['sub1']) && !isset($q['sub2']) && !isset($q['sub5']) && ($q['sub7'] ?? '') === 'ef1', 'Unmapped affid gets no partner sub');
// Mapping outside sub1-sub5 is ignored.
$check(!isset($query(decline_offerwall_cta_url('https://example.com/o', ['affid' => '666'] + $base, $cfg))['sub6']), 'Only sub1-sub5 may be a partner sub');
// Mapped partner with no inbound value: nothing in that sub (not our click id).
$check(decline_offerwall_cta_url('https://example.com/o', ['affid' => '819', 'oid' => '915', 'ef_transaction_id' => 'e'], $cfg)
    === 'https://example.com/o?sub7=e&sub8=1551&sub9=915_jg_3rd_party', 'Missing partner value adds nothing');
// Partner value still passes when our own click is missing.
$check(decline_offerwall_cta_url('https://example.com/o', ['affid' => '819', 'sub1' => 'p1'], $cfg) === 'https://example.com/o?sub1=p1', 'Partner sub independent of parent click');

// Live config: ?oid=1067 must select 1067 server-side (thank-you conversion +
// offerwall sub8/sub9), the same list the landing JS reads.
$liveEf = (require __DIR__ . '/../config.php')['everflow'];
$check(everflow_offer_for_affid('300', $liveEf, '1067') === '1067', 'Live config: oid 1067 must select offer 1067');
$check(everflow_offer_for_affid('300', $liveEf, '999') === '915', 'Live config: unlisted oid falls back to affid split');
$check(str_contains(decline_offerwall_cta_url('https://example.com/o', ['oid' => '1067', 'affid' => '300', 'ef_transaction_id' => 'x'], $liveEf), 'sub8=1567&sub9=1067_jg_email'), 'Live config: email CTA gets sub8=1567 & sub9=1067_jg_email');

// Live config: every mapping must name sub1-sub5.
$live = $liveEf['partner_click_subs'] ?? [];
$check($live !== [], 'config partner_click_subs is empty');
foreach ($live as $affid => $sub) {
    $check(ctype_digit((string) $affid) && preg_match('/^sub[1-5]$/', $sub) === 1, "config partner_click_subs[$affid] invalid");
}
echo "Offerwall attribution checks passed for all general and student offers.\n";
