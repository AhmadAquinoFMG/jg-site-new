<?php
/**
 * Minified-asset lookup. tools/build-assets.mjs writes minified copies of the
 * landing page's JS/CSS to assets/dist/ plus a manifest of each source's
 * SHA-1. These helpers hand back the minified copy only while that hash still
 * matches the source — so a source edited without re-running `npm run build`
 * is served as-is rather than as a stale minified version of old code.
 *
 * Hashing ~150 KB per request costs well under a millisecond. The CRLF strip
 * mirrors the build script: Windows checkouts hold CRLF, the server LF.
 */
if (!function_exists('asset_min')) {
    /** Web path of the file to serve for $src (relative to the web root). */
    function asset_min(string $src): string
    {
        static $manifest = null;
        if ($manifest === null) {
            $raw      = @file_get_contents(dirname(__DIR__) . '/assets/dist/manifest.json');
            $manifest = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        }

        $entry = $manifest[$src] ?? null;
        if (!is_array($entry) || empty($entry['min']) || empty($entry['sha1'])) {
            return $src;
        }

        $root   = dirname(__DIR__);
        $source = @file_get_contents($root . '/' . $src);
        if (!is_string($source) || !is_file($root . '/' . $entry['min'])) {
            return $src;
        }

        return sha1(str_replace("\r\n", "\n", $source)) === $entry['sha1'] ? $entry['min'] : $src;
    }

    /** Echo a stylesheet's contents for inlining, minified when fresh. */
    function asset_inline(string $src): void
    {
        readfile(dirname(__DIR__) . '/' . asset_min($src));
    }
}
