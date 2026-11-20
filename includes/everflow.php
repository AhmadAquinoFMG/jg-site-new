<?php

/**
 * Everflow offer routing.
 *
 * One place that answers "which Everflow offer does this visitor belong to?",
 * so the click (index.php -> assets/js/tracking/everflow.js) and the conversion
 * (thank-you.php) can never disagree and fire against different offers.
 *
 * The answer comes from affid alone — see config.php ['everflow'] for why, and
 * for the affid -> offer table itself.
 */

/**
 * Resolve the Everflow offer id for an affid.
 *
 * @param  string|null $affid  Raw ?affid= value (or the one stashed at submit).
 * @param  array       $cfg    config.php ['everflow'] block.
 * @return string|null         Offer id, or null when the visitor must not be
 *                             sent to Everflow at all (no/blank affid).
 */
function everflow_offer_for_affid(?string $affid, array $cfg, ?string $oid = null): ?string
{
    $affid = trim((string) $affid);
    if ($affid === '') {
        return null;    // unattributed traffic — Everflow stays untouched
    }

    // An allowlisted ?oid= (config click_offer_overrides, e.g. 1067 email) wins
    // over the affid split — must match assets/js/tracking/everflow.js, which
    // reads the same list as clickOfferOverrides.
    $oid = trim((string) $oid);
    if ($oid !== '' && in_array($oid, array_map('strval', $cfg['click_offer_overrides'] ?? []), true)) {
        return $oid;
    }

    $firstParty = $cfg['first_party_affids'] ?? [];

    return in_array($affid, $firstParty, true)
        ? (string) ($cfg['offer_first_party'] ?? '')
        : (string) ($cfg['offer_third_party'] ?? '');
}

/**
 * Generate an Everflow tracking link for an offerwall offer, credited to the
 * visitor's affiliate.
 *
 * POSTs to the Network API's tracking-link endpoint with
 * network_offer_id / network_affiliate_id and is_redirect_link=true, and returns
 * the "url" it hands back. Cached on disk per offer+affid — the link for a pair
 * never changes — so the wall costs one API call per pair per TTL, not one per
 * view. Failures are cached for a shorter TTL so an outage doesn't stall every
 * page on the timeout.
 *
 * @param  string|int  $offerId Everflow network_offer_id.
 * @param  string|null $affid   Raw ?affid= value (network_affiliate_id).
 * @param  array       $cfg     config.php ['everflow'] block.
 * @return string|null          Tracking URL, or null when the caller should
 *                              fall back to its static link (no key, no/bad
 *                              affid, API failure).
 */
function everflow_tracking_link(string|int $offerId, ?string $affid, array $cfg): ?string
{
    $offerId = trim((string) $offerId);
    $affid   = trim((string) $affid);
    $apiKey  = (string) ($cfg['api_key'] ?? '');

    // Both ids are integers on Everflow's side. Requiring digits also keeps the
    // cache filename below safe from anything a visitor puts in ?affid=.
    if ($apiKey === '' || !ctype_digit($offerId) || !ctype_digit($affid)) {
        return null;
    }

    $cacheDir  = rtrim((string) ($cfg['link_cache_dir'] ?? ''), '/\\');
    $cacheFile = $cacheDir !== '' ? $cacheDir . "/link-{$offerId}-{$affid}.json" : null;

    if ($cacheFile !== null && is_file($cacheFile)) {
        $cached = json_decode((string) @file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            $ttl = isset($cached['url'])
                ? (int) ($cfg['link_cache_ttl'] ?? 86400)
                : (int) ($cfg['link_failure_ttl'] ?? 300);
            if (time() - (int) ($cached['ts'] ?? 0) < $ttl) {
                return $cached['url'] ?? null;
            }
        }
    }

    $url = everflow_request_tracking_link($offerId, $affid, $apiKey, $cfg);

    if ($cacheFile !== null) {
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }
        @file_put_contents(
            $cacheFile,
            json_encode(['ts' => time()] + ($url !== null ? ['url' => $url] : []), JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    return $url;
}

/** One uncached call to the tracking-link endpoint. Never throws. */
function everflow_request_tracking_link(string $offerId, string $affid, string $apiKey, array $cfg): ?string
{
    if (!function_exists('curl_init')) {
        if (function_exists('app_log')) {
            app_log('warning', 'everflow', 'tracking_link_failed', ['offer' => $offerId, 'error' => 'curl_unavailable']);
        }
        return null;
    }

    $ch = curl_init((string) ($cfg['api_endpoint'] ?? 'https://api.eflow.team/v1/networks/tracking/offers/clicks'));
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'network_offer_id'     => (int) $offerId,
            'network_affiliate_id' => (int) $affid,
            'is_redirect_link'     => true,
        ]),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Eflow-API-Key: ' . $apiKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => max(1, (int) ($cfg['api_timeout'] ?? 3)),
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    // Windows PHP ships without a CA bundle; trust the OS store instead (also
    // covers antivirus HTTPS inspection on dev machines). Linux uses its own.
    if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
        curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    }
    $resp   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno  = curl_errno($ch);

    $decoded = is_string($resp) ? json_decode($resp, true) : null;
    $url     = is_array($decoded) ? trim((string) ($decoded['url'] ?? '')) : '';

    if ($status !== 200 || !preg_match('#^https://#i', $url)) {
        if (function_exists('app_log')) {
            app_log('warning', 'everflow', 'tracking_link_failed', [
                'offer'  => $offerId,
                'affid'  => $affid,
                'status' => $status,
                'curl'   => $errno,
            ]);
        }
        return null;
    }

    return $url;
}
