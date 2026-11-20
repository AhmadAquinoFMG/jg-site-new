<?php
// Run: php tools/test-debt-multiplier.php   (exit code 1 on any failure)
declare(strict_types=1);

require_once __DIR__ . "/../includes/debt-multiplier.php";
require __DIR__ . '/../includes/leadprosper.php';

$fails = 0;
$total = 0;
function check(string $label, $actual, $expected): void
{
    global $fails, $total;
    $total++;
    if ($actual !== $expected) {
        $fails++;
        echo "FAIL  $label: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n";
    }
}

// behindPaymentTrue
$behind = fn($v) => in_array(detectBehindPayment($v), ['over_60', 'over_30'], true);
foreach (['over_60', 'over_60_days', '60_plus', '60_days_plus', 'more_than_60_days', 'Over 60 Days', '60+',
          '90+ days', '90 days', '30+ Days', '30-60 days', 45, '45', 'Yes', 'true', 'over_30', 'over_30_days',
          '30_plus', 'more_than_30_days', 'More than 30 days', 'late', 'behind'] as $v) {
    check('behind true ' . var_export($v, true), $behind($v), true);
}
foreach (['No', 'false', 'current', 'Up to date', 'not_late', 'not_behind', 0, 15, '15 days', '', null, 'unknown'] as $v) {
    check('behind false ' . var_export($v, true), $behind($v), false);
}
check('over_60 bucket', detectBehindPayment('90+ days'), 'over_60');
check('over_30 bucket', detectBehindPayment('30-60 days'), 'over_30');
check('not_behind bucket', detectBehindPayment('current'), 'not_behind');
check('unknown bucket', detectBehindPayment('unknown'), '');

// employedTrue
$employed = fn($v) => detectEmployment($v) === 'employed';
foreach (['Employed', 'employee', 'full_time', 'Full Time', 'part_time', 'self_employed', 'Self-Employed', 'Yes', 'true', '1'] as $v) {
    check('employed true ' . var_export($v, true), $employed($v), true);
}
foreach (['Disability', 'SSDI', 'SSI', 'unemployed', 'No', '0', '', null, 'retired'] as $v) {
    check('employed false ' . var_export($v, true), $employed($v), false);
}
check('disability bucket', detectEmployment('on disability'), 'disability');
check('unemployed bucket', detectEmployment('not_employed'), 'unemployed');

// Tiers, totalDebt = 25000 — using this funnel's own option keys
$cases = [
    'behind + employed'   => [['behind_payment' => 'over_30', 'employment' => 'employed'], 2.0, 50000.0, 'both', 2],
    'behind only'         => [['behind_payment' => 'over_60', 'employment' => 'retired'], 1.5, 37500.0, 'one', 1],
    'employed only'       => [['behind_payment' => 'not_behind', 'employment' => 'employed'], 1.5, 37500.0, 'one', 1],
    'behind + disability' => [['behind_payment' => 'over_30', 'employment' => 'disability'], 1.5, 37500.0, 'one', 1],
    'neither'             => [['behind_payment' => 'not_behind', 'employment' => 'unemployed'], 1.0, 25000.0, 'none', 0],
    'nothing at all'      => [[], 1.0, 25000.0, 'none', 0],
];
foreach ($cases as $label => [$lead, $mult, $value, $tier, $count]) {
    $r = computeDebtMultiplier(null, $lead, 25000);
    check("$label multiplier", $r['multiplier_debt'], $mult);
    check("$label value", $r['multiplied_debt_value'], $value);
    check("$label tier", $r['value_tier'], $tier);
    check("$label indicators", $r['indicators_true'], $count);
}
$r = computeDebtMultiplier(null, ['behind_payment' => 'over_30'], 0);
check('totalDebt 0 multiplier', $r['multiplier_debt'], 1.5);
check('totalDebt 0 value', $r['multiplied_debt_value'], 0.0);

// Lookup: call record first, then lead; keys case/punctuation-insensitive
$r = computeDebtMultiplier(['Behind-Payment' => '60+'], ['behind_payment' => 'not_behind', 'Employment Status' => 'Full Time'], 25000);
check('call wins + fuzzy keys', $r['value_tier'], 'both');
$r = computeDebtMultiplier(['behind_payment' => ''], ['days_behind' => 45], 25000);
check('empty call value falls through to lead', $r['value_tier'], 'one');

// Overrides
check('ONE=1.25', debt_multiplier_tiers_from_env(['META_VALUE_TIER_ONE_MULTIPLIER' => '1.25'])['one'], 1.25);
foreach (['abc', '0', '-1', 'INF', 'NAN', '', null] as $bad) {
    check('ONE=' . var_export($bad, true) . ' falls back', debt_multiplier_tiers_from_env(['META_VALUE_TIER_ONE_MULTIPLIER' => $bad])['one'], 1.5);
}
$r = computeDebtMultiplier(null, ['behind_payment' => 'over_30'], 25000, debt_multiplier_tiers_from_env(['META_VALUE_TIER_ONE_MULTIPLIER' => '1.33333']));
check('override rounds to 4 dp', $r['multiplier_debt'], 1.3333);
check('override value', $r['multiplied_debt_value'], 33332.5);

// Inputs not mutated
$call = ['behind_payment' => '30+ Days'];
$lead = ['employed' => 'Employed'];
$before = [$call, $lead];
computeDebtMultiplier($call, $lead, 25000);
check('inputs unchanged', [$call, $lead], $before);

// LeadProsper payload carries both fields; total_debt untouched
$cfg = ['leadprosper' => [], 'debt_multiplier' => ['tiers' => DEBT_MULTIPLIER_DEFAULT_TIERS]];
$row = ['behind_payment' => 'over_30', 'employment' => 'employed'];
$p = leadprosper_payload($cfg, $row, [], 12000);
check('payload total_debt unchanged', $p['total_debt'] ?? null, 12000);
check('payload multiplier_debt', $p['multiplier_debt'] ?? null, 2.0);
check('payload multiplied_debt_value', $p['multiplied_debt_value'] ?? null, 24000.0);
$p = leadprosper_payload($cfg, ['behind_payment' => 'not_behind', 'employment' => 'retired'], [], null);
check('payload no figure still posts 1.0', $p['multiplier_debt'] ?? null, 1.0);
check('payload no figure posts 0 value', $p['multiplied_debt_value'] ?? null, 0.0);
check('value_tier not posted', array_key_exists('value_tier', $p), false);

echo ($fails === 0 ? 'PASS' : 'FAILED') . " — $total checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
