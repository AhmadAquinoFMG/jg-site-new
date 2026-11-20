/**
 * Minified copies of the landing page's own JS and CSS, for PageSpeed's
 * "Minify JavaScript" / "Minify CSS" findings. There is no bundler here and
 * none is wanted: the sources in assets/ stay the files you edit, and this
 * writes assets/dist/ next to them.
 *
 *   npm install          (once — esbuild is the only dependency)
 *   npm run build        (after editing any file in SOURCES, then commit dist/)
 *
 * Forgetting the rebuild is safe. manifest.json records a SHA-1 of each
 * source, and includes/assets.php serves the minified copy ONLY while that
 * hash still matches the source on disk — an edited source with a stale
 * dist/ file falls back to the unminified source instead of shipping old code.
 *
 * Hashes are taken over LF-normalised text, because Windows checkouts
 * (core.autocrlf) hold CRLF while the server holds LF; PHP normalises the
 * same way.
 */
import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { transform } from 'esbuild';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const dist = join(root, 'assets', 'dist');

// Source path (relative to the web root) => minified output name in dist/.
// faces.css is minified on its own so its relative font URLs are untouched
// (they resolve against the page, which is why it is inlined, not linked).
const SOURCES = {
    'assets/js/funnel.js':               'funnel.min.js',
    'assets/js/tracking/attribution.js': 'attribution.min.js',
    'assets/js/tracking/everflow.js':    'everflow.min.js',
    'assets/css/style.css':              'style.min.css',
    'assets/fonts/poppins-v24/faces.css': 'faces.min.css',
};

const read = (p) => readFileSync(join(root, p), 'utf8').replace(/\r\n/g, '\n');
const sha1 = (s) => createHash('sha1').update(s, 'utf8').digest('hex');

mkdirSync(dist, { recursive: true });
const manifest = {};

for (const [src, out] of Object.entries(SOURCES)) {
    const code = read(src);
    // No `target`: esbuild then keeps the syntax exactly as written and only
    // minifies. Down-levelling is not wanted — the sources already are ES5-ish
    // and anything newer in them is there on purpose.
    const { code: min } = await transform(code, {
        loader: src.endsWith('.css') ? 'css' : 'js',
        minify: true,
        legalComments: 'none',
    });
    writeFileSync(join(dist, out), min);
    manifest[src] = { sha1: sha1(code), min: relative(root, join(dist, out)).replace(/\\/g, '/') };
    console.log(`${src} ${code.length} -> ${min.length} bytes`);
}

writeFileSync(join(dist, 'manifest.json'), JSON.stringify(manifest, null, 2) + '\n');
