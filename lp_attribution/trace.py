"""Shared trace logic used by both the CLI and the MCP server.

    trace_lead(lead_id)            -> one lead, normalized, plus Meta ad details if a token is set
    trace_recent(...)              -> many leads, normalized, grouped by ad with Meta insights
    attribution_health(...)        -> issue counts per affid / lander, for the reconciliation report
"""
from __future__ import annotations

from collections import Counter, defaultdict
from datetime import datetime, timedelta, timezone
from typing import Any

from . import lp_client, meta_client
from .normalize import Attribution, normalize, summarize_issues


def _window(days: int) -> tuple[str, str]:
    end = datetime.now(timezone.utc)
    start = end - timedelta(days=days)
    return start.strftime("%Y-%m-%d %H:%M:%S"), end.strftime("%Y-%m-%d %H:%M:%S")


def _meta_ad_details(ad_id: str, cache: dict[str, Any]) -> dict[str, Any]:
    if ad_id in cache:
        return cache[ad_id]
    if not meta_client.configured():
        cache[ad_id] = {"error": "META_ACCESS_TOKEN not set; Meta lookup skipped."}
        return cache[ad_id]
    try:
        ad = meta_client.get_ad(ad_id)
        cr = ad.get("creative") or {}
        cache[ad_id] = {
            "ad_id": ad.get("id"), "ad_name": ad.get("name"),
            "status": ad.get("effective_status") or ad.get("status"),
            "account_id": ad.get("account_id"),
            "adset": ad.get("adset"), "campaign": ad.get("campaign"),
            "creative": {"id": cr.get("id"), "name": cr.get("name"), "title": cr.get("title"),
                         "body": (cr.get("body") or "")[:200], "thumbnail_url": cr.get("thumbnail_url"),
                         "url_tags": cr.get("url_tags")},
        }
    except meta_client.MetaError as e:
        cache[ad_id] = {"error": str(e)}
    return cache[ad_id]


def trace_lead(lead_id: str, *, with_meta: bool = True, with_insights: bool = False) -> dict[str, Any]:
    lead = lp_client.get_lead(lead_id)
    a = normalize(lead)
    out: dict[str, Any] = {"attribution": a.to_dict()}
    if with_meta and a.meta_ad_id:
        out["meta_ad"] = _meta_ad_details(a.meta_ad_id, {})
        if with_insights and meta_client.configured() and a.lead_date_ms:
            day = datetime.fromtimestamp(a.lead_date_ms / 1000, tz=timezone.utc).strftime("%Y-%m-%d")
            try:
                rows = meta_client.get_insights(a.meta_ad_id, since=day, until=day)
                out["meta_insights_on_lead_day"] = rows[0] if rows else {}
            except meta_client.MetaError as e:
                out["meta_insights_on_lead_day"] = {"error": str(e)}
    out["explanation"] = explain(a)
    return out


def explain(a: Attribution) -> str:
    if a.attributable:
        parts = [f"Lead {a.lead_id} came from Meta ad {a.meta_ad_id}"]
        if a.meta_ad_name:
            parts.append(f'("{a.meta_ad_name}")')
        if a.meta_adset_id or a.meta_adset_name:
            parts.append(f"in ad set {a.meta_adset_id or ''} {a.meta_adset_name or ''}".rstrip())
        if a.meta_campaign_id or a.meta_campaign_name:
            parts.append(f"under campaign {a.meta_campaign_id or ''} {a.meta_campaign_name or ''}".rstrip())
        if a.placement:
            parts.append(f"on placement {a.placement}")
        s = " ".join(parts) + "."
        s += f" Ad id was read from field '{a.meta_id_source}'."
        if a.event_id:
            s += f" Meta event_id for Pixel/CAPI dedupe: {a.event_id}."
        else:
            s += " No event_id was stored, so this lead cannot be matched to a Pixel/CAPI event by id."
        if a.fbc_reconstructed:
            s += " The fbc value was rebuilt from fbclid and the lead timestamp."
        return s
    reasons = ", ".join(a.issues) or "no Meta identifiers on the lead"
    return (f"Lead {a.lead_id} cannot be attributed to a specific Meta ad. Reasons: {reasons}. "
            f"Lander: {a.landing_page_url or 'unknown'}; affid {a.affid or '?'}.")


def trace_recent(*, days: int = 7, affid: str | None = None, campaign_ids: list[int] | None = None,
                 per_campaign: int = 200, with_meta: bool = True, with_insights: bool = True,
                 insights_days: int | None = None) -> dict[str, Any]:
    start, end = _window(days)
    where = {"affid": affid} if affid else None
    leads = lp_client.search_leads_all_campaigns(start, end, per_campaign=per_campaign,
                                                 where=where, campaign_ids=campaign_ids)
    records = [normalize(l) for l in leads]

    by_ad: dict[str, dict[str, Any]] = defaultdict(lambda: {
        "leads": 0, "accepted": 0, "revenue": 0.0, "cost": 0.0, "lead_ids": [],
        "ad_name": None, "adset_name": None, "campaign_name": None, "placements": Counter()})
    unattributed = []
    for r in records:
        if not r.attributable:
            unattributed.append({"lead_id": r.lead_id, "affid": r.affid, "lander": r.landing_page_url,
                                 "issues": r.issues})
            continue
        g = by_ad[r.meta_ad_id]
        g["leads"] += 1
        g["accepted"] += 1 if (r.status or "").lower() == "accepted" else 0
        g["revenue"] += float(r.revenue or 0)
        g["cost"] += float(r.cost or 0)
        if len(g["lead_ids"]) < 25:
            g["lead_ids"].append(r.lead_id)
        g["ad_name"] = g["ad_name"] or r.meta_ad_name
        g["adset_name"] = g["adset_name"] or r.meta_adset_name
        g["campaign_name"] = g["campaign_name"] or r.meta_campaign_name
        if r.placement:
            g["placements"][r.placement] += 1

    cache: dict[str, Any] = {}
    rows = []
    since = (datetime.now(timezone.utc) - timedelta(days=insights_days or days)).strftime("%Y-%m-%d")
    until = datetime.now(timezone.utc).strftime("%Y-%m-%d")
    for ad_id, g in sorted(by_ad.items(), key=lambda kv: -kv[1]["leads"]):
        row = {"meta_ad_id": ad_id, **{k: v for k, v in g.items() if k != "placements"},
               "placements": dict(g["placements"].most_common(5))}
        if with_meta:
            row["meta"] = _meta_ad_details(ad_id, cache)
            if with_insights and meta_client.configured() and "error" not in row["meta"]:
                try:
                    ins = meta_client.get_insights(ad_id, since=since, until=until)
                    if ins:
                        i = ins[0]
                        row["spend"] = float(i.get("spend", 0))
                        row["impressions"] = int(i.get("impressions", 0))
                        row["link_clicks"] = int(i.get("inline_link_clicks", 0) or 0)
                        row["meta_reported_leads"] = meta_client.lead_actions(i)
                        row["lp_cost_per_lead"] = round(row["spend"] / g["leads"], 2) if g["leads"] else None
                        row["lp_cost_per_accepted"] = (round(row["spend"] / g["accepted"], 2)
                                                       if g["accepted"] else None)
                except meta_client.MetaError as e:
                    row["insights_error"] = str(e)
        rows.append(row)

    return {
        "window_utc": {"start": start, "end": end},
        "filters": {"affid": affid, "campaign_ids": campaign_ids},
        "leads_total": len(records),
        "leads_attributed": len(records) - len(unattributed),
        "ads": rows,
        "unattributed": unattributed[:100],
        "issue_summary": summarize_issues(records),
    }


def attribution_health(*, days: int = 7, affids: list[str] | None = None,
                       campaign_ids: list[int] | None = None, per_campaign: int = 300) -> dict[str, Any]:
    start, end = _window(days)
    segments = affids or [None]
    report: dict[str, Any] = {"window_utc": {"start": start, "end": end}, "segments": []}
    for affid in segments:
        where = {"affid": affid} if affid else None
        leads = lp_client.search_leads_all_campaigns(start, end, per_campaign=per_campaign,
                                                     where=where, campaign_ids=campaign_ids)
        recs = [normalize(l) for l in leads]
        n = len(recs)
        by_lander: dict[str, Counter] = defaultdict(Counter)
        for r in recs:
            host = (r.landing_page_url or "unknown").split("?")[0]
            by_lander[host]["leads"] += 1
            by_lander[host]["attributable"] += 1 if r.attributable else 0
            by_lander[host]["has_event_id"] += 1 if r.event_id else 0
            by_lander[host]["has_click_id"] += 1 if (r.fbclid or r.fbc) else 0
            by_lander[host]["unresolved_macros"] += 1 if any(i.startswith("unresolved_macro") for i in r.issues) else 0
        report["segments"].append({
            "affid": affid, "leads": n,
            "attributable_pct": round(100 * sum(r.attributable for r in recs) / n, 1) if n else None,
            "event_id_pct": round(100 * sum(1 for r in recs if r.event_id) / n, 1) if n else None,
            "click_id_pct": round(100 * sum(1 for r in recs if r.fbclid or r.fbc) / n, 1) if n else None,
            "issues": summarize_issues(recs),
            "by_lander": {k: dict(v) for k, v in by_lander.items()},
        })
    return report
