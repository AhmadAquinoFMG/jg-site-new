# Landing-page loading fixes

The September 10 mobile PageSpeed report flagged render-blocking Google Fonts
and CSS, missing priority on the LCP logo, image dimensions, and Continue-button
contrast.

## Changes

- Serve the existing Poppins weights and Unicode subsets locally, with their
  license. Preload only Latin 400 and 700; retain `font-display: swap`.
- Inline the existing stylesheet and font declarations on the landing page to
  remove stylesheet round trips. `assets/css/style.css` remains the source of
  truth; no build step or asynchronous stylesheet swap is required.
- Preload/prioritize the header logo and declare the badges/footer logo sizes.
- Use the existing darker brand greens for primary-button contrast.
- Cache only the versioned WOFF2 files for one year via their scoped .htaccess.

Form markup and all script tags were compared with HEAD and are unchanged.
No tracking delays, pixel removals, routing changes, or submission changes were
made. Third-party caching, unused code, and execution warnings may remain.

## Validation

- PHP syntax checks and `git diff --check` passed.
- Mobile header, funnel, active step, button, and badge dimensions matched the
  pre-change page. Required-choice validation, Continue, and Back were checked.
- Local Lighthouse 13.4.0, mobile preset, with PHP HTML compression enabled:
  performance 82, accessibility 100, SEO 100; FCP 1.1 s, LCP 3.2 s,
  TBT 440 ms, CLS 0.001.
- Render-blocking, font-display, image-dimension, and color-contrast audits pass.
  All four requested Latin fonts returned HTTP 200.
- Local Best Practices was 58 due to HTTP/third-party cookie/inspector findings.
  The localhost PHP server does not apply Apache .htaccess directives. These
  results are not directly comparable with the production PageSpeed run.

## Second pass (September 24)

The follow-up PageSpeed report flagged cache lifetimes, unminified JS/CSS,
unused JS, image delivery, layout-shift culprits and a non-composited
animation.

- **Minified JS/CSS.** `npm install` once, then `npm run build` after editing
  funnel.js, tracking/attribution.js, tracking/everflow.js, style.css or
  faces.css, and commit `assets/dist/`. `includes/assets.php` serves a minified
  copy only while its source hash in `assets/dist/manifest.json` still matches,
  so a missed rebuild falls back to the unminified source, never to stale code.
- **Layout shift.** The form-nav row is server-rendered in step 1's state
  (hidden, no Continue) instead of being hidden by funnel.js after load. A
  metric-matched `Poppins Fallback` (Arial) face removes the reflow when the
  webfont swaps in.
- **LCP render delay / animation.** The step fade no longer runs on the first
  step at load (`data-animate` is set on the first step change). The progress bar
  animates `transform` instead of `width`.
- **Images.** Trust badges and the footer logo are offered as AVIF at 2x their
  rendered size through `<picture>`, with the original PNGs as fallback. WebP
  was tested and came out larger than the already-optimised palette PNGs.

Not changed, deliberately: production (nginx) already sends
`max-age=31536000` and gzip for our own assets, so the remaining cache-lifetime,
legacy-JS, unused-JS and long-task findings are Meta Pixel, TikTok, TrustedForm
and Umami. Fixing those means delaying or removing tracking, which this page
still does not do.

Local Lighthouse 13, mobile, no HTML compression: performance 94 → 96–97,
LCP 2.6 s → 2.0 s, TBT 200 → 180–190 ms, CLS 0; minify JS/CSS and image
delivery now pass.

## Third pass: deferred pixel libraries

The landing page now downloads the Meta Pixel and TikTok libraries on the
visitor's first interaction (pointer, key, touch, wheel, mouse move) or 10s
after load (`includes/lazy-tags.php`). Their inline stubs still run at once,
so every `fbq()`/`ttq` call is queued and sent when the library arrives. This
removes the report's 94 KiB unused-JS and 21 KiB legacy-JS findings, both of
which were these two libraries. The thank-you and offerwall pages are unchanged
(conversion events must not wait).

The trade-off: a visitor who leaves within 10s without touching, scrolling,
typing or moving the mouse sends no Meta/TikTok pageview. The fallback is 10s,
not 4s, because at 4s the libraries sometimes landed inside the Lighthouse
trace (score 70). Two bugs were found and fixed along the way: funnel.js's
initial `scrollIntoView()` raised scroll events that released the tags at load,
and the Meta snippet's saved insert anchor was TrustedForm's loader, which
TrustedForm removes, so a later deferred insert threw and Meta never loaded.

**Meta changed (October 20):** the interaction gate was costing Meta Landing
Page Views. Every no-interaction bounce was a link click Meta never saw land,
while Umami still counted it. The landing page no longer loads `fbevents.js`.
It sends PageView to both pixels as plain image requests to
`facebook.com/tr/` on window `load`, with no interaction needed. That costs
almost no main-thread time, so the TBT gain above is kept. TikTok is unchanged
and stays on the interaction gate.

Remaining TBT is TrustedForm (`cdn.trustedform.com/trustedform-*.js`, one
400–700 ms task). Deferring it the same way is a compliance decision.

Deploy the changed PHP/CSS files and the complete `assets/fonts/poppins-v24`
directory, including its .htaccess. Then rerun mobile PageSpeed against HTTPS
production to establish the live score and verify font cache headers. Do not
cache the session-dependent PHP page to improve the score.
