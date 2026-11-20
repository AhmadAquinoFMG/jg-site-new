/* jgdebtrelief.com — per-lead event_id for Meta Pixel / Conversions API dedupe.
 *
 * Include on the form page after attribution.min.js:
 *   <script src="assets/dist/event-id.js?v=49"></script>
 *
 * What it does
 *   - mints one UUID v4 per funnel session and keeps it in sessionStorage
 *     (a refresh mid-funnel keeps the same id; a new session gets a new one)
 *   - adds <input type="hidden" name="event_id"> to #funnelForm so submit.php
 *     receives it with the rest of the FormData
 *   - exposes window.JG_EVENT_ID for anything else that needs it
 *   - clears the stored id when the thank-you page loads, so a second lead
 *     from the same browser is not deduped against the first
 *
 * submit.php forwards the same value to Lead Prosper as `event_id` and uses it
 * as the Conversions API event_id; thank-you.php fires the browser Lead with
 * eventID = the same value. Meta dedupes the pair.
 */
(function () {
  'use strict';
  var KEY = 'jgw_event_id';

  function uuid() {
    try {
      if (window.crypto && typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    } catch (e) {}
    var b = new Uint8Array(16), i;
    if (window.crypto && crypto.getRandomValues) {
      crypto.getRandomValues(b);
    } else {
      for (i = 0; i < 16; i++) b[i] = Math.floor(Math.random() * 256);
    }
    b[6] = (b[6] & 0x0f) | 0x40; // version 4
    b[8] = (b[8] & 0x3f) | 0x80; // variant
    var h = '';
    for (i = 0; i < 16; i++) h += (b[i] + 256).toString(16).slice(1);
    return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' +
           h.slice(16, 20) + '-' + h.slice(20);
  }

  var onThankYou = /thank-you/i.test(location.pathname);
  if (onThankYou) {
    try { sessionStorage.removeItem(KEY); } catch (e) {}
    return;
  }

  var id = '';
  try { id = sessionStorage.getItem(KEY) || ''; } catch (e) {}
  if (!/^[0-9a-f-]{36}$/i.test(id)) {
    id = uuid();
    try { sessionStorage.setItem(KEY, id); } catch (e) {}
  }
  window.JG_EVENT_ID = id;

  function attach() {
    var form = document.getElementById('funnelForm');
    if (!form) return;
    var input = form.querySelector('input[name="event_id"]');
    if (!input) {
      input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'event_id';
      input.id = 'event_id';
      form.appendChild(input);
    }
    input.value = id;
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', attach, { once: true });
  } else {
    attach();
  }
})();
