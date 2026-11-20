"""Thin read-only client for the Lead Prosper API 2.0.

Auth: LP_API_KEY in the environment, sent as a bearer header.
Docs: https://api.leadprosper.io/public/docs/openapi-v2.json
"""
from __future__ import annotations

import json
import os
import urllib.error
import urllib.parse
import urllib.request
from typing import Any

BASE = "https://api.leadprosper.io/public"


class LeadProsperError(RuntimeError):
    pass


def _token() -> str:
    tok = os.environ.get("LP_API_KEY")
    if not tok:
        raise LeadProsperError("LP_API_KEY is not set in the environment.")
    return tok


def _get(path: str, params: dict[str, Any]) -> dict[str, Any]:
    clean = {k: v for k, v in params.items() if v is not None}
    url = f"{BASE}{path}?{urllib.parse.urlencode(clean, doseq=True)}"
    req = urllib.request.Request(url, headers={
        "Authorization": f"Bearer {_token()}",
        "Accept": "application/json",
        "User-Agent": "lp-attribution/1.0",
    })
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return json.loads(r.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        body = e.read().decode("utf-8", "replace")[:500]
        raise LeadProsperError(f"Lead Prosper {e.code} on {path}: {body}") from e


def list_campaigns(view: str = "short", name_contains: str | None = None) -> list[dict]:
    out, search_after = [], None
    while True:
        resp = _get("/v2/campaigns", {"view": view, "limit": 100,
                                      "name_contains": name_contains,
                                      "search_after": search_after})
        data = resp.get("data", resp)
        out.extend(data.get("campaigns", []))
        search_after = data.get("search_after")
        if not search_after:
            break
    return out


def get_lead(lead_id: str) -> dict:
    resp = _get(f"/v2/lead/{lead_id}", {})
    return resp.get("data", resp)


def search_leads(campaign: int, start_date: str, end_date: str, *,
                 limit: int = 100, where: dict[str, str] | None = None,
                 supplier: int | None = None, buyer: int | None = None,
                 status: str | None = None, buyer_outcome: str | None = None,
                 test: bool | None = False) -> list[dict]:
    """Newest-first leads for one campaign within a date window.

    `where` maps a lead_data field to the one exact value it must have
    (e.g. {"affid": "989"}). The API allows one value per field, so call
    once per value when you need several.
    """
    leads: list[dict] = []
    search_after = None
    while len(leads) < limit:
        params: dict[str, Any] = {
            "campaign": campaign,
            "start_date": start_date,
            "end_date": end_date,
            "limit": min(500, limit - len(leads)),
            "supplier": supplier,
            "buyer": buyer,
            "status": status,
            "buyer_outcome": buyer_outcome,
            "search_after": search_after,
        }
        if test is not None and buyer is None:
            params["test"] = "true" if test else "false"
        if where:
            params["where[match]"] = "all"
            for field, value in where.items():
                params[f"where[conditions][{field}]"] = value
        resp = _get("/v2/leads", params)
        data = resp.get("data", resp)
        page = data.get("leads", [])
        leads.extend(page)
        search_after = data.get("search_after")
        if not page or not search_after:
            break
    return leads[:limit]


def list_triggers(campaign: int) -> list[dict]:
    """Real-time triggers on a campaign with their actions (type, url, method).
    Payloads/field mappings are never published by the API; only the destination."""
    out, search_after = [], None
    while True:
        resp = _get(f"/v2/campaigns/{campaign}/triggers", {"limit": 250, "search_after": search_after})
        data = resp.get("data", resp)
        out.extend(data.get("triggers", []))
        search_after = data.get("search_after")
        if not search_after:
            break
    return out


def find_meta_capi_actions(campaign_ids: list[int] | None = None) -> list[dict]:
    """Scan campaigns for http trigger actions that post to Meta's Conversions API
    and extract the pixel id from the URL (…/vXX.X/<pixel_id>/events)."""
    import re
    camps = list_campaigns("short")
    if campaign_ids:
        camps = [c for c in camps if c["id"] in set(campaign_ids)]
    hits = []
    for c in camps:
        try:
            trigs = list_triggers(c["id"])
        except LeadProsperError as e:
            hits.append({"campaign_id": c["id"], "campaign_name": c.get("name"), "error": str(e)})
            continue
        for t in trigs:
            for a in t.get("actions", []):
                url = a.get("url") or ""
                if a.get("type") == "http" and re.search(r"facebook\.com|fb\.com", url):
                    m = re.search(r"/(\d{12,20})/events", url)
                    hits.append({
                        "campaign_id": c["id"], "campaign_name": c.get("name"),
                        "trigger_id": t.get("id"), "trigger_name": t.get("name"),
                        "trigger_paused": t.get("paused"),
                        "action_id": a.get("id"), "action_name": a.get("name"),
                        "action_paused": a.get("paused"), "method": a.get("method"),
                        "url": re.sub(r"access_token=[^&]+", "access_token=REDACTED", url),
                        "pixel_id": m.group(1) if m else None,
                    })
    return hits


def search_leads_all_campaigns(start_date: str, end_date: str, *, per_campaign: int = 100,
                               where: dict[str, str] | None = None,
                               campaign_ids: list[int] | None = None) -> list[dict]:
    camps = list_campaigns("short")
    if campaign_ids:
        camps = [c for c in camps if c["id"] in set(campaign_ids)]
    out: list[dict] = []
    for c in camps:
        out.extend(search_leads(c["id"], start_date, end_date, limit=per_campaign, where=where))
    return out
