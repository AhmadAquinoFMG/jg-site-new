<?php
declare(strict_types=1);

/**
 * Debt multiplier for LeadProsper (informational fields only).
 *
 * The multiplier tier is how many of these two are true:
 *   behindPaymentTrue — the lead is 30+ days late on payments
 *   employedTrue      — the lead is employed (disability does NOT count)
 *
 *   none (0 true) = 1.0   one (1 true) = 1.5   both (2 true) = 2.0
 *
 * Overrides come from env META_VALUE_TIER_{NONE,ONE,BOTH}_MULTIPLIER, read into
 * config.php ['debt_multiplier']['tiers'] via debt_multiplier_tiers_from_env().
 * A missing or unknown indicator counts as false. total_debt itself is never
 * changed. includes/leadprosper.php sends the result as multiplier_debt
 * (the multiplier) and multiplied_debt_value (total_debt × multiplier).
 *
 * Ported from unitedsettlement-chat-based/api/debt-multiplier.php. Load with
 * require_once only (top-level consts cannot sit inside a function_exists guard).
 */

const DEBT_MULTIPLIER_DEFAULT_TIERS = ['none' => 1.0, 'one' => 1.5, 'both' => 2.0];

const DEBT_MULTIPLIER_BEHIND_KEYS = [
    'behind_payment', 'behind_payments', 'payment_status', 'payment_delinquency', 'days_behind',
];
const DEBT_MULTIPLIER_EMPLOYMENT_KEYS = [
    'employed', 'employment', 'employment_status', 'job_status', 'work_status',
];

/** Lowercase, collapse every run of non-alphanumerics to "_", trim "_". */
function debt_multiplier_normalize(string $value): string
{
    return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($value))), '_');
}

/** Key form used for matching: case- and punctuation-insensitive. */
function debt_multiplier_key(string $key): string
{
    return (string) preg_replace('/[^a-z0-9]/', '', strtolower($key));
}

/**
 * First non-empty value for any of $keys, searching each source in order
 * (call record first, then lead), keys in priority order within a source.
 */
function debt_multiplier_lookup(array $sources, array $keys)
{
    foreach ($sources as $source) {
        if (!is_array($source)) {
            continue;
        }
        $indexed = [];
        foreach ($source as $k => $v) {
            $nk = debt_multiplier_key((string) $k);
            if (!array_key_exists($nk, $indexed)) {
                $indexed[$nk] = $v;
            }
        }
        foreach ($keys as $key) {
            $v = $indexed[debt_multiplier_key($key)] ?? null;
            if ($v === null || $v === '' || (is_string($v) && trim($v) === '')) {
                continue;
            }
            return $v;
        }
    }
    return null;
}

function debt_multiplier_days_bucket(float $days): string
{
    if ($days >= 60) return 'over_60';
    if ($days >= 30) return 'over_30';
    return 'not_behind';
}

/**
 * @return string 'over_60' | 'over_30' | 'not_behind' | ''
 * Parses the RAW value first: normalization strips "+", so "30+ days" is
 * read for its leading integer before any normalizing happens. The
 * over_60 / over_30 aliases (over_60_days, 60_plus, more_than_60_days, …)
 * all carry the number, so the integer rule covers them too.
 */
function detectBehindPayment($value): string
{
    if ($value === null || is_bool($value) || is_array($value) || is_object($value)) {
        return $value === true ? 'over_30' : ($value === false ? 'not_behind' : '');
    }
    if (is_int($value) || is_float($value)) {
        return is_finite((float) $value) ? debt_multiplier_days_bucket((float) $value) : '';
    }

    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }
    if (is_numeric($raw)) {
        return debt_multiplier_days_bucket((float) $raw);
    }
    // "30+ days", "60+", "90 days", "30-60 days", "More than 30 days", "over_60"
    if (preg_match('/\d+/', $raw, $m) === 1) {
        return debt_multiplier_days_bucket((float) $m[0]);
    }

    $n = debt_multiplier_normalize($raw);
    if (in_array($n, ['yes', 'true', 'behind', 'late'], true)) {
        return 'over_30';
    }
    if (in_array($n, ['no', 'false', 'current', 'up_to_date', 'not_late', 'not_behind'], true)) {
        return 'not_behind';
    }
    return '';
}

/** @return string 'employed' | 'unemployed' | 'disability' | '' */
function detectEmployment($value): string
{
    if ($value === true) return 'employed';
    if ($value === false) return 'unemployed';
    if ($value === null || is_array($value) || is_object($value)) {
        return '';
    }

    $n = debt_multiplier_normalize((string) $value);
    if (in_array($n, ['employed', 'employee', 'full_time', 'part_time', 'self_employed', 'yes', 'true', '1'], true)) {
        return 'employed';
    }
    if (in_array($n, ['unemployed', 'not_employed', 'no', 'false', '0'], true)) {
        return 'unemployed';
    }
    if (in_array($n, ['disability', 'disabled', 'on_disability', 'ssi', 'ssdi'], true)) {
        return 'disability';
    }
    return '';
}

/**
 * Tier multipliers with env overrides. An override must be numeric, finite
 * and > 0, else the default is kept. $env is any key => value map.
 */
function debt_multiplier_tiers_from_env(array $env): array
{
    $tiers = DEBT_MULTIPLIER_DEFAULT_TIERS;
    foreach (['none' => 'NONE', 'one' => 'ONE', 'both' => 'BOTH'] as $tier => $suffix) {
        $raw = $env["META_VALUE_TIER_{$suffix}_MULTIPLIER"] ?? null;
        if (is_string($raw)) {
            $raw = trim($raw);
        }
        if (is_numeric($raw)) {
            $f = (float) $raw;
            if (is_finite($f) && $f > 0) {
                $tiers[$tier] = $f;
            }
        }
    }
    return $tiers;
}

/**
 * Pure: reads only its arguments and never mutates them.
 *
 * @param array|null $call      call/disposition record, searched first (null on this site)
 * @param array|null $lead      lead record
 * @param mixed      $totalDebt the debt figure to scale; returned value only, never changed
 * @param array|null $tiers     ['none'=>, 'one'=>, 'both'=>]; defaults when null
 * @return array{multiplier_debt: float, multiplied_debt_value: float, value_tier: string, indicators_true: int}
 */
function computeDebtMultiplier(?array $call, ?array $lead, $totalDebt, ?array $tiers = null): array
{
    $tiers = $tiers ?? DEBT_MULTIPLIER_DEFAULT_TIERS;
    $sources = [$call ?? [], $lead ?? []];

    $behind = detectBehindPayment(debt_multiplier_lookup($sources, DEBT_MULTIPLIER_BEHIND_KEYS));
    $employment = detectEmployment(debt_multiplier_lookup($sources, DEBT_MULTIPLIER_EMPLOYMENT_KEYS));

    $behindPaymentTrue = ($behind === 'over_60' || $behind === 'over_30');
    $employedTrue = ($employment === 'employed');
    $count = (int) $behindPaymentTrue + (int) $employedTrue;
    $tier = [0 => 'none', 1 => 'one', 2 => 'both'][$count];

    $multiplier = round((float) ($tiers[$tier] ?? DEBT_MULTIPLIER_DEFAULT_TIERS[$tier]), 4);
    $debt = is_numeric($totalDebt) && is_finite((float) $totalDebt) ? (float) $totalDebt : 0.0;

    return [
        'multiplier_debt' => $multiplier,
        'multiplied_debt_value' => round($debt * $multiplier, 2),
        'value_tier' => $tier,
        'indicators_true' => $count,
    ];
}
