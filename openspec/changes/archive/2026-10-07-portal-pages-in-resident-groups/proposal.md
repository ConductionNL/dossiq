# Portal pages in resident groups, and a decision notice that says what happened

## Why

Woo round 3 (hydra woo-citizen-journey, Ruben 2026-10-02) found three things on the resident site:

- The site menu put dossiq's pages under the heading "Dossiq", an app name a resident does not know.
- Two of those pages, "Mijn zaken" and "Berichten", carry the same names as the site's own case list and inbox,
  so the menu showed each name twice.
- When the decision on a Woo request was published, the resident read "<case> is bijgewerkt". That is portaliq's
  generic change notice, fired by a change rule on `wooPublicationUrl`. It does not say a decision was published,
  and it does not link to the publication.

## What changes

- Every dossiq portal page is declared, with a `group` (portaliq's group contract): "Mijn zaken en verzoeken" for
  residents, "Opdrachten en facturen" for suppliers, "Inspecties" for inspectors. The blocks are the ones portaliq
  made when no page was declared, so the screens stay the same.
- The resident's case page is named "Voortgang van uw zaken" and the reply page "Een bericht beantwoorden". Both
  stay: opening a case from the site's case list or a notice link needs a page that shows `mijnZaken`, and only the
  reply page offers the reply.
- dossiq writes the decision notice itself (`WooDecisionNotice`), once, on the first publish: Dutch subject
  "Het besluit op uw Woo-verzoek is gepubliceerd", a body with an absolute link to the publication page of the
  site, rule key `dossiq.wooRequest.published` and a link back to the case. The change rule goes, so portaliq writes
  no generic notice for the same publish. This supersedes design D-8 of `woo-publish-decision-from-the-case`
  ("dossiq writes no portalMessage"): portaliq now sends the e-mail for another app's message with a declared key
  (portaliq woo-journey-entry-points REQ-WJE-006).

## Impact

- `lib/Portal/PortalContributionProvider.php`, `lib/Woo/WooDecisionNotice.php` (new),
  `lib/Service/WooPublicationService.php`, l10n.
- No register change.
