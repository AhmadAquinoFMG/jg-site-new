"""
Lead Prosper attribution audit (read-only).

Pulls recent leads from every campaign on the account and reports which
Meta attribution fields (ad_id, fbclid, utm_*, lp_subid*, event ids, ...)
are actually populated in lead_data. This tells you whether ad-level
tracing is possible today or whether the URL macros / form mapping need
fixing upstream first.

Usage (PowerShell):
    $env:LP_API_KEY = "<your developer api key>"
    python lp_attribution_audit.py --days 14 --limit 300

Options:
    --days N              look back N days (default 14)
    --limit N             leads per campaign (per segment) to sample, max 500 (default 300)
    --campaign ID         audit only this campaign id
    --affid 989,995,1024  only leads whose lead_data `affid` field equals one of these
                          (one server-side query per value; change the field with --affid-field)
    --affid-field NAME    lead_data field the --affid values are matched against (default: affid)
    --supplier 12,34      only leads from these Lead Prosper supplier ids
    --dump                also write the raw leads to lp_leads_sample.json

Example:
    python lp_attribution_audit.py --days 14 --affid 989,995,1024
"""
import argparse
import json
import os
import re
import sys
import urllib.parse
import urllib.request
from collections import Counter, defaultdict
from datetime import datetime, timedelta, timezone

BASE = "https://api.leadprosper.io/public"

# Field-name patterns that indicate Meta / click attribution data.
ATTRIBUTION_PATTERNS = {
    "meta_ad_id":        r"^(fb_|meta_|facebook_)?ad_?id$|^adid$",
    "meta_adset_id":     r"adset|ad_set",
    "meta_campaign_id":  r"^(fb_|meta_|facebook_)?campaign_?(id|name)$",
    "meta_placement":    r"placement|site_source",
    "fbclid":            r"fbclid",
    "fbc_cookie":        r"^_?fbc$",
    "fbp_cookie":        r"^_?fbp$",
    "leadgen_id":        r"leadgen|lead_gen|form_id",
    "event_id":          r"event_?id|dedup",
    "utm_source":        r"utm_source",
    "utm_medium":        r"utm_medium",
    "utm_campaign":      r"utm_campaign",
    "utm_content":       r"utm_content",
    "utm_term":          r"utm_term",
    "gclid_or_other":    r"gclid|ttclid|msclkid|wbraid|gbraid",
    "lp_subid":          r"^lp_subid\d*$|^sub_?id\d*$|^s\d$",
    "source_url":        r"source_url|landing|referr?er|page_url|url$",
    "trusted_form":      r"trusted_?form|jornaya|lead_?id_token|xxTrustedForm",
}

# Values that look like Meta ad/adset/campaign ids (long numeric) or fbclid.
LOOKS_LIKE_META_ID = re.compile(r"^\d{13,20}$")
LOOKS_LIKE_FBCLID = re.compile(r"^(IwAR|IwY|IwZ|PAAa|PAZX)[A-Za-z0-9_-]{20,}$")


def api_get(path, params, token):
    url = f"{BASE}{path}?{urllib.parse.urlencode(params, doseq=True)}"
    req = urllib.request.Request(url, headers={
        "Authorization": f"Bearer {token}",
        "Accept": "application/json",
        "User-Agent": "lp-attribution-audit/1.0",
    })
    with urllib.request.urlopen(req, timeout=60) as r:
        return json.loads(r.read().decode("utf-8"))


def list_campaigns(token):
    campaigns, search_after = [], None
    while True:
        params = {"view": "short", "limit": 100}
        if search_after:
            params["search_after"] = search_after
        resp = api_get("/v2/campaigns", params, token)
        data = resp.get("data", resp)
        campaigns.extend(data.get("campaigns", []))
        search_after = data.get("search_after")
        if not search_after:
            break
    return campaigns


def fetch_leads(token, campaign_id, start, end, limit, where=None, supplier=None):
    """where: dict of lead_data field -> exact value (server-side filter)."""
    leads, search_after = [], None
    while len(leads) < limit:
        params = {
            "campaign": campaign_id,
            "start_date": start,
            "end_date": end,
            "limit": min(500, limit - len(leads)),
            "test": "false",
        }
        if supplier is not None:
            params["supplier"] = supplier
        if where:
            params["where[match]"] = "all"
            for field, value in where.items():
                params[f"where[conditions][{field}]"] = value
        if search_after:
            params["search_after"] = search_after
        resp = api_get("/v2/leads", params, token)
        data = resp.get("data", resp)
        page = data.get("leads", [])
        leads.extend(page)
        search_after = data.get("search_after")
        if not page or not search_after:
            break
    return leads[:limit]


def classify_key(key):
    k = key.lower()
    return [name for name, pat in ATTRIBUTION_PATTERNS.items() if re.search(pat, k, re.I)]


def audit_campaign(leads):
    n = len(leads)
    key_seen = Counter()          # key -> leads where key exists
    key_filled = Counter()        # key -> leads where value non-empty
    key_samples = defaultdict(set)
    cat_filled = Counter()        # attribution category -> leads with any filled key in that cat
    meta_id_like = Counter()      # key -> count of values that look like a Meta numeric id
    fbclid_like = Counter()
    supplier_counts = Counter()

    for lead in leads:
        ld = lead.get("lead_data") or {}
        sup = (lead.get("supplier") or {}).get("name") or "?"
        supplier_counts[sup] += 1
        filled_cats = set()
        for k, v in ld.items():
            key_seen[k] += 1
            sval = "" if v is None else str(v).strip()
            if sval and sval.lower() not in ("null", "none", "undefined", "n/a", "{}", "[]"):
                key_filled[k] += 1
                if len(key_samples[k]) < 3:
                    key_samples[k].add(sval[:60])
                for cat in classify_key(k):
                    filled_cats.add(cat)
                if LOOKS_LIKE_META_ID.match(sval):
                    meta_id_like[k] += 1
                if LOOKS_LIKE_FBCLID.match(sval):
                    fbclid_like[k] += 1
        for cat in filled_cats:
            cat_filled[cat] += 1

    return {
        "n": n,
        "key_seen": key_seen,
        "key_filled": key_filled,
        "key_samples": key_samples,
        "cat_filled": cat_filled,
        "meta_id_like": meta_id_like,
        "fbclid_like": fbclid_like,
        "suppliers": supplier_counts,
    }


def pct(a, b):
    return f"{(100.0 * a / b):5.1f}%" if b else "   n/a"


def print_report(camp, r, segment_label=""):
    n = r["n"]
    print("=" * 78)
    print(f"Campaign {camp['id']}  {camp.get('name')}  [{camp.get('campaign_type')}]  {segment_label}")
    print(f"Live leads sampled: {n}")
    if n == 0:
        print("  (no leads in window)")
        return
    print("  Suppliers:", ", ".join(f"{s} ({c})" for s, c in r["suppliers"].most_common(8)))

    print("\n  Attribution coverage (share of leads with a non-empty value):")
    for cat in ATTRIBUTION_PATTERNS:
        c = r["cat_filled"].get(cat, 0)
        flag = "  <-- MISSING" if c == 0 and cat in ("meta_ad_id", "fbclid", "event_id") else ""
        print(f"    {cat:18s} {pct(c, n)}  ({c}/{n}){flag}")

    if r["meta_id_like"]:
        print("\n  Fields whose values look like Meta numeric ids:")
        for k, c in r["meta_id_like"].most_common():
            print(f"    {k:30s} {c}/{n}")
    if r["fbclid_like"]:
        print("\n  Fields whose values look like fbclid tokens:")
        for k, c in r["fbclid_like"].most_common():
            print(f"    {k:30s} {c}/{n}")

    print("\n  All lead_data fields seen (filled / present), with sample values:")
    for k, seen in sorted(r["key_seen"].items(), key=lambda kv: -r["key_filled"][kv[0]]):
        filled = r["key_filled"][k]
        samples = "; ".join(sorted(r["key_samples"][k]))
        if re.search(r"email|phone|first_name|last_name|address|ssn|dob|birth", k, re.I):
            samples = "<pii redacted>"
        print(f"    {k:30s} {filled:>4}/{seen:<4}  {samples}")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--days", type=int, default=14)
    ap.add_argument("--limit", type=int, default=300)
    ap.add_argument("--campaign", type=int)
    ap.add_argument("--affid", type=str, help="comma-separated lead_data affid values, e.g. 989,995,1024")
    ap.add_argument("--affid-field", type=str, default="affid",
                    help="lead_data field the --affid values are matched against")
    ap.add_argument("--supplier", type=str, help="comma-separated Lead Prosper supplier ids")
    ap.add_argument("--dump", action="store_true")
    args = ap.parse_args()

    token = os.environ.get("LP_API_KEY")
    if not token:
        sys.exit("Set LP_API_KEY in your environment first (do not hardcode it).")

    end = datetime.now(timezone.utc)
    start = end - timedelta(days=args.days)
    start_s, end_s = start.strftime("%Y-%m-%d %H:%M:%S"), end.strftime("%Y-%m-%d %H:%M:%S")

    # Build the list of segments to query. Each segment is one server-side filter.
    # (label, where-dict-or-None, supplier-id-or-None)
    segments = []
    if args.affid:
        for v in [x.strip() for x in args.affid.split(",") if x.strip()]:
            segments.append((f"{args.affid_field}={v}", {args.affid_field: v}, None))
    if args.supplier:
        for v in [x.strip() for x in args.supplier.split(",") if x.strip()]:
            segments.append((f"supplier={v}", None, int(v)))
    if not segments:
        segments.append(("", None, None))

    campaigns = list_campaigns(token)
    if args.campaign:
        campaigns = [c for c in campaigns if c["id"] == args.campaign] or [{"id": args.campaign, "name": "?"}]
    print(f"Found {len(campaigns)} campaign(s). Window: {start_s} -> {end_s} UTC")
    if args.affid or args.supplier:
        print("Segments:", ", ".join(s[0] for s in segments))

    all_leads = {}
    overall = Counter()
    overall_n = 0
    per_segment = {label: {"n": 0, "cats": Counter(), "campaigns": Counter()} for label, _, _ in segments}

    for camp in campaigns:
        for label, where, supplier in segments:
            try:
                leads = fetch_leads(token, camp["id"], start_s, end_s, args.limit,
                                    where=where, supplier=supplier)
            except Exception as e:  # noqa: BLE001
                print(f"\nCampaign {camp['id']} {camp.get('name')} {label}: ERROR {e}")
                continue
            if not leads and label:
                continue  # skip empty campaign/segment combos to keep output readable
            r = audit_campaign(leads)
            print_report(camp, r, segment_label=f"[{label}]" if label else "")
            overall_n += r["n"]
            overall.update(r["cat_filled"])
            seg = per_segment[label]
            seg["n"] += r["n"]
            seg["cats"].update(r["cat_filled"])
            seg["campaigns"][f"{camp['id']} {camp.get('name')}"] += r["n"]
            if args.dump:
                all_leads[f"{camp['id']}|{label}"] = leads

    summary_cats = ("meta_ad_id", "meta_adset_id", "meta_campaign_id", "fbclid", "fbc_cookie",
                    "event_id", "leadgen_id", "lp_subid", "utm_source", "utm_campaign", "utm_content")

    if len(segments) > 1 or segments[0][0]:
        print("\n" + "=" * 78)
        print("PER-SEGMENT TOTALS")
        for label, _, _ in segments:
            seg = per_segment[label]
            print(f"\n  {label}: {seg['n']} live leads")
            if seg["campaigns"]:
                print("    campaigns:", "; ".join(f"{k} ({v})" for k, v in seg["campaigns"].most_common(6)))
            for cat in summary_cats:
                print(f"    {cat:18s} {pct(seg['cats'].get(cat, 0), seg['n'])}")

    print("\n" + "=" * 78)
    print(f"ACCOUNT TOTAL (all segments): {overall_n} live leads sampled")
    for cat in summary_cats:
        print(f"    {cat:18s} {pct(overall.get(cat, 0), overall_n)}")

    def share(cat):
        return (overall.get(cat, 0) / overall_n) if overall_n else 0.0

    verdict = []
    ad_share = max(share("meta_ad_id"), share("leadgen_id"))
    if ad_share >= 0.50:
        verdict.append(f"Ad identity on {ad_share:.0%} of leads: deterministic ad-level tracing works for this traffic.")
    elif ad_share >= 0.05:
        verdict.append(f"Ad identity on only {ad_share:.0%} of leads: tracing works for a minority; "
                       "find which source populates it and make the rest match.")
    else:
        verdict.append(f"Ad identity on {ad_share:.1%} of leads (effectively none): nothing can be traced to a "
                       "specific ad yet. Fix URL macros ({{ad.id}}) or Lead Ads field mapping first.")
    click_share = max(share("fbclid"), share("fbc_cookie"))
    if click_share >= 0.50:
        verdict.append(f"fbclid/_fbc on {click_share:.0%}: Conversions API events can be matched to the click.")
    elif click_share > 0:
        verdict.append(f"fbclid/_fbc on only {click_share:.1%}: the landing page or supplier is dropping the "
                       "click id for most Meta traffic.")
    else:
        verdict.append("No fbclid/_fbc: CAPI events would match only on hashed PII, with weaker attribution.")
    if share("event_id") == 0:
        verdict.append("No event_id stored: add a per-lead UUID so Pixel + CAPI dedupe and leads can be "
                       "looked up by event id.")
    else:
        verdict.append(f"A field matching 'event_id' is filled on {share('event_id'):.0%} of leads. Check its "
                       "sample values above: it is only useful if the SAME value was sent to the Meta Pixel/CAPI.")
    print("\nVERDICT:")
    for v in verdict:
        print("  -", v)

    if args.dump:
        with open("lp_leads_sample.json", "w", encoding="utf-8") as f:
            json.dump(all_leads, f, indent=1)
        print("\nRaw sample written to lp_leads_sample.json (contains PII, handle accordingly).")


if __name__ == "__main__":
    main()
