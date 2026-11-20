# Marketing Partners page — publication review

`marketing-partners.php` is now linked from the phone-step contact consent in config.php. The words "marketing partners" open this page in a new tab with noopener/noreferrer, preserving the original form. The page has no tracking scripts and a noindex directive. These changes are local and have not been deployed. The publication-review banner and pending business-name labels remain intentional until review is complete.

## Evidence reviewed on October 21, 2026

| Brand | Business identity / relationship evidence | Remaining review |
| --- | --- | --- |
| JG Wentworth | JGW Debt Settlement, LLC, identified in JG's licenses and GLBA notice. | Confirm final caller identity and logo approval. |
| United Settlement | United Debt Settlement, LLC, identified by unitedsettlement.com/privacy-policy/. LeadProsper campaign 35954 lists United Debt - Under $10k, buyer 122730. | Confirm calling scope and approved brand/logo. Do not describe it as a JG-owned DBA. |
| DocuPop | LeadProsper campaign 35954 lists DocuPop, buyer 128655. Everflow advertiser 1038 is named Eleven X (Docupop), with offers 953 and 988. docupop.com terms/privacy identify the consumer brand but do not establish Eleven X's exact legal name or a registered DBA. | Confirm exact contracting entity, DBA relationship, caller identity, service description, and logo approval. Do not confuse it with unrelated eleven-x companies. |
| ScoreShuttle | Everflow advertiser 1039 is named FinnTekk, Inc (ScoreShuttle), with offers 958 and 989. scoreshuttle.com/privacy-policy/ identifies the consumer brand. | Obtain primary confirmation of exact business name/DBA, whether contact follows this site's submission or a separate opt-in, service description, and logo approval. |

Logos are existing repository assets, reused without alteration. Their presence in the repository is not proof of permission or current brand approval.

InCharge is omitted because config.php describes that route as temporarily disabled. National Debt Relief has an inbound offer in Everflow, but that alone does not establish that it calls these form leads; confirm whether it belongs on the final roster. Do not add traffic suppliers/approved publishers merely because they can run a JG offer.

Before publishing, confirm the complete calling roster with the responsible business owner and resolve the items above. Then replace tentative labels with confirmed legal names/DBAs, approve consumer copy, and remove the publication-review notice. Deployment, the shared directory and SOP changes have not been performed.

The contact consent now names "JG Wentworth and its marketing partners." submit.php already saves this same config value as consent_text, and includes/leadprosper.php passes it as tcpa_text; both therefore receive the updated copy without a second hard-coded version.

Follow-up verification: Everflow advertiser records 1038 and 1039 confirm the account labels Eleven X (Docupop) and FinnTekk, Inc (ScoreShuttle). Those labels alone do not prove an exact contracting entity or a registered DBA. No verification flags were cleared on that basis.

Sources:
- https://www.jgwentworth.com/licenses
- https://www.jgwentworth.com/wp-content/uploads/2024/05/JG-Wentworth-Consumer-Privacy-Notice-2024.pdf
- https://unitedsettlement.com/privacy-policy/
- https://www.docupop.com/privacy/
- https://www.docupop.com/terms/
- https://www.scoreshuttle.com/privacy-policy/
