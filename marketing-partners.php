<?php
/** Marketing partners disclosure page, linked from the contact consent.
 * Publication review: docs/marketing-partners-review.md.
 */
$cfg = require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/assets.php';
$e = fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$partners = [
    [
        'name' => 'JG Wentworth',
        'business' => 'JGW Debt Settlement, LLC',
        'service' => 'Debt relief',
        'description' => 'Debt settlement services for eligible unsecured debt.',
        'logo' => $cfg['brand']['logo_header'],
        'privacy' => 'https://www.jgwentworth.com/jg-wentworth-company-r-consumer-privacy-notice',
    ],
    [
        'name' => 'United Debt',
        'business' => 'United Debt Settlement, LLC',
        'service' => 'Debt relief',
        'description' => 'Debt relief options for consumers seeking help with their debt.',
        'logo' => 'assets/img/buyers/united-settlement.webp',
        'privacy' => 'https://unitedsettlement.com/privacy-policy/',
    ],
    // Paused: Docupop. Remove this block's comment markers to resume.
    /*
    [
        'name' => 'Docupop',
        'business' => 'Eleven X',
        'service' => 'Student loan assistance',
        'description' => 'Information and assistance related to student loan repayment options.',
        'logo' => 'assets/img/buyers/Docupop-Logo_v02.png',
        'privacy' => 'https://www.docupop.com/privacy/',
    ],
    */
    // Paused: ScoreShuttle. Remove this block's comment markers to resume.
    /*
    [
        'name' => 'ScoreShuttle',
        'business' => 'FinnTekk, Inc.',
        'service' => 'Credit monitoring',
        'description' => 'Credit monitoring tools and information to help you understand your credit.',
        'logo' => 'assets/img/buyers/scoreshuttle-logo-4000w.png',
        'privacy' => 'https://www.scoreshuttle.com/privacy-policy/',
    ],
    */
    // Paused: InCharge. Remove this block's comment markers to resume.
    /*
    [
        'name' => 'InCharge',
        'business' => 'InCharge Debt Solutions',
        'service' => 'Credit counseling',
        'description' => 'Credit counseling and debt management services.',
        'logo' => 'assets/img/buyers/Incharge_Debt_Solutions-r.webp',
        'privacy' => 'https://www.incharge.org/privacy/',
    ],
    */
];
?>
<!DOCTYPE html>
<html lang="en-US">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#006846">
    <title>Marketing Partners | <?= $e($cfg['brand']['name']) ?></title>
    <link rel="icon" type="image/png" href="assets/img/jg-icon.png">
    <style><?php asset_inline('assets/fonts/poppins-v24/faces.css'); ?></style>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= $e($cfg['asset_version']) ?>">
    <link rel="stylesheet" href="assets/css/marketing-partners.css?v=2">
</head>
<body class="partners-page">
    <a class="partners-skip" href="#main-content">Skip to content</a>
    <?php include __DIR__ . '/includes/header.php'; ?>

    <main id="main-content" class="partners-main">
        <header class="partners-intro">
            <p class="partners-eyebrow">Clarity at every step</p>
            <h1>Our marketing partners</h1>
            <p class="partners-lede">Know who may contact you about your request.</p>
            <p>Depending on your needs and the options available to you, JG Wentworth or one of the companies listed below may contact you about financial services.</p>
        </header>

        <section aria-labelledby="partners-list-title">
            <div class="partners-section-head">
                <h2 id="partners-list-title">Meet the companies</h2>
                <div class="partners-controls" hidden>
                    <button type="button" id="partners-prev" aria-label="Scroll to previous partners" aria-controls="partners-track" disabled><span aria-hidden="true">←</span></button>
                    <button type="button" id="partners-next" aria-label="Scroll to next partners" aria-controls="partners-track"><span aria-hidden="true">→</span></button>
                </div>
            </div>
            <p class="partners-scroll-hint" id="partners-scroll-hint">Scroll left or right to explore our partners.</p>
            <div class="partners-grid" id="partners-track" tabindex="0" role="region" aria-label="Marketing partners" aria-describedby="partners-scroll-hint">
                <?php foreach ($partners as $partner): ?>
                    <article class="partner-card" aria-label="<?= $e($partner['name']) ?>">
                        <div class="partner-card-logo">
                            <img src="<?= $e($partner['logo']) ?>" alt="<?= $e($partner['name']) ?> logo" decoding="async">
                        </div>
                        <div class="partner-card-body">
                            <p class="partner-card-service"><?= $e($partner['service']) ?></p>
                            <h3><?= $e($partner['name']) ?></h3>
                            <p class="partner-card-business"><?= $e($partner['business']) ?></p>
                            <p class="partner-card-description"><?= $e($partner['description']) ?></p>
                            <a class="partner-card-link" href="<?= $e($partner['privacy']) ?>" target="_blank" rel="noopener noreferrer">Privacy policy<span class="partners-sr-only"> for <?= $e($partner['name']) ?> (opens in a new tab)</span><span aria-hidden="true"> ↗</span></a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <aside class="partners-note" aria-labelledby="partners-note-title">
            <svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 3 4 6v6c0 4 8 9 8 9s8-5 8-9V6l-8-3Z"/><path d="m8.5 12 2.5 2.5 4.5-5"/></svg>
            <div><h2 id="partners-note-title">Your information. Your choices.</h2>
                <p>You may not be contacted by every company listed. Each company has its own privacy policy, which explains how it handles your information and how to contact it about your privacy choices.</p>
            </div>
        </aside>
        <p class="partners-return"><a href="index.php"><span aria-hidden="true">← </span>Return to JG Wentworth</a></p>
    </main>
    <?php include __DIR__ . '/includes/footer.php'; ?>
    <script>
        (function () {
            var track = document.getElementById('partners-track');
            var previous = document.getElementById('partners-prev');
            var next = document.getElementById('partners-next');
            var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
            document.querySelector('.partners-controls').hidden = false;

            function updateControls() {
                previous.disabled = track.scrollLeft <= 2;
                next.disabled = track.scrollLeft + track.clientWidth >= track.scrollWidth - 2;
            }

            function scrollPartners(direction) {
                var cards = track.querySelectorAll('.partner-card');
                var step = cards[1].offsetLeft - cards[0].offsetLeft;
                track.scrollBy({ left: direction * step, behavior: reducedMotion.matches ? 'auto' : 'smooth' });
            }

            previous.addEventListener('click', function () { scrollPartners(-1); });
            next.addEventListener('click', function () { scrollPartners(1); });
            track.addEventListener('scroll', updateControls, { passive: true });
            window.addEventListener('resize', updateControls);
            updateControls();
        })();
    </script>
</body>
</html>
