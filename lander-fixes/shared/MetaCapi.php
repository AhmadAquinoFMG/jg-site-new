<?php
/**
 * Meta Conversions API sender. Drop next to submit.php on both landers.
 *
 *   require_once __DIR__ . '/MetaCapi.php';
 *   MetaCapi::sendLead([
 *       'event_id'   => $eventId,              // the same UUID the browser Pixel uses
 *       'event_time' => time(),
 *       'source_url' => $landingPageUrl,       // lead_data.landing_page_url
 *       'fbc'        => $post['fbc'] ?? '',    // or rebuilt from fbclid, see below
 *       'fbp'        => $post['fbp'] ?? '',
 *       'fbclid'     => $post['fbclid'] ?? '',
 *       'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
 *       'ua'         => $_SERVER['HTTP_USER_AGENT'] ?? '',
 *       'external_id'=> $_COOKIE['jgw_uid'] ?? '',
 *       'email'      => $post['email'] ?? '',
 *       'phone'      => $post['phone'] ?? '',
 *       'first_name' => $post['first_name'] ?? '',
 *       'last_name'  => $post['last_name'] ?? '',
 *       'city'       => $post['city'] ?? '',
 *       'state'      => $post['state'] ?? '',
 *       'zip'        => $post['zip_code'] ?? ($post['zip'] ?? ''),
 *       'dob'        => $post['date_of_birth'] ?? '',   // any parseable date
 *       'custom'     => ['affid' => $post['affid'] ?? '', 'lp_lead_id' => $lpLeadId],
 *   ]);
 *
 * Environment (set in the web server / .env, never in the repo):
 *   META_PIXEL_IDS             comma-separated pixel ids that ALSO fire the browser Lead
 *   META_CAPI_ACCESS_TOKEN     Conversions API system-user token (Events Manager → Settings)
 *   META_CAPI_TEST_EVENT_CODE  optional; set while verifying in Test Events, then unset
 *   META_API_VERSION           optional, default v25.0
 *
 * Fire-and-forget: 3 s timeout, failures are logged, never shown to the visitor,
 * and never block the Lead Prosper post. Call it AFTER Lead Prosper accepted.
 */
final class MetaCapi
{
    public static function sendLead(array $lead): array
    {
        return self::send('Lead', $lead);
    }

    /** For later: fire when Lead Prosper reports the lead sold / qualified. */
    public static function sendQualified(array $lead, float $value = 0.0): array
    {
        $lead['value'] = $value;
        return self::send('Purchase', $lead);
    }

    public static function send(string $eventName, array $lead): array
    {
        $pixels = array_filter(array_map('trim', explode(',', (string) getenv('META_PIXEL_IDS'))));
        $token  = (string) getenv('META_CAPI_ACCESS_TOKEN');
        if (!$pixels || !$token) {
            self::log('not configured: META_PIXEL_IDS / META_CAPI_ACCESS_TOKEN missing');
            return ['sent' => 0, 'error' => 'not_configured'];
        }

        $eventId = (string) ($lead['event_id'] ?? '');
        if ($eventId === '') {
            self::log('refusing to send without event_id (dedupe would break)');
            return ['sent' => 0, 'error' => 'missing_event_id'];
        }

        $fbc = (string) ($lead['fbc'] ?? '');
        if ($fbc === '' && !empty($lead['fbclid']) && strpos($lead['fbclid'], '{{') === false) {
            // Meta's documented format: fb.<subdomain index>.<ms timestamp>.<fbclid>
            $fbc = 'fb.1.' . (int) round(microtime(true) * 1000) . '.' . $lead['fbclid'];
        }

        $userData = array_filter([
            'em'          => self::hashList($lead['email'] ?? '', 'email'),
            'ph'          => self::hashList($lead['phone'] ?? '', 'phone'),
            'fn'          => self::hashList($lead['first_name'] ?? '', 'name'),
            'ln'          => self::hashList($lead['last_name'] ?? '', 'name'),
            'ct'          => self::hashList($lead['city'] ?? '', 'city'),
            'st'          => self::hashList($lead['state'] ?? '', 'state'),
            'zp'          => self::hashList($lead['zip'] ?? '', 'zip'),
            'db'          => self::hashList($lead['dob'] ?? '', 'dob'),
            'country'     => self::hashList('us', 'plain'),
            'external_id' => self::hashList($lead['external_id'] ?? '', 'plain'),
            'client_ip_address' => $lead['ip'] ?? null,
            'client_user_agent' => $lead['ua'] ?? null,
            'fbc'               => $fbc ?: null,
            'fbp'               => $lead['fbp'] ?? null,
        ], static fn ($v) => $v !== null && $v !== '' && $v !== []);

        $custom = array_filter((array) ($lead['custom'] ?? []), static fn ($v) => $v !== null && $v !== '');
        if (isset($lead['value'])) {
            $custom['value'] = (float) $lead['value'];
            $custom['currency'] = 'USD';
        }

        $event = array_filter([
            'event_name'       => $eventName,
            'event_time'       => (int) ($lead['event_time'] ?? time()),
            'event_id'         => $eventId,
            'event_source_url' => $lead['source_url'] ?? null,
            'action_source'    => 'website',
            'user_data'        => $userData,
            'custom_data'      => $custom ?: null,
        ], static fn ($v) => $v !== null);

        $payload = ['data' => [$event]];
        $test = (string) getenv('META_CAPI_TEST_EVENT_CODE');
        if ($test !== '') {
            $payload['test_event_code'] = $test;
        }

        $version = (string) (getenv('META_API_VERSION') ?: 'v25.0');
        $sent = 0;
        $errors = [];
        foreach ($pixels as $pixelId) {
            $url = "https://graph.facebook.com/{$version}/{$pixelId}/events";
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS     => json_encode($payload + ['access_token' => $token]),
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            if ($code === 200) {
                $sent++;
            } else {
                $errors[$pixelId] = $err ?: substr((string) $body, 0, 300);
                self::log("pixel {$pixelId} HTTP {$code}: " . ($err ?: $body));
            }
        }
        return ['sent' => $sent, 'errors' => $errors, 'event_id' => $eventId];
    }

    /** Meta wants lowercase, trimmed, SHA-256 hex; phones digits only with country code. */
    private static function hashList(string $value, string $kind): array
    {
        $v = trim(mb_strtolower($value));
        if ($v === '') {
            return [];
        }
        switch ($kind) {
            case 'phone':
                $v = preg_replace('/\D+/', '', $v);
                if ($v === '') return [];
                if (strlen($v) === 10) $v = '1' . $v;
                break;
            case 'zip':
                $v = substr(preg_replace('/\s+/', '', $v), 0, 5);
                break;
            case 'city':
            case 'name':
                $v = preg_replace('/[^a-z]/', '', $v);
                break;
            case 'state':
                $v = substr(preg_replace('/[^a-z]/', '', $v), 0, 2);
                break;
            case 'dob':
                $ts = strtotime($value);
                if (!$ts) return [];
                $v = date('Ymd', $ts);
                break;
        }
        return $v === '' ? [] : [hash('sha256', $v)];
    }

    private static function log(string $msg): void
    {
        error_log('[MetaCapi] ' . $msg);
    }
}
