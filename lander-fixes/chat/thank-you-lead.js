/* chat.relief.unitedsettlement.org thank-you.php — browser Lead with eventID.
 *
 * The page already resolves a single-use token into `data` with
 * `data.event_id` (the UUID /api/submit.php generated and posted to Lead
 * Prosper) and `data.verified_qualifies`, and already uses it for TikTok
 * CompleteRegistration and the GTM dataLayer. Meta gets nothing but PageView.
 *
 * Add this <script> right after the existing TikTok CompleteRegistration block
 * in <head>. fbq is already defined on the page (init + PageView), so the call
 * queues safely even if fbevents.js has not arrived yet.
 */
window.__usTokenPromise.then(function (data) {
  if (!data || !data.success || !data.verified_qualifies) return;   // same gate as TikTok
  if (typeof window.fbq !== 'function') return;
  var eventId = data.event_id || '';
  if (!eventId) return;
  fbq('track', 'Lead', { content_name: 'Debt Relief' }, { eventID: eventId });
});
