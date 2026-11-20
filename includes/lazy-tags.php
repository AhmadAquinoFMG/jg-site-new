<?php
/**
 * window.jgWhenEngaged(fn) — runs fn on the visitor's first interaction
 * (pointer, key, touch, wheel, mouse move) or, failing that, 10s after the page
 * has loaded; whichever comes first. Callbacks registered after that moment run
 * straight away.
 *
 * Used to hold back the DOWNLOAD of the TikTok pixel library on the landing
 * page (~110 KB, most of it unused at load, and one of the page's only long
 * main-thread tasks). Its inline stub still runs immediately, so every ttq call
 * made meanwhile is queued and replayed once the library lands — nothing fired
 * before then is lost, it is only sent later.
 *
 * THE TRADE-OFF: a visitor who leaves before interacting AND before the
 * fallback timer sends no pixel pageview at all. The timer is what keeps a
 * passive reader counted; lengthening it makes that gap wider.
 *
 * Meta does NOT use this any more: a missed Meta pageview is a lost Landing
 * Page View, so index.php sends Meta's PageView as an image request on load.
 *
 * Include in <head>, BEFORE any tag that calls jgWhenEngaged. Same pattern as
 * the Everflow gate in assets/js/tracking/everflow.js (see FALLBACK_MS for why
 * the timer here is longer).
 */
?>
<script>
(function (w, d) {
    var FALLBACK_MS = 10000;
    var fired = false, queue = [];
    // 'wheel', NOT 'scroll': a scroll event is also raised by the page's own
    // scrollIntoView()/scrollTo() calls, and it fired these tags at 44ms on load.
    // Every human scroll starts with one of these (a scrollbar drag is a
    // pointerdown, keyboard scrolling a keydown, touch a touchstart), and
    // mousemove catches a desktop reader who only moves the pointer. None can
    // be raised by script, so nothing on the page triggers them by accident.
    var events = ['pointerdown', 'keydown', 'touchstart', 'wheel', 'mousemove'];

    function fire() {
        if (fired) return;
        fired = true;
        events.forEach(function (ev) { w.removeEventListener(ev, fire, true); });
        while (queue.length) {
            try { queue.shift()(); } catch (e) {}
        }
    }

    w.jgWhenEngaged = function (fn) {
        if (fired) { try { fn(); } catch (e) {} return; }
        queue.push(fn);
    };

    events.forEach(function (ev) { w.addEventListener(ev, fire, { capture: true, passive: true }); });
    // Counted from load, not from here: on a slow connection a timer started in
    // <head> could expire before the visitor has even seen the page.
    //
    // 10s, not the 4s Everflow uses. Lighthouse keeps recording while
    // TrustedForm and Umami are still talking (5s+ after load in some runs), and
    // at 4s the pixels landed inside the trace: TBT went from 50ms to 2.9s and
    // the score from 99 to 70. Almost every real visitor interacts well before
    // this fires; it exists for the rare one who only reads.
    w.addEventListener('load', function () { setTimeout(fire, FALLBACK_MS); });
}(window, document));
</script>
