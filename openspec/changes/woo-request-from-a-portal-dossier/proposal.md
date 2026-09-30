---
kind: code
depends_on: []
---

# Proposal: woo-request-from-a-portal-dossier

Part of the Woo citizen journey (hydra `openspec/changes/woo-citizen-journey`,
merged in hydra#725). dossiq owns contract C5 and the dossiq half of C6 and C3.
This change carries C5. The C6 and C3 halves finish the open change
`woo-publish-decision-from-the-case`.

## Why

A resident collects publications in a dossier on the portal and wants to ask
for more: the documents behind them. Today that is impossible.

- The Woo request case type ships as a template only
  (`lib/Settings/templates/woo-verzoek.json`, with a byte-identical duplicate
  `woo_verzoek.json`). Nothing seeds it, it has no result types although
  `woo-case-type` requires them, and it opens nothing to the portal.
- A resident cannot start any case from the portal. The citizen contribution
  offers a complaint, an objection, a reply and an amendment, and no create on
  `case`.
- pipelinq's employee converts a question into a Woo request (J4.6) and needs a
  dossiq service to call. There is none.

## What changes

- The Woo request case type is seeded with its eight statuses, four result
  types, its intake properties and portal windows, under the fixed identifier
  `woo-verzoek`. The duplicate template goes.
- `OCA\Dossiq\Woo\WooRequestIntake::start(array): array` is the one creation
  path (C5). It checks that the resident owns the dossier, opens a case of the
  Woo type stamped with the resident's `subjectRef`, adds one `caseObject` per
  dossier item pointing at the public publication, and appends the case to the
  dossier's `sourceOf`.
- The citizen contribution offers the endpoint action `startWooVerzoek` to
  `citizen` and `client`. portaliq forwards it to a new dossiq route with a
  signed `X-Portal-Subject` assertion; dossiq verifies it and calls the intake.

## Hydra requirements implemented

- "A Woo request MUST be created by one dossiq path, from the portal and from
  pipelinq alike" (C5).
- "A resident's dossier MUST be owned by the resident and readable by nobody
  else unless shared" (the ownership check, 404 on a mismatch).

## What this change does not do

- It does not build the portal form. portaliq renders the action on the
  dossier page (`woo-journey-entry-points`).
- It does not convert tickets. pipelinq calls `start()` with
  `origin: "pipelinq"` (`questions-about-a-citizen-dossier`).
- It does not publish. Publishing and the loop back to the dossier are in
  `woo-publish-decision-from-the-case`.

## Capabilities

- New: `woo-request-intake`.
- Modified: `portal-contribution` (one added action).

## Impact

`lib/Settings/register.d/81-woo-verzoek.json` (new), `lib/Settings/templates/woo_verzoek.json`
(removed), `lib/Woo/WooRequestIntake.php` (new), `lib/Portal/PortalAssertionVerifier.php`
(new), `lib/Controller/PortalWooRequestController.php` (new), `appinfo/routes.php`
(one route), `lib/Portal/PortalContributionProvider.php`, `lib/Service/SettingsService.php`
(two config keys), the register version.
