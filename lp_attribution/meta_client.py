"""Read-only Meta Marketing API (Graph API) client.

Auth: META_ACCESS_TOKEN in the environment. The token needs `ads_read`
on every ad account the leads come from. The lookups below are by object
id, so the ad account does not have to be named.

Optional: META_API_VERSION (default v25.0).
"""
from __future__ import annotations

import json
import os
import urllib.error
import urllib.parse
import urllib.request
from typing import Any

GRAPH = "https://graph.facebook.com"


class MetaError(RuntimeError):
    pass


def _token() -> str:
    tok = os.environ.get("META_ACCESS_TOKEN")
    if not tok:
        raise MetaError("META_ACCESS_TOKEN is not set in the environment.")
    return tok


def _version() -> str:
    return os.environ.get("META_API_VERSION", "v25.0")


def configured() -> bool:
    return bool(os.environ.get("META_ACCESS_TOKEN"))


def _get(path: str, params: dict[str, Any]) -> dict[str, Any]:
    clean = {k: v for k, v in params.items() if v is not None}
    clean["access_token"] = _token()
    url = f"{GRAPH}/{_version()}/{path.lstrip('/')}?{urllib.parse.urlencode(clean, doseq=True)}"
    req = urllib.request.Request(url, headers={"Accept": "application/json",
                                               "User-Agent": "lp-attribution/1.0"})
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return json.loads(r.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        body = e.read().decode("utf-8", "replace")
        try:
            err = json.loads(body).get("error", {})
            msg = f"{err.get('type')} ({err.get('code')}): {err.get('message')}"
        except Exception:  # noqa: BLE001
            msg = body[:300]
        raise MetaError(f"Meta {e.code} on /{path}: {msg}") from e


AD_FIELDS = ",".join([
    "id", "name", "status", "effective_status", "account_id", "created_time",
    "adset{id,name,status,optimization_goal,daily_budget,bid_strategy}",
    "campaign{id,name,objective,status}",
    "creative{id,name,title,body,thumbnail_url,object_story_spec,url_tags}",
    "tracking_specs",
])


def get_ad(ad_id: str) -> dict:
    return _get(ad_id, {"fields": AD_FIELDS})


def get_adset(adset_id: str) -> dict:
    return _get(adset_id, {"fields": "id,name,status,campaign{id,name},optimization_goal,daily_budget"})


def get_campaign(campaign_id: str) -> dict:
    return _get(campaign_id, {"fields": "id,name,status,objective,account_id"})


INSIGHT_FIELDS = "spend,impressions,clicks,inline_link_clicks,cpc,cpm,actions,cost_per_action_type,reach,frequency"


def get_insights(object_id: str, *, since: str | None = None, until: str | None = None,
                 date_preset: str | None = None, breakdowns: str | None = None) -> list[dict]:
    """Aggregate insights for an ad / adset / campaign. Dates are YYYY-MM-DD."""
    params: dict[str, Any] = {"fields": INSIGHT_FIELDS, "breakdowns": breakdowns}
    if since or until:
        params["time_range"] = json.dumps({"since": since, "until": until or since})
    else:
        params["date_preset"] = date_preset or "last_14d"
    return _get(f"{object_id}/insights", params).get("data", [])


def lead_actions(insights_row: dict) -> dict[str, float]:
    """Pull the conversion action counts out of an insights row.

    The Lead Prosper trigger sends the lead to Meta as `CompleteRegistration`
    (see the trigger body on campaign 35954), so that is counted alongside the
    `Lead` family. Both are what "Meta-reported leads" means for this account."""
    out: dict[str, float] = {}
    for a in insights_row.get("actions", []) or []:
        t = a.get("action_type", "")
        if ("lead" in t or "complete_registration" in t
                or t in ("offsite_conversion.fb_pixel_lead", "onsite_conversion.lead_grouped",
                         "offsite_conversion.fb_pixel_complete_registration")):
            out[t] = float(a.get("value", 0))
    return out


def get_url_tags(ad_id: str) -> str | None:
    """The URL parameters template on the ad's creative, for macro audits."""
    ad = _get(ad_id, {"fields": "creative{url_tags,object_story_spec}"})
    cr = ad.get("creative") or {}
    if cr.get("url_tags"):
        return cr["url_tags"]
    spec = cr.get("object_story_spec") or {}
    for key in ("link_data", "video_data"):
        link = (spec.get(key) or {}).get("link") or (spec.get(key) or {}).get("call_to_action", {}).get("value", {}).get("link")
        if link:
            return link
    return None
