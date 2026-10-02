---
kind: code
depends_on: [woo-request-from-a-portal-dossier]
---

# Proposal: site-woo-request-in-steps

Part of the portal-design programme (2026-10-02). Ruben approved `DossiqWoo.dc.html`: the Woo
request as a form in four steps, "Uw vraag", "Periode en documenten", "Uw gegevens" and
"Controleren en versturen", with "Opslaan en later verdergaan" and a confirmation. Source:
`~/memcap-work/portal-design/canvas/project/DossiqWoo.dc.html`.

## Why

A resident starts a Woo request today through `startWooVerzoek`, shipped by
`woo-request-from-a-portal-dossier` (REQ-PORTAL-020). It is one screen with five fields, and it
appears only on a dossier page (`attachTo` opencatalogi `collection`). Three problems follow.

- **No way in without a dossier.** A resident who wants documents about the trees on the
  Lindelaan has no dossier. The mockup starts the request from the home page and the overview.
  `WooRequestIntake::start()` already accepts a request without `collectionId`; only the
  portal offers no door to it.
- **One long screen asks everything at once.** The mockup splits the questions so a resident
  answers one thing at a time, sees what they gave so far, and checks it before sending. That
  is the pattern of Open Formulieren and of the NL Design System guidance for long forms.
- **Nothing to pick up later.** The mockup promises "Wij bewaren uw antwoorden 30 dagen".
  Nothing stores a half-filled Woo request.

## What changes

Builds on `woo-request-from-a-portal-dossier` and keeps its one creation path,
`WooRequestIntake::start()`, its route and its assertion check. Nothing here contradicts it.

- **The action declares its steps.** `startWooVerzoek` gains a `steps` list: each step has an
  id, a title, a hint and the fields it asks. The fourth step is a review of every answer with
  a "wijzigen" link per step. Portaliq renders the steps (`site-multi-step-forms`, written by
  the portaliq lane). Dossiq declares them; it renders nothing.
- **New fields, all optional on the server.** `documentSoorten` (one or more of besluiten,
  rapporten, correspondentie, alles), `toelichting`, and the requester details the Woo case
  type already declares as intake properties: `verzoekerNaam`, `verzoekerEmail`,
  `verzoekerType`. They join the whitelist in the action and in `PortalWooRequestController::FIELDS`,
  and `WooRequestForm` keeps them on `case.wooRequest`.
- **A door without a dossier.** A second action, `startWooVerzoekAlgemeen`, posts to the same
  route with the same steps and no `attachTo`. It is what the home page tile and the overview
  link start. The dossier variant keeps `attachTo` and `rowField`.
- **Save and resume is declared.** Both actions declare `draft: {retentionDays: 30}`. Portaliq
  stores the draft against the signed-in resident and offers it back; dossiq never sees an
  unsent request. This follows portaliq's own "save and continue later" requirement
  (REQ-ICQ-005 in `intake-conditional-questions-and-drafts`), which stores drafts for bound
  intake forms the same way.
- **The confirmation names the case.** The route's 201 answer gains `identifier` (the case
  number) and `deadline`, so the confirmation can say "Uw zaaknummer is 2026-0003. U krijgt
  uiterlijk 30 oktober antwoord." The action declares that text with placeholders.

## What this change does not do

- It does not build the step form, the progress indicator, the review page, the draft store or
  the confirmation page. Those are portaliq's (`site-multi-step-forms`).
- It does not change how a request becomes a case, the case type, its statuses or its
  deadline.
- It does not let pipelinq's conversion use steps. Pipelinq calls `start()` directly.

## Not in this change

- The mockup's date input in three boxes (dag, maand, jaar). That is a portaliq input widget.
  Dossiq declares `periodeVan` and `periodeTot` as dates.
- Uploading a file with the request. The mockup does not ask for one.

## Capabilities

- Modified: `portal-contribution` (steps, the second action, draft, confirmation).
- Modified: `woo-request-intake` (the new optional fields, the richer 201 answer).

## Impact

`lib/Portal/PortalContributionProvider.php` (`startWooVerzoekAction()`, a second action),
`lib/Controller/PortalWooRequestController.php` (`FIELDS`), `lib/Woo/WooRequestForm.php`
(new fields), `lib/Woo/WooRequestIntake.php` (the answer, the intake property values),
`lib/Settings/register.d/81-woo-verzoek.json` (`case.wooRequest` properties), `l10n/`.
