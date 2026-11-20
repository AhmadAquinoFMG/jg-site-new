# jgdebtrelief.com `submit.php` patch

The form now posts an `event_id` hidden field (from `event-id.js`). Three
additions, in this order inside the existing handler.

## 1. Accept or mint the event id (top of the handler)

```php
$eventId = isset($_POST['event_id']) ? trim((string) $_POST['event_id']) : '';
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $eventId)) {
    // Browser without JS/storage: mint one here so the lead still carries an id.
    $eventId = sprintf('%08x-%04x-4%03x-%04x-%012x',
        random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff),
        random_int(0x8000, 0xbfff), random_int(0, 0xffffffffffff));
}
```

## 2. Send it to Lead Prosper, together with `fbc`

Wherever the Direct Post payload to `https://api.leadprosper.io/direct_post`
is built, add both fields. The Lead Prosper campaign (35954, JG Debt Relief)
needs text fields named `event_id` and `fbc`; the United Settlement campaigns
already have them. The form already posts `fbc` (filled from the `_fbc`
cookie, or rebuilt from `fbclid`), it is just dropped today.

```php
$lpPayload['event_id'] = $eventId;
$lpPayload['fbc']      = $_POST['fbc'] ?? '';
```

Then, in Lead Prosper, map the Meta trigger action's `event_id` to the lead's
`event_id` field and `user_data.fbc` to `fbc`. **Steps 3 and 4 below are only
needed if you stop sending Conversions API events from the Lead Prosper
trigger and send them from the lander instead. Never do both with different
ids.**

Also make sure these already-collected values are in the payload (they are on
the form; confirm none are dropped): `fbp`, `fbc`, `fbclid`, `fb_adid`,
`source_id`, `utm_campaign`, `utm_term`, `utm_content`, `sub2`→`lp_subid2`,
`sub3`→`lp_subid3`, `sub4`→`lp_subid4`, `ms_placement`, `ms_publisher`,
`landing_page_url`.

## 3. After Lead Prosper accepts: Conversions API + hand the id to thank-you.php

```php
require_once __DIR__ . '/MetaCapi.php';

if ($lpAccepted) {   // whatever your success condition is today
    MetaCapi::sendLead([
        'event_id'    => $eventId,
        'event_time'  => time(),
        'source_url'  => $_POST['landing_page_url'] ?? ('https://jgdebtrelief.com' . ($_SERVER['REQUEST_URI'] ?? '/')),
        'fbc'         => $_POST['fbc'] ?? '',
        'fbp'         => $_POST['fbp'] ?? '',
        'fbclid'      => $_POST['fbclid'] ?? '',
        'ip'          => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''),
        'ua'          => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'external_id' => $_COOKIE['jgw_uid'] ?? '',
        'email'       => $_POST['email'] ?? '',
        'phone'       => $_POST['phone'] ?? '',
        'first_name'  => $_POST['first_name'] ?? '',
        'last_name'   => $_POST['last_name'] ?? '',
        'city'        => $_POST['city'] ?? '',
        'state'       => $_POST['state'] ?? '',
        'zip'         => $_POST['zip'] ?? '',
        'dob'         => $_POST['dob'] ?? '',
        'custom'      => [
            'affid'       => $_POST['affid'] ?? '',
            'fb_adid'     => $_POST['fb_adid'] ?? '',
            'lp_lead_id'  => $lpLeadId ?? '',
            'content_name'=> 'Debt Relief',
        ],
    ]);

    // thank-you.php reads this to fire the browser Lead with the same id.
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
    $_SESSION['jgw_lead_event_id'] = $eventId;
    $_SESSION['jgw_lead_event_at'] = time();
}
```

`PHPSESSID` is already set on the site, so the session is available.

## 4. Environment

```
META_PIXEL_IDS=1809915816237904,9860760613990195
META_CAPI_ACCESS_TOKEN=<Events Manager → pixel → Settings → Conversions API → Generate access token>
```

Only list pixels that also fire the browser `Lead` on `thank-you.php`, so every
server event has a browser twin to dedupe against.

## Why fire after Lead Prosper accepts

A duplicate or rejected lead should not be reported to Meta as a Lead, or the
optimizer learns from leads you did not keep. Later, send `MetaCapi::sendQualified`
from the Lead Prosper "sold" webhook with the same `event_id` prefix scheme to
optimize toward sold leads.
