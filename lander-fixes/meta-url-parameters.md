# Meta ad URL parameters template

Paste into the ad's **URL parameters** field (Ads Manager → ad → Tracking →
URL parameters), **not** into the Website URL. Do not URL-encode it. One line.

The website URL stays the bare lander: `https://jgdebtrelief.com/` or
`https://chat.relief.unitedsettlement.org/`.

Meta appends `fbclid` itself. Never add `fbclid={{fbclid}}`.

## JG Debt Relief (offer 914)

affid 989:
```
oid=914&affid=989&source_id={{ad.id}}&fb_adid={{ad.id}}&utm_source=facebook&utm_medium=social&utm_campaign={{campaign.id}}&utm_term={{adset.id}}&utm_content={{ad.id}}&sub2={{campaign.name}}&sub3={{adset.name}}&sub4={{ad.name}}&ms_placement={{placement}}&ms_publisher={{site_source_name}}
```

affid 995:
```
oid=914&affid=995&source_id={{ad.id}}&fb_adid={{ad.id}}&utm_source=facebook&utm_medium=social&utm_campaign={{campaign.id}}&utm_term={{adset.id}}&utm_content={{ad.id}}&sub2={{campaign.name}}&sub3={{adset.name}}&sub4={{ad.name}}&ms_placement={{placement}}&ms_publisher={{site_source_name}}
```

affid 1024:
```
oid=914&affid=1024&source_id={{ad.id}}&fb_adid={{ad.id}}&utm_source=facebook&utm_medium=social&utm_campaign={{campaign.id}}&utm_term={{adset.id}}&utm_content={{ad.id}}&sub2={{campaign.name}}&sub3={{adset.name}}&sub4={{ad.name}}&ms_placement={{placement}}&ms_publisher={{site_source_name}}
```

## United Settlement chat (offer 855)

affid 989:
```
oid=855&affid=989&source_id={{ad.id}}&fb_adid={{ad.id}}&utm_source=facebook&utm_medium=social&utm_campaign={{campaign.id}}&utm_term={{adset.id}}&utm_content={{ad.id}}&sub2={{campaign.name}}&sub3={{adset.name}}&sub4={{ad.name}}&ms_placement={{placement}}&ms_publisher={{site_source_name}}
```

affid 995:
```
oid=855&affid=995&source_id={{ad.id}}&fb_adid={{ad.id}}&utm_source=facebook&utm_medium=social&utm_campaign={{campaign.id}}&utm_term={{adset.id}}&utm_content={{ad.id}}&sub2={{campaign.name}}&sub3={{adset.name}}&sub4={{ad.name}}&ms_placement={{placement}}&ms_publisher={{site_source_name}}
```

affid 1024:
```
oid=855&affid=1024&source_id={{ad.id}}&fb_adid={{ad.id}}&utm_source=facebook&utm_medium=social&utm_campaign={{campaign.id}}&utm_term={{adset.id}}&utm_content={{ad.id}}&sub2={{campaign.name}}&sub3={{adset.name}}&sub4={{ad.name}}&ms_placement={{placement}}&ms_publisher={{site_source_name}}
```

## Why these names

They match what the working JG ads already send and what the normalizer in
`lp_attribution/normalize.py` reads:

| Param | Meta macro | Lands in Lead Prosper as | Read by the tracer as |
|-------|-----------|--------------------------|-----------------------|
| `source_id`, `fb_adid`, `utm_content` | `{{ad.id}}` | same names | ad id (three copies on purpose) |
| `utm_term` | `{{adset.id}}` | `utm_term` | ad set id |
| `utm_campaign` | `{{campaign.id}}` | `utm_campaign` | campaign id |
| `sub2` | `{{campaign.name}}` | `lp_subid2` | campaign name |
| `sub3` | `{{adset.name}}` | `lp_subid3` | ad set name |
| `sub4` | `{{ad.name}}` | `lp_subid4` | ad name |
| `ms_placement` | `{{placement}}` | `ms_placement` | placement |
| `ms_publisher` | `{{site_source_name}}` | `ms_publisher` | fb / ig / an / msg |

Both landers also pass `source_id` and `sub1..sub5` to Everflow's `EF.click`,
so Everflow gets the same ad id and names without any Everflow change.

## Rolling it out

Ads Manager lets you edit URL parameters on many ads at once: select the ads,
Edit, Tracking, URL parameters. Editing URL parameters does not reset learning.
Do affid 1024 first, since it is the one carrying literal macros today.
