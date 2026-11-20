<?php
/**
 * Site header: centered logo with a thin underline, plus a click-to-call CTA
 * pinned top right with the CTA number (['brand']['cta_phone'], falling back
 * to ['brand']['phone']) on every page that uses this header. $cfg from the page.
 */
$brand    = $cfg['brand'];
$ctaPhone = (string) ($brand['cta_phone'] ?? $brand['phone'] ?? '');
$showCta  = $ctaPhone !== '';
$ctaTel   = '';
if ($showCta) {
    $digits = preg_replace('/\D/', '', $ctaPhone);
    $ctaTel = strlen($digits) === 10 ? '+1' . $digits
            : (strlen($digits) === 11 && $digits[0] === '1' ? '+' . $digits : $digits);
}
?>
<header class="site-header">
    <div class="header-inner">
        <a class="logo" href="index.php" aria-label="<?= htmlspecialchars($brand['name']) ?>">
            <img src="<?= htmlspecialchars($brand['logo_header']) ?>"
                 alt="<?= htmlspecialchars($brand['name']) ?> logo" width="200" height="44" fetchpriority="high">
        </a>
        <?php if ($showCta): ?>
        <a class="header-cta" href="tel:<?= htmlspecialchars($ctaTel) ?>"
           aria-label="Call <?= htmlspecialchars($brand['name']) ?> at <?= htmlspecialchars($ctaPhone) ?>">
            <svg class="header-cta-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
                <path fill="currentColor" d="M6.62 10.79a15.05 15.05 0 0 0 6.59 6.59l2.2-2.2a1 1 0 0 1 1.02-.24c1.12.37 2.33.57 3.57.57a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.25.2 2.45.57 3.57a1 1 0 0 1-.25 1.02l-2.2 2.2z"/>
            </svg>
            <span class="header-cta-text">
                <span class="header-cta-label">Speak with a debt specialist</span>
                <span class="header-cta-number"><?= htmlspecialchars($ctaPhone) ?></span>
            </span>
            <span class="header-cta-short">Call Now</span>
        </a>
        <?php endif; ?>
    </div>
</header>
