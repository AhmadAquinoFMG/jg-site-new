# Refuse unresolved `{{macros}}` on both landers

Both attribution scripts have a single sanitizer every URL value passes
through. Add one test to each so a literal `{{ad.id}}` is treated as empty
instead of being stored for 30 days and posted to Lead Prosper and Everflow.

## jgdebtrelief.com — source of `assets/dist/attribution.min.js`

Current (minified):
```js
function u(t){if(t==null)return"";var e=String(t).trim();return e.length>l?e.slice(0,l):e}
```
Change to:
```js
function u(t){if(t==null)return"";var e=String(t).trim();if(/\{\{[^}]*\}\}/.test(e))return"";return e.length>l?e.slice(0,l):e}
```

`funnel.min.js` also copies URL params straight into the hidden inputs in its
own IIFE (`["affid","oid","source_id","sub1",…].forEach(function(a){var o=t.get(a)…c.value=o`).
Apply the same test there: skip the assignment when `/\{\{[^}]*\}\}/.test(o)`.

## chat.relief.unitedsettlement.org — inline `us_attribution_v2`

Current:
```js
function b(t){return"string"==typeof t?t.trim().slice(0,200):""}
```
Change to:
```js
function b(t){if("string"!=typeof t)return"";t=t.trim();return/\{\{[^}]*\}\}/.test(t)?"":t.slice(0,200)}
```

`chat-app.min.js` reads values through `$()` which prefers `__trackingParams`,
so once `b()` rejects macros nothing downstream sees them.

## Why this is a guard, not the fix

The fix is the ad template in `meta-url-parameters.md`. This guard only keeps a
future misconfigured ad from poisoning stored attribution for a month. The
tracer in `lp_attribution/normalize.py` flags the same strings as
`unresolved_macro`, so if they keep arriving after the guard ships, the guard
was not deployed.
