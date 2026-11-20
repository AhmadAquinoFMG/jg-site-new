"""MCP server: Lead Prosper -> Meta ad attribution.

Runs over stdio. Register it in Claude Desktop / Claude Code with:

    {
      "mcpServers": {
        "lp-attribution": {
          "command": "python",
          "args": ["-m", "lp_attribution.mcp_server"],
          "cwd": "<folder that contains the lp_attribution package>",
          "env": {"LP_API_KEY": "...", "META_ACCESS_TOKEN": "..."}
        }
      }
    }

All tools are read-only. Meta's own hosted Ads MCP can stay connected
alongside this one; this server only does the Lead Prosper <-> Meta join.
"""
from __future__ import annotations

import json
from typing import Any

try:  # mcp SDK 2.x
    from mcp.server.mcpserver import MCPServer as _Server
except ImportError:  # mcp SDK 1.x
    from mcp.server.fastmcp import FastMCP as _Server

from . import lp_client, meta_client, trace
from .normalize import normalize

mcp = _Server(
    "lp-attribution",
    instructions=(
        "Trace Lead Prosper leads back to the Meta (Facebook/Instagram) ad that produced them. "
        "Leads carry the Meta ad/adset/campaign ids and the Pixel event_id as lead_data fields; "
        "this server normalizes them and joins to the Meta Marketing API. Meta never exposes "
        "per-lead attribution itself, so every answer here is deterministic from the click "
        "parameters captured on the landing page. Use trace_lead for one lead, leads_by_ad for a "
        "period rollup, attribution_health to find tracking gaps, and meta_ad / meta_insights for "
        "ad details."
    ),
)


def _dump(obj: Any) -> str:
    return json.dumps(obj, indent=1, default=str)


@mcp.tool()
def trace_lead(lead_id: str, include_insights: bool = False) -> str:
    """Trace one Lead Prosper lead to its Meta ad.

    Returns the normalized attribution (ad/adset/campaign ids and names, placement,
    fbclid/fbc/fbp, event_id, Everflow affid/oid/transaction id), the Meta ad's details
    if META_ACCESS_TOKEN is set, a plain-English explanation, and a list of issues
    explaining any gap (unresolved macros, missing event_id, missing click id...).
    """
    return _dump(trace.trace_lead(lead_id, with_meta=True, with_insights=include_insights))


@mcp.tool()
def leads_by_ad(days: int = 7, affid: str | None = None, campaign_ids: str | None = None,
                per_campaign: int = 200, include_insights: bool = True) -> str:
    """Group recent Lead Prosper leads by the Meta ad that produced them.

    For each ad: lead count, accepted count, Lead Prosper cost/revenue, placements,
    sample lead ids, and (with a Meta token) spend, impressions, link clicks, Meta's own
    reported lead count, and true cost per Lead Prosper lead / per accepted lead.
    Also lists leads that could not be attributed and why.

    Args:
        days: look-back window in days (default 7).
        affid: optional Everflow affiliate id to filter on, e.g. "989".
        campaign_ids: optional comma-separated Lead Prosper campaign ids.
        per_campaign: max leads to pull per Lead Prosper campaign (default 200, max 500 per page).
        include_insights: pull Meta spend/insights per ad (slower; one Meta call per ad).
    """
    camps = [int(x) for x in campaign_ids.split(",")] if campaign_ids else None
    return _dump(trace.trace_recent(days=days, affid=affid, campaign_ids=camps,
                                    per_campaign=min(per_campaign, 500),
                                    with_meta=True, with_insights=include_insights))


@mcp.tool()
def attribution_health(days: int = 7, affids: str | None = None, campaign_ids: str | None = None) -> str:
    """Data-quality report: how much of recent traffic can actually be traced to a Meta ad.

    Per affid and per landing page: share of leads with an ad id, with an event_id, with a
    click id (fbclid/fbc), and counts of leads carrying unresolved {{macro}} strings.
    Use this to find landers or ads that are dropping tracking parameters.

    Args:
        days: look-back window in days.
        affids: comma-separated Everflow affiliate ids, e.g. "989,995,1024". Omit for all traffic.
        campaign_ids: optional comma-separated Lead Prosper campaign ids.
    """
    camps = [int(x) for x in campaign_ids.split(",")] if campaign_ids else None
    aff = [x.strip() for x in affids.split(",")] if affids else None
    return _dump(trace.attribution_health(days=days, affids=aff, campaign_ids=camps))


@mcp.tool()
def get_lead_raw(lead_id: str) -> str:
    """Return one Lead Prosper lead exactly as the API gives it, plus the normalized view.
    Useful when a trace says a lead is unattributable and you want to see every field."""
    lead = lp_client.get_lead(lead_id)
    return _dump({"raw": lead, "normalized": normalize(lead).to_dict()})


@mcp.tool()
def find_leads(days: int = 7, field: str | None = None, value: str | None = None,
               campaign_ids: str | None = None, limit: int = 50) -> str:
    """Find Lead Prosper leads by an exact lead_data field value, e.g. field="event_id"
    value="33bf51eb-..." or field="fb_adid" value="120251099977600593" or field="fbclid".
    Matching is exact and case-insensitive. Returns normalized attribution for each hit.

    Args:
        days: look-back window.
        field: lead_data field name to match.
        value: exact value it must have.
        campaign_ids: optional comma-separated Lead Prosper campaign ids to search.
        limit: max leads per campaign.
    """
    start, end = trace._window(days)  # noqa: SLF001
    camps = [int(x) for x in campaign_ids.split(",")] if campaign_ids else None
    where = {field: value} if field and value else None
    leads = lp_client.search_leads_all_campaigns(start, end, per_campaign=limit, where=where,
                                                 campaign_ids=camps)
    return _dump([normalize(l).to_dict() for l in leads])


@mcp.tool()
def list_lp_campaigns(name_contains: str | None = None) -> str:
    """List Lead Prosper campaigns (id, name, type). Optionally filter by name substring."""
    return _dump(lp_client.list_campaigns("short", name_contains=name_contains))


@mcp.tool()
def list_triggers(campaign_id: int) -> str:
    """Real-time Lead Prosper triggers on a campaign and their actions (http/email/
    google_sheets), with each http action's destination URL and method. This is how
    Meta Conversions API events are sent today: an http action posting to
    graph.facebook.com/<version>/<pixel_id>/events. The API never exposes the action's
    payload or field mapping, so whether it sends the lead's event_id must be checked in
    the Lead Prosper UI (campaign -> Triggers -> action -> body)."""
    return _dump(lp_client.list_triggers(campaign_id))


@mcp.tool()
def meta_capi_triggers(campaign_ids: str | None = None) -> str:
    """Scan all (or the given comma-separated) Lead Prosper campaigns for trigger
    actions that post to Meta's Conversions API, and report campaign, trigger, action,
    paused state and the pixel id parsed from the URL. Use it to confirm which
    campaigns actually send Leads to Meta and to which pixel, and to spot campaigns
    (or paused actions) that send nothing."""
    camps = [int(x) for x in campaign_ids.split(",")] if campaign_ids else None
    return _dump(lp_client.find_meta_capi_actions(camps))


@mcp.tool()
def meta_ad(ad_id: str) -> str:
    """Meta ad details by ad id: name, status, account, ad set, campaign, creative
    (title/body/thumbnail) and the creative's URL parameter template (url_tags), which
    shows whether the {{ad.id}} style macros are configured correctly."""
    if not meta_client.configured():
        return _dump({"error": "META_ACCESS_TOKEN not set."})
    try:
        return _dump(meta_client.get_ad(ad_id))
    except meta_client.MetaError as e:
        return _dump({"error": str(e)})


@mcp.tool()
def meta_insights(object_id: str, since: str | None = None, until: str | None = None,
                  date_preset: str = "last_14d", breakdowns: str | None = None) -> str:
    """Aggregate Meta insights for an ad, ad set or campaign id: spend, impressions, clicks,
    CPC/CPM, reach, frequency and action counts (including Meta's own reported leads).
    Dates are YYYY-MM-DD; omit them to use date_preset. breakdowns e.g.
    "publisher_platform,platform_position" for placement-level numbers."""
    if not meta_client.configured():
        return _dump({"error": "META_ACCESS_TOKEN not set."})
    try:
        return _dump(meta_client.get_insights(object_id, since=since, until=until,
                                              date_preset=date_preset, breakdowns=breakdowns))
    except meta_client.MetaError as e:
        return _dump({"error": str(e)})


def main() -> None:
    mcp.run()


if __name__ == "__main__":
    main()
