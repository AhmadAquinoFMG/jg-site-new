# Lander fixes: attribution and event_id

Findings from loading both landers live (Oct 2026) with a full parameter set,
reading their shipped JS, and reading the thank-you pages.

## What is actually broken

### 1. The chat lander does NOT drop attribution. The ad URLs never carry it.

`chat.relief.unitedsettlement.org` runs an inline `us_attribution_v2` script
that reads `affid, oid, source_id, fb_adid, fbclid, utm_*, subN, ms_placement,
ms_publisher` from the URL, persists them for 30 days (sessionStorage + cookie),
and `chat-app.min.js` posts every one of them to `/api/submit.php`, which maps
`subN` to `lp_subidN`. Loaded with a full parameter set, `getTrackingPayload()`
returned every field correctly, and `_fbc` / `_fbp` were minted.

So the leads with nothing on them (affid 989) and the leads with one identical
hardcoded set of sub ids (affid 1024, "Lawn moving ad") mean exactly one thing:
**the Meta ads pointing at the chat lander have no URL parameter template**
(989) or **static values typed in instead of macros** (1024).

`jgdebtrelief.com` works because its ads carry the template. Copy that template
to every ad that points at the chat lander. See `meta-url-parameters.md`.

### 2. The Meta `Lead` is sent by a Lead Prosper campaign trigger, not the landers

The landers fire no browser `Lead` at all (JG's thank-you page loads no Meta
pixel; the chat thank-you page fires `PageView` only; GTM `GTM-PLGFZ27N` has
no Meta tag). The Lead reaches Meta as a **server-side Conversions API event
from a Lead Prosper real-time trigger** (an `http` action posting to
`graph.facebook.com/<version>/<pixel_id>/events`).

That is a sound design: one source of truth, fired only for leads Lead Prosper
accepted, no browser/server dedupe to get wrong. So the missing `event_id` on
JG is **not** causing double counting today. It matters for two other reasons:

| | JG (`jgdebtrelief.com`) | Chat (`chat.relief…`) |
|---|---|---|
| `event_id` on the lead | **No.** Form has no field; `submit.php` posts none. | **Yes.** UUID minted in `/api/submit.php`, posted to Lead Prosper. |
| `fbc` on the lead | **No.** The form fills a `fbc` input from the `_fbc` cookie, but the Lead Prosper campaign has no `fbc` field, so it is dropped. Only `fbp` and `fbclid` arrive. | Yes (campaign has the field; filled when the cookie exists). |
| What the trigger can send as CAPI `event_id` | Only something it mints itself, which nobody stores. | The lead's UUID, **if the action body maps it**. |

1. **Traceability.** For `find_leads(field="event_id", …)` and
   `trace_lead` to tie a Lead Prosper lead to the exact event Meta received,
   the trigger must send the lead's `event_id` field as the CAPI `event_id`.
   JG has no such field, so its events are untraceable by id.
2. **Match quality.** Meta matches a CAPI Lead to the click mainly through
   `fbc` (click id), then `fbp`, then hashed PII. JG leads reach the trigger
   with no `fbc`, so the trigger either reconstructs it from `fbclid` or the
   event matches only on PII. Add the `fbc` field to campaign 35954 and the
   lander's existing hidden input lands in it with no code change.

**Check the trigger body in the Lead Prosper UI** (campaign → Triggers →
the Meta action → request body). It should contain, at least:
`event_id` ← lead `event_id`; `user_data.fbc` ← `fbc`; `user_data.fbp` ←
`fbp`; `user_data.client_ip_address` ← `ip_address`; `user_data.client_user_agent`
← `user_agent`; `event_source_url` ← `landing_page_url`; hashed `em`/`ph`.
The API never shows the body, only the URL. The MCP tool `meta_capi_triggers()`
lists every campaign's Meta action and the pixel id in its URL, so you can at
least confirm which campaigns send, to which pixel, and that none are paused.

**Do not add the browser `Lead` snippets** in `jg/thank-you-lead.php.snippet`
and `chat/thank-you-lead.js` unless you decide to run browser + server
together. If you do, the browser `eventID` must be the same UUID the trigger
sends, or every lead counts twice. They are kept here for that case only.

Pixel note: the chat landing page inits `1809915816237904` and
`9860760613990195`; its thank-you page inits `9860760613990195` and
`1383071320512202`. Compare with the pixel id in the trigger URL from
`meta_capi_triggers()`; the trigger's pixel is the one that counts.

### 3. Literal macros reach Lead Prosper

Leads from affid 1024 carry the strings `{{ad.id}}`, `{{campaign.name}}`,
`{{site_source_name}}` and `{{fbclid}}`. Causes:

- `{{fbclid}}` is not a Meta macro. Meta appends `fbclid` by itself. Remove it.
- Meta substitutes `{{…}}` only in the ad's **URL parameters** field (and the
  website URL when typed raw). A URL that was pasted already URL-encoded
  (`%7B%7Bad.id%7D%7D`, which is what one affid 1024 landing URL shows) is not
  substituted.
- Clicks from Ads Manager previews deliver literal macros. Those are test leads.

Check an affid 1024 ad with the MCP tool `meta_ad(ad_id)`: the creative's
`url_tags` shows the template exactly as Meta has it.

Both landers should also refuse to store a value containing `{{`. See
`shared/macro-guard.md`.

## The fixes, in order

1. **Ads (no code):** put the template from `meta-url-parameters.md` in the URL
   parameters field of every ad for affids 989, 995 and 1024, both landers.
   Fixes problem 1 and problem 3 at the source. Verify within a day with the MCP
   tool `attribution_health(days=1, affids="989,995,1024")`.

2. **Lead Prosper (no lander code):**
   - campaign 35954 (JG): add text fields `event_id` and `fbc`;
   - every campaign's Meta trigger action: map CAPI `event_id` ← lead
     `event_id`, and make sure `fbc`, `fbp`, `ip_address`, `user_agent`,
     `landing_page_url` are in `user_data` / `event_source_url`.

3. **JG lander:**
   - add `jg/event-id.js` to the form page (creates the `event_id` hidden input);
   - in `submit.php`, forward `event_id` and `fbc` in the Direct Post payload
     (steps 1 and 2 of `jg/submit-php-patch.md`; step 3, the lander-side
     Conversions API, is only for the case where you move CAPI off the trigger).

4. **Chat lander:** nothing required. Optionally keep the full inbound query
   string in `landing_page_url` (see `chat/submit-php-patch.md`, section 3).

5. **Both, defensive:** the `{{macro}}` guard in `shared/macro-guard.md`.

`shared/MetaCapi.php` and the two thank-you snippets are kept for the
alternative design (lander-side CAPI, or browser + server with dedupe). They
are not needed while the Lead Prosper trigger is the sender.

## Verifying

1. `meta_capi_triggers()` from Claude: every live campaign has an unpaused
   Meta action, and the pixel id is the one you expect.
2. Submit a test lead through JG. `trace_lead(lead_id)` shows a UUID in
   `event_id` and a real `fbc`.
3. Meta Events Manager → pixel → Test Events (put the test code in the trigger
   body temporarily): the `Lead` arrives with that same `event_id` and shows
   fbc/fbp/IP/UA as matched parameters. Then remove the test code.
4. `find_leads(field="event_id", value="<that uuid>")` returns the lead.
