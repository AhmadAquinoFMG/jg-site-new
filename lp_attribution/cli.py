"""Command line front end.

    python -m lp_attribution.cli lead <LEAD_ID> [--insights]
    python -m lp_attribution.cli recent --days 7 --affid 989 [--no-meta] [--json]
    python -m lp_attribution.cli health --days 7 --affids 989,995,1024

Environment: LP_API_KEY (required), META_ACCESS_TOKEN (optional, enables Meta lookups).
"""
from __future__ import annotations

import argparse
import json
import sys

from . import trace


def _print_recent(res: dict) -> None:
    print(f"Window: {res['window_utc']['start']} -> {res['window_utc']['end']} UTC   filters={res['filters']}")
    print(f"Leads: {res['leads_total']}  attributed to a Meta ad: {res['leads_attributed']}")
    print()
    hdr = f"{'ad_id':>20} {'leads':>5} {'acc':>4} {'spend':>9} {'cpl':>8} {'meta_leads':>10}  ad / adset / campaign"
    print(hdr)
    print("-" * len(hdr))
    for r in res["ads"]:
        spend = f"{r.get('spend', ''):>9}" if "spend" in r else f"{'':>9}"
        cpl = f"{r.get('lp_cost_per_lead', ''):>8}" if "lp_cost_per_lead" in r else f"{'':>8}"
        ml = sum((r.get("meta_reported_leads") or {}).values()) if "meta_reported_leads" in r else ""
        meta = r.get("meta") or {}
        name = meta.get("ad_name") or r.get("ad_name") or ""
        adset = (meta.get("adset") or {}).get("name") or r.get("adset_name") or ""
        camp = (meta.get("campaign") or {}).get("name") or r.get("campaign_name") or ""
        print(f"{r['meta_ad_id']:>20} {r['leads']:>5} {r['accepted']:>4} {spend} {cpl} {str(ml):>10}  "
              f"{name[:40]} / {adset[:30]} / {camp[:30]}")
        if "error" in meta:
            print(f"{'':>20}   meta: {meta['error'][:120]}")
    if res["unattributed"]:
        print(f"\nUnattributed ({len(res['unattributed'])} shown):")
        for u in res["unattributed"][:20]:
            print(f"  {u['lead_id']}  affid={u['affid']}  {u['lander']}  {u['issues']}")
    print("\nIssue summary:", json.dumps(res["issue_summary"], indent=1))


def _print_health(res: dict) -> None:
    print(f"Window: {res['window_utc']['start']} -> {res['window_utc']['end']} UTC")
    for s in res["segments"]:
        print(f"\naffid={s['affid']}  leads={s['leads']}  attributable={s['attributable_pct']}%  "
              f"event_id={s['event_id_pct']}%  click_id={s['click_id_pct']}%")
        for lander, c in s["by_lander"].items():
            print(f"   {lander:55s} leads={c['leads']:>4} attributable={c['attributable']:>4} "
                  f"event_id={c['has_event_id']:>4} click={c['has_click_id']:>4} macros={c['unresolved_macros']:>3}")
        print("   issues:", s["issues"])


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(prog="lp_attribution")
    sub = ap.add_subparsers(dest="cmd", required=True)

    p = sub.add_parser("lead", help="trace one lead")
    p.add_argument("lead_id")
    p.add_argument("--insights", action="store_true", help="also pull Meta insights for the lead's day")
    p.add_argument("--no-meta", action="store_true")

    p = sub.add_parser("recent", help="group recent leads by Meta ad")
    p.add_argument("--days", type=int, default=7)
    p.add_argument("--affid")
    p.add_argument("--campaigns", help="comma-separated Lead Prosper campaign ids")
    p.add_argument("--per-campaign", type=int, default=200)
    p.add_argument("--no-meta", action="store_true")
    p.add_argument("--no-insights", action="store_true")
    p.add_argument("--json", action="store_true")

    p = sub.add_parser("health", help="attribution data-quality report")
    p.add_argument("--days", type=int, default=7)
    p.add_argument("--affids", help="comma-separated affid values")
    p.add_argument("--campaigns")
    p.add_argument("--json", action="store_true")

    args = ap.parse_args(argv)
    camps = [int(x) for x in args.campaigns.split(",")] if getattr(args, "campaigns", None) else None

    if args.cmd == "lead":
        res = trace.trace_lead(args.lead_id, with_meta=not args.no_meta, with_insights=args.insights)
        print(json.dumps(res, indent=1, default=str))
        print("\n" + res["explanation"])
    elif args.cmd == "recent":
        res = trace.trace_recent(days=args.days, affid=args.affid, campaign_ids=camps,
                                 per_campaign=args.per_campaign, with_meta=not args.no_meta,
                                 with_insights=not args.no_insights)
        print(json.dumps(res, indent=1, default=str)) if args.json else _print_recent(res)
    elif args.cmd == "health":
        affids = [x.strip() for x in args.affids.split(",")] if args.affids else None
        res = trace.attribution_health(days=args.days, affids=affids, campaign_ids=camps)
        print(json.dumps(res, indent=1, default=str)) if args.json else _print_health(res)
    return 0


if __name__ == "__main__":
    from .lp_client import LeadProsperError
    from .meta_client import MetaError
    try:
        sys.exit(main())
    except (LeadProsperError, MetaError) as e:
        print(f"error: {e}", file=sys.stderr)
        sys.exit(2)
