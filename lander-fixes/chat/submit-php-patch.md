# chat.relief.unitedsettlement.org `/api/submit.php` patch

This handler already generates the UUID (`result.event_id`), posts it to Lead
Prosper and returns it to the browser. **While the Lead Prosper trigger sends
the Conversions API event, no change is required here.** Make sure the
trigger action maps CAPI `event_id` ← lead `event_id` and `user_data.fbc` ←
`fbc`; that is a Lead Prosper UI setting.

Section 1 is only for moving CAPI off the trigger onto the lander.

## 1. (Alternative design only) Conversions API from the lander with the id it already has

After the Lead Prosper post is accepted and the lead is marked
`verified_qualifies` (so Meta only hears about qualified leads, matching the
thank-you page gate):

```php
require_once __DIR__ . '/MetaCapi.php';

MetaCapi::sendLead([
    'event_id'    => $eventId,                       // the UUID you already generate
    'event_time'  => time(),
    'source_url'  => $in['landing_page_url'] ?? 'https://chat.relief.unitedsettlement.org/',
    'fbc'         => $in['fbc'] ?? '',
    'fbp'         => $in['fbp'] ?? '',
    'fbclid'      => $in['fbclid'] ?? '',
    'ip'          => $in['ip_address'] ?? ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? '')),
    'ua'          => $in['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''),
    'external_id' => $_COOKIE['jgw_uid'] ?? '',
    'email'       => $in['email'] ?? '',
    'phone'       => $in['phone'] ?? '',
    'first_name'  => $in['first_name'] ?? '',
    'last_name'   => $in['last_name'] ?? '',
    'city'        => $in['city'] ?? '',
    'state'       => $in['state'] ?? '',
    'zip'         => $in['zip_code'] ?? '',
    'dob'         => $in['date_of_birth'] ?? '',
    'custom'      => [
        'affid'      => $in['affid'] ?? '',
        'fb_adid'    => $in['fb_adid'] ?? '',
        'lp_lead_id' => $lpLeadId ?? '',
        'content_name' => 'Debt Relief',
    ],
]);
```

`$in` is the decoded JSON body `chat-app.min.js` posts; the keys above are the
ones it sends today.

## 2. Pixel ids

Landing page inits `1809915816237904` and `9860760613990195`. Thank-you inits
`9860760613990195` and `1383071320512202`, with `1809915816237904` commented
out. Pick the set that should receive Leads, make both pages init exactly that
set, and put the same set in:

```
META_PIXEL_IDS=9860760613990195,1383071320512202
META_CAPI_ACCESS_TOKEN=<Events Manager → pixel → Settings → Conversions API>
```

A server event sent to a pixel that never fires the browser Lead cannot be
deduped and will simply count as an extra Lead on that pixel.

## 3. Optional: keep the full inbound URL

`us_attribution_v2` stores `landing_page_url` as origin + path, so the query
string that proves which ad sent the visitor is lost (it survives only in the
`inbound_snapshot` the browser posts, which Lead Prosper never sees). Change

```js
u.landing_page_url = i.landing || location.origin + location.pathname
```
to
```js
u.landing_page_url = i.landing || (location.origin + location.pathname + location.search).slice(0, 2000)
```

JG already posts the full URL, which is how the literal-macro problem was
visible in the first place.
