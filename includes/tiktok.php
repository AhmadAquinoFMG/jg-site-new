<?php
/**
 * TikTok Pixel base code. Ported from the DebtWave funnel
 * (debt-wave-official/includes/header.php), wired the way includes/analytics.php
 * already is rather than pasted into a page.
 *
 * THE ONLY PLACE the pixel ID belongs. Every page renders the tag through this
 * file — never hardcode a second snippet on a page. Two tags means two
 * ttq.page() calls per visit, which doubles the view count the ad spend is
 * judged against. That is the same rule the 'umami' comment in config.php
 * states at length, and it applies here for the same reason.
 *
 * ⚠ THIS PIXEL IS SHARED WITH THE DEBTWAVE FUNNEL. It is deliberately the same
 * ID, so TikTok sees one pixel spanning both sites and reports one combined
 * number. Traffic and conversions CANNOT be split per funnel afterwards — the
 * pixel does not carry which site a pageview came from. Give this funnel its
 * own pixel in Events Manager and set TIKTOK_PIXEL_ID in .env if per-funnel
 * reporting is ever wanted; nothing here has to change but the ID.
 *
 * Suppressed on local hosts, so a dev run never fires a pageview at a live ad
 * account. config.php has no is-local helper of its own — the Umami tag above
 * fires from localhost today — so the check is inline here rather than shared.
 *
 * NOT deferred, unlike the Umami tag. The snippet defines window.ttq
 * synchronously and queues every call made before the remote script lands, so
 * anything later in the page can call ttq.track() straight away. Deferring it
 * would leave ttq undefined for exactly the inline code most likely to want it.
 *
 * CONVERSION EVENTS come from $tiktokEvents, which the including page sets
 * before the include: a list of ['EventName', ['param' => 'value'], 'event_id']
 * tuples, the last two optional. ttq.page() is the PAGEVIEW and is deliberately
 * not one of them - TikTok counts Pageview and ViewContent as separate events,
 * and a pixel reporting neither ViewContent nor a completion is what raises
 * "Missing events" in Events Manager. That alert is why this exists.
 *
 * THE NAMES MUST MATCH WHAT IS CONFIGURED IN EVENTS MANAGER. A name the pixel
 * is not expecting is dropped on arrival: nothing errors, the request still
 * leaves the browser, and the campaign still reports zero while the code looks
 * right. Confirm every name against the pixel's event list before spend runs.
 *
 * ---------------------------------------------------------------------------
 * EVENT MATCH QUALITY (EMQ)
 * ---------------------------------------------------------------------------
 * EMQ is the score Events Manager puts next to each event, and it measures one
 * thing only: how many identifiers the event carried that TikTok can resolve to
 * a person. It is not a measure of how many events arrived. An event with no
 * identifiers still counts as a conversion, but it is a conversion TikTok
 * cannot attribute to the account that saw the ad — so it neither credits the
 * campaign nor feeds the optimisation model, which is the whole reason the
 * events are fired.
 *
 * Bare `ttq.load()` + `ttq.page()` sends the pixel's own signals and nothing
 * else: the _ttp first-party cookie, the ttclid off the ad click URL, the IP
 * and the user agent. That is the floor, and it is where this pixel sat. What
 * lifts it is ttq.identify() — the hashed identifiers below, sent BEFORE
 * page()/track() so every event in the request carries them:
 *
 *   external_id   Every page. A first-party id we mint ourselves (see the
 *                 cookie below), so even the landing pageview — where no one
 *                 has typed anything yet — carries one identifier instead of
 *                 none, and the ViewContent that opens a session ties to the
 *                 CompleteRegistration that closes it.
 *   email         The conversion page. Passed by the including page.
 *   phone_number  Likewise.
 *
 * ALL THREE ARE HASHED HERE, server-side, and the plaintext never reaches the
 * rendered page. The pixel will SHA-256 a plaintext value itself, but doing it
 * here means (a) no consumer email sits in the HTML source of a page that is
 * one right-click away from being read, and (b) the normalisation is ours and
 * is the same normalisation a server-side event would use, so the two agree.
 * The pixel recognises a 64-character hex string as already hashed and passes
 * it through untouched — that detection is why the helpers below return hex.
 *
 * NORMALISATION IS THE WHOLE GAME. A hash of "Bob@Example.com " and a hash of
 * "bob@example.com" are two unrelated 64-character strings, and TikTok only
 * ever sees the hash, so a normalisation slip does not fail loudly — it reports
 * a perfect-looking event that matches nobody and quietly drags EMQ down.
 * Lowercase + trim for email, E.164 with the leading + for phone.
 *
 * WHAT IS NOT HERE: the rest of the identity set (first/last name, city, state,
 * zip, country) and the server-side half of the event. Those belong to the
 * TikTok Events API, which posts the same event from our server with the
 * visitor's IP and user agent attached and survives the ad blockers and ITP
 * restrictions that stop a browser pixel firing at all. $tiktokEvents carries
 * an event_id per event precisely so that can be added without touching this
 * file: TikTok dedupes a browser event against a server event of the same name
 * and event_id, and thank-you.php derives that id from the lead row so both
 * halves can compute it independently.
 *
 * $cfg is provided by the including page.
 */
$tiktokPixelId = $cfg['tiktok']['pixel_id'] ?? '';
if ($tiktokPixelId === '') {
    return; // pixel disabled
}

$tiktokHost = strtolower((string) ($_SERVER['SERVER_NAME'] ?? ''));
if ($tiktokHost === 'localhost'
    || $tiktokHost === '127.0.0.1'
    || $tiktokHost === '::1'
    || str_ends_with($tiktokHost, '.local')
    || str_ends_with($tiktokHost, '.test')
    || str_ends_with($tiktokHost, '.localhost')) {
    return; // local/dev run
}

// Only the characters a pixel ID is made of. It is interpolated into a string
// literal in every page's HTML.
if (preg_match('/^[A-Za-z0-9]{1,40}$/', $tiktokPixelId) !== 1) {
    return;
}

/* Conversion events set by the including page. Anything unnamed is skipped
   rather than emitted as ttq.track(""), which TikTok would reject. */
$tiktokEvents = isset($tiktokEvents) && is_array($tiktokEvents) ? $tiktokEvents : [];

/* Identifiers set by the including page, in PLAINTEXT — hashing is this file's
   job, so no caller can get the normalisation wrong in its own way. Only the
   page that actually holds a consumer's details sets this; every other page
   still gets the external_id below. */
$tiktokIdentify = isset($tiktokIdentify) && is_array($tiktokIdentify) ? $tiktokIdentify : [];

/* Set true by a page that wants the pixel LIBRARY fetched only once the visitor
   engages (includes/lazy-tags.php must be included first). The landing page
   does, for load performance. Pages that fire a conversion must not: an event
   still queued when the visitor leaves is never sent. */
$tiktokDefer = !empty($tiktokDefer);

/* Declared behind function_exists because this file is include'd, not
   require_once'd, and it sits past several early returns — so a page that
   breaks the one-tag rule at the top of this file would otherwise take the
   whole response down with a redeclare fatal rather than just double-counting
   a pageview. */
if (!function_exists('tiktok_match_email')) {
    /**
     * SHA-256 an email the way TikTok expects it: trimmed and lowercased.
     *
     * Returns '' for anything that is not an email, so a blank or hand-edited
     * ?email= param on the thank-you page drops the key rather than sending the
     * hash of a typo — a wrong identifier is worse than a missing one, because
     * TikTok counts it as sent and then fails to match it.
     *
     * A value that is ALREADY a 64-char hex string is passed straight through,
     * so a caller holding only the hash (a stored match key, say) can hand it
     * over.
     */
    function tiktok_match_email(string $raw): string
    {
        $value = strtolower(trim($raw));
        if ($value === '') {
            return '';
        }
        if (preg_match('/^[a-f0-9]{64}$/', $value) === 1) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_EMAIL) ? hash('sha256', $value) : '';
    }

    /**
     * SHA-256 a phone number in E.164, INCLUDING the leading '+' and the
     * country code. TikTok's documented format, and the one thing about it that
     * is easy to get wrong: hashing the bare 10 digits produces a valid-looking
     * hash that matches nothing.
     *
     * Everything that is not a digit is stripped first, so the '+1XXXXXXXXXX'
     * that submit.php stores and the '(555) 123-4567' a looser caller might
     * pass both normalise to the same string. A bare 10-digit number is assumed
     * US and gets a '1' — this funnel is US-only (the schema markup says
     * addressCountry US and submit.php validates 10 digits), so that assumption
     * is the funnel's, not a guess made here.
     */
    function tiktok_match_phone(string $raw): string
    {
        $value = strtolower(trim($raw));
        if ($value === '') {
            return '';
        }
        if (preg_match('/^[a-f0-9]{64}$/', $value) === 1) {
            return $value;
        }

        $digits = preg_replace('/\D/', '', $raw);
        if (strlen($digits) === 10) {
            $digits = '1' . $digits;   // US, per the funnel's own validation
        }
        // E.164 allows 8–15 digits. Outside that it is not a phone number, and
        // the hash of it would be an identifier TikTok can only fail to match.
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return '';
        }

        return hash('sha256', '+' . $digits);
    }
}

/* ---- external_id ---------------------------------------------------------
   A first-party visitor id, minted here and kept in a cookie for a year.

   This is the identifier that makes the landing pageview worth matching at
   all. index.php fires ViewContent before the visitor has typed a character,
   so email and phone do not exist yet; without this, that event carries no
   identity beyond the pixel's own cookie and scores accordingly. With it, the
   ViewContent, every mid-funnel event and the CompleteRegistration on the
   thank-you page all report the SAME external_id, which is what lets TikTok
   resolve them to one person rather than three anonymous hits.

   Written from JavaScript rather than setcookie(). Every page includes this
   file from inside <head>, so the response body has already started and PHP
   can no longer send a Set-Cookie header — headers_sent() is true by the time
   we get here. document.cookie has no such problem, and a browser with no
   JavaScript would not be running the pixel either way.

   $_COOKIE is updated in the same breath so that anything later in THIS
   request sees the id we just minted rather than minting a second one.

   Hex from random_bytes: unguessable, fixed length, and nothing derived from
   the consumer — it identifies a browser, not a person, which is all TikTok
   needs it to do. It is hashed before it is sent, like every other match key,
   because TikTok's own examples hash external_id and a server-side event would
   have to hash it identically to dedupe against this one. */
$tiktokUidCookie = 'jgw_uid';
$tiktokUid       = (string) ($_COOKIE[$tiktokUidCookie] ?? '');
$tiktokUidIsNew  = preg_match('/^[a-f0-9]{32}$/', $tiktokUid) !== 1;
if ($tiktokUidIsNew) {
    $tiktokUid = bin2hex(random_bytes(16));
    $_COOKIE[$tiktokUidCookie] = $tiktokUid;
}

// Secure flag. The local-host guard above already means this only ever runs on
// a real host, but a proxy that terminates TLS leaves $_SERVER['HTTPS'] unset,
// so the forwarded header is checked too — the same case config.php's
// PORTAL_COOKIE_SECURE note describes for the session cookie.
$tiktokHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

/* The identify() payload. Keys are TikTok's, not ours. Anything that
   normalised to '' is left out entirely: identify() ignores a key it is not
   given, whereas an empty string is an identifier that cannot match. */
$tiktokMatch = ['external_id' => hash('sha256', $tiktokUid)];

$tiktokEmail = tiktok_match_email((string) ($tiktokIdentify['email'] ?? ''));
if ($tiktokEmail !== '') {
    $tiktokMatch['email'] = $tiktokEmail;
}

$tiktokPhone = tiktok_match_phone((string) ($tiktokIdentify['phone'] ?? ''));
if ($tiktokPhone !== '') {
    $tiktokMatch['phone_number'] = $tiktokPhone;
}

/* One encoder for every value interpolated below. The HEX_* flags are the ones
   that keep a string literal from closing the <script> element early.

   INVALID_UTF8_SUBSTITUTE is the difference between a mangled character and a
   blank page: json_encode returns false on a malformed UTF-8 byte, false is
   coerced to '' by the string return type, and the emitted line becomes
   `ttq.track(, {});` — a syntax error that kills every script in the document,
   including the ones that run the funnel. Event params can carry consumer text,
   so that byte is reachable. */
$tiktokJson = static fn($value): string => (string) json_encode(
    $value,
    JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS
        | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
);
?>
<!-- TikTok Pixel Code Start -->
<script>
!function (w, d, t) {
  w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie","holdConsent","revokeConsent","grantConsent"],ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(
var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e},ttq.load=function(e,n){var r="https://analytics.tiktok.com/i18n/pixel/events.js",o=n&&n.partner;ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=r,ttq._t=ttq._t||{},ttq._t[e]=+new Date,ttq._o=ttq._o||{},ttq._o[e]=n||{};n=document.createElement("script")
;n.type="text/javascript",n.async=!0,n.src=r+"?sdkid="+e+"&lib="+t;e=document.getElementsByTagName("script")[0];
<?php if ($tiktokDefer): ?>
  /* Deferred page ($tiktokDefer): only the library download waits for
     jgWhenEngaged (includes/lazy-tags.php). The stub above is already live,
     so identify/page/track below are queued and replayed when it lands.
     Appended to <head> at run time, not beside the saved first <script> `e`:
     by then that element may have been removed (TrustedForm removes its own
     loader), and insertBefore on a detached node throws. */
  var ins=function(){(d.head||d.documentElement).appendChild(n)};w.jgWhenEngaged?w.jgWhenEngaged(ins):ins()};
<?php else: ?>
  e.parentNode.insertBefore(n,e)};
<?php endif; ?>

<?php if ($tiktokUidIsNew): ?>
  /* Persist the external_id this pageview reported, so the next one reports the
     same. Wrapped because document.cookie throws in a few embedded webviews
     with storage partitioned off; losing it costs a match key, not a pageview. */
  try { d.cookie = <?= $tiktokJson($tiktokUidCookie . '=' . $tiktokUid . '; max-age=31536000; path=/; SameSite=Lax' . ($tiktokHttps ? '; Secure' : '')) ?>; } catch (e) {}

<?php endif; ?>
  ttq.load(<?= $tiktokJson($tiktokPixelId) ?>);
  /* Advanced matching. BEFORE page() and track() on purpose — identify() sets
     the identifiers that every event fired afterwards carries, so an event sent
     ahead of it is an event sent without them. */
  ttq.identify(<?= $tiktokJson($tiktokMatch) ?>);
  ttq.page();
<?php foreach ($tiktokEvents as $tiktokEvent): ?>
<?php   $tiktokEventName = (string) ($tiktokEvent[0] ?? ''); ?>
<?php   if ($tiktokEventName === '') { continue; } ?>
<?php   /* event_id: TikTok's dedupe key between this browser event and the same
           event sent server-side. Random when the caller names none, which is
           correct for an event with no server-side twin — it is still unique
           per fire, so a double-rendered tag cannot be counted twice. */ ?>
<?php   $tiktokEventId = trim((string) ($tiktokEvent[2] ?? '')) ?: bin2hex(random_bytes(8)); ?>
  ttq.track(<?= $tiktokJson($tiktokEventName) ?>, <?= $tiktokJson(!empty($tiktokEvent[1]) ? $tiktokEvent[1] : (object) []) ?>, <?= $tiktokJson(['event_id' => $tiktokEventId]) ?>);
<?php endforeach; ?>
}(window, document, 'ttq');
</script>
<!-- TikTok Pixel Code End -->
