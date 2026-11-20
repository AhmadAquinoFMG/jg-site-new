<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/routing.php';
$offers = require __DIR__ . '/../includes/offerwall-campaigns.php';
$cfg = [
    'offer_id' => '1072',
    'offer_name' => 'jg_bid_on_calls_cpl',
    'decline_event_id' => '1585',
    'partner_click_subs' => ['819' => 'sub1', '1024' => 'sub2', '464' => 'sub5', '666' => 'sub6'],
];
$parent = '1072_jg_bid_on_calls_cpl';
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
    foreach (['1225', '989', '777'] as $affid) {
        $lead = ['oid' => '1072', 'affid' => $affid, 'ef_transaction_id' => 'parent-click'];
        $q = $query(decline_offerwall_cta_url($offer['cta_link'], $lead, $cfg));
        $check(($q['sub7'] ?? '') === 'parent-click' && ($q['sub8'] ?? '') === '1585' && ($q['sub9'] ?? '') === $parent, $offer['id'] . ': wrong attribution');
        // The visit has no inbound subs, so nothing may land in sub1/sub2
        // (click id and event ride sub7/sub8).
        $check(!isset($q['sub1']) && !isset($q['sub2']), $offer['id'] . ': click/event must not use sub1/sub2');
        foreach (['oid', 'affid', 'ef_transaction_id'] as $missing) {
            $incomplete = $lead;
            unset($incomplete[$missing]);
            $check(decline_offerwall_cta_url($offer['cta_link'], $incomplete, $cfg) === $offer['cta_link'], 'Missing ' . $missing . ' must leave link unchanged');
            $incomplete[$missing] = '   ';
            $check(decline_offerwall_cta_url($offer['cta_link'], $incomplete, $cfg) === $offer['cta_link'], 'Blank ' . $missing . ' must leave link unchanged');
        }
    }
}

// Any ?oid= on the visit still attributes to 1072 — the site has one offer.
foreach (['914', '915', '1067', '999'] as $otherOid) {
    $lead = ['oid' => $otherOid, 'affid' => '300', 'ef_transaction_id' => 'x'];
    $check(str_contains(decline_offerwall_cta_url('https://example.com/o', $lead, $cfg), 'sub7=x&sub8=1585&sub9=' . $parent), "oid $otherOid must still attribute to 1072");
    $check(everflow_offer_for_affid('300', $cfg, $otherOid) === '1072', "oid $otherOid must resolve to 1072");
}
$check(everflow_offer_for_affid('', $cfg, '1072') === null, 'No affid still means no Everflow');
$check(everflow_offer_for_affid('300', []) === null, 'No offer configured means no Everflow');

$lead = ['oid' => '1072', 'affid' => '989', 'transaction_id' => 'click +/id'];
$url = 'https://example.com/offer?keep=a%20b&sub7=old&sub8=old&sub9=old&sub7=duplicate&sub1=untouched#details';
$expected = 'https://example.com/offer?keep=a%20b&sub1=untouched&sub7=click%20%2B%2Fid&sub8=1585&sub9=' . $parent . '#details';
$check(decline_offerwall_cta_url($url, $lead, $cfg) === $expected, 'Replace subs, encode click ID, preserve query/fragment');
$check(decline_offerwall_cta_url($expected, $lead, $cfg) === $expected, 'Repeated application must not duplicate subs');

$unnamed = $cfg;
unset($unnamed['offer_name'], $unnamed['decline_event_id']);
$check(decline_offerwall_cta_url('https://example.com/o?sub8=keep&sub9=keep', $lead, $unnamed) === 'https://example.com/o?sub8=keep&sub9=keep&sub7=click%20%2B%2Fid', 'Unset name/event must not set or strip sub8/sub9');

$lead['ef_transaction_id'] = 'preferred';
$check(str_contains(decline_offerwall_cta_url($url, $lead, $cfg), 'sub7=preferred&sub8=1585&sub9=' . $parent), 'Prefer resolved Everflow transaction ID');
$lead['affid'] = ['989'];
$check(decline_offerwall_cta_url($url, $lead, $cfg) === $url, 'Reject array input');

// ---- Partner's own inbound id, in the sub their global postback reads ----
// Never our ef_transaction_id (ef1) in sub1-sub5.
$base = ['oid' => '1072', 'ef_transaction_id' => 'ef1', 'sub1' => 'p1', 'sub2' => 'p2', 'sub5' => 'p5', 'sub6' => 'p6'];
// 819 reads {sub1}: sub1 carries the partner's value, sub7 ours.
$check(decline_offerwall_cta_url('https://example.com/o', ['affid' => '819'] + $base, $cfg)
    === 'https://example.com/o?sub1=p1&sub7=ef1&sub8=1585&sub9=' . $parent, '819 -> partner value in sub1');
// 464 reads {sub5}; an existing sub5 on the link is replaced, not duplicated.
$check(decline_offerwall_cta_url('https://example.com/o?sub5=stale', ['affid' => '464'] + $base, $cfg)
    === 'https://example.com/o?sub5=p5&sub7=ef1&sub8=1585&sub9=' . $parent, '464 -> partner value in sub5, stale replaced');
// 1024 reads {sub2}.
$check(($query(decline_offerwall_cta_url('https://example.com/o', ['affid' => '1024'] + $base, $cfg))['sub2'] ?? '') === 'p2', '1024 -> partner value in sub2');
// Unmapped partner: no partner sub, sub7 only.
$q = $query(decline_offerwall_cta_url('https://example.com/o', ['affid' => '777'] + $base, $cfg));
$check(!isset($q['sub1']) && !isset($q['sub2']) && !isset($q['sub5']) && ($q['sub7'] ?? '') === 'ef1', 'Unmapped affid gets no partner sub');
// Mapping outside sub1-sub5 is ignored.
$check(!isset($query(decline_offerwall_cta_url('https://example.com/o', ['affid' => '666'] + $base, $cfg))['sub6']), 'Only sub1-sub5 may be a partner sub');
// Mapped partner with no inbound value: nothing in that sub (not our click id).
$check(decline_offerwall_cta_url('https://example.com/o', ['affid' => '819', 'oid' => '1072', 'ef_transaction_id' => 'e'], $cfg)
    === 'https://example.com/o?sub7=e&sub8=1585&sub9=' . $parent, 'Missing partner value adds nothing');
// Partner value still passes when our own click is missing.
$check(decline_offerwall_cta_url('https://example.com/o', ['affid' => '819', 'sub1' => 'p1'], $cfg) === 'https://example.com/o?sub1=p1', 'Partner sub independent of parent click');

// Live config: offer 1072 only, decline event 1585, for any oid.
$liveEf = (require __DIR__ . '/../config.php')['everflow'];
$check(everflow_offer_for_affid('300', $liveEf, '1067') === '1072', 'Live config: any oid resolves to 1072');
$check(everflow_offer_for_affid('300', $liveEf) === '1072', 'Live config: no oid resolves to 1072');
$check(str_contains(decline_offerwall_cta_url('https://example.com/o', ['oid' => '915', 'affid' => '300', 'ef_transaction_id' => 'x'], $liveEf), 'sub8=1585&sub9=' . $parent), 'Live config: CTA gets sub8=1585 & sub9=' . $parent);

// Live config: every mapping must name sub1-sub5.
$live = $liveEf['partner_click_subs'] ?? [];
$check($live !== [], 'config partner_click_subs is empty');
foreach ($live as $affid => $sub) {
    $check(ctype_digit((string) $affid) && preg_match('/^sub[1-5]$/', $sub) === 1, "config partner_click_subs[$affid] invalid");
}
echo "Offerwall attribution checks passed for all general and student offers.\n";
