---
kind: code
depends_on: []
---

# Proposal: woo-publish-decision-from-the-case

OpenSpec pass of 2026-09-27. Two rows in the opencatalogi matrix
(`ConductionNL/opencatalogi`, `openspec/parity/capabilities.json`) whose
`built.owner` is `ConductionNL/dossiq`. Both miss the same half, so one change
carries them.

| matrix | row | capability | own rating | built.state |
| --- | --- | --- | --- | --- |
| opencatalogi | `int-case-system` | Take documents for publication from a case management system. | partial | built |
| opencatalogi | `woo-from-case` | Publish a decision and its documents straight from the case that produced them. | partial | built |

## Why

A handler cannot publish a Woo decision from the case. The backend is there:
`POST /api/cases/{id}/woo/publish` (`appinfo/routes.php:820`,
`WOOAssessmentController::publishDecision`) runs
`WooPublicationService::publish()`, which creates the publication in
OpenCatalogi's register and attaches the disclosable documents. Withdraw is
there too (`/woo/withdraw`). Nothing on screen calls either.
`src/services/wooPublicationApi.js:32` `publishWooDecision` has no importer.
The view that did, `DocumentAssessmentPanel.vue`, was removed as unreachable in
#867, and the spec requirement "Publication status surfaced on the WOO
assessment view" still names it.

The opencatalogi matrix records it the same way. On `int-case-system`: "dossiq
lib/Service/WooPublicationService.php:244,352 sendPublicationToOpenCatalogi
creates the publication and attaches disclosable documents, reached by POST
/api/cases/{id}/woo/publish ... src/services/wooPublicationApi.js:32
publishWooDecision has no caller in src/". The note: "the pack credited
BesluitPublicatiePanel, but that panel publishes into dossiq's own register."

No demand row. Three competitors rate both rows yes, quoted from the matrix:

- xxllnc Publiceren, https://xxllnc.nl/applicaties/publiceren/: documents are
  collected "via een compatibel zaaksysteem / DMS (zoals xxllnc Zaken /
  SharePoint)"; FAQ "Flexibele integraties": "Ons platform kan werken met de
  ZGW-API".
- iprox.open, https://iprox.nl/marketing/521/interview-marc-ypeij-ws-limburg:
  "publiceren of updaten we met een druk op de knop Woo-dossiers vanuit het
  zaaksysteem naar ons platform"; and
  https://iprox.nl/marketing/477/blog-woo-publiceren-koppeling: "desgewenst
  rechtstreeks vanuit een intern systeem naar iprox.open publiceren".
- Decos JOIN Woo Portaal, https://decos.com/oplossingen/woo-portaal: "Direct
  gekoppeld aan JOIN Zaak & Document ... rechtstreekse aansluiting", and "Na
  beoordeling worden de relevante documenten via het portaal gepubliceerd, zo
  nodig na anonimisering via JOIN".

The `woo` area is in the core of the opencatalogi matrix (its first 30 rows).

## What changes

- The publish and withdraw endpoints find the case's Woo decision themselves
  when the caller sends no `decisionId`, and refuse with a reason when there is
  none or more than one.
- The case carries its publication state, `wooPublicationStatus` and
  `wooPublicationUrl`, written by the two services that change it.
- `#CaseDetail` gains two header actions, Publish (Woo) and Withdraw
  publication, gated on that state.
- The Data tab shows the state and a link to the publication.
- The `decision` schema declares the Woo fields its writers already send, so
  they are not lost on save.

## What this change does not do

- It does not move the Woo besluit to decidiq. That raise is BLOCKED-2 in
  `dossiq-decisions-to-decidiq` and stays where it is.
- It does not change what is published or how. `buildPayload`, the redaction
  rule and the in-process write (REQ-WPI-001 to REQ-WPI-004) stay as they are.
- It does not add a reading room. OpenCatalogi is the reading room.

## Sibling halves

None. OpenCatalogi's publication register and its in-process contract are
consumed as they are (`woo-publication-in-process-object-writes`, archived).

## Capabilities

- Modified: `woo-publication-via-opencatalogi`: the status requirement moves
  from the removed assessment view to the case page; two requirements are
  added for the decision lookup and the header actions.

## Impact

`lib/Controller/WOOAssessmentController.php`, `lib/Service/WooPublicationService.php`,
`lib/Service/WOODecisionService.php`, `lib/Settings/dossiq_register.json`
(`case` and `decision`), a repair step under `lib/Repair`, `src/manifest.json`
(`#CaseDetail` header actions and one Data tab field). No new route.

## Woo journey additions (2026-09-30)

The Woo citizen journey (hydra `woo-citizen-journey`, C6 and C3) adds three
things to this change: the publication carries `publicationKind`,
`wooCategory`, `caseReference` and `period`, with its documents as files
on the publication; a decision on a request started from a dossier is appended
to that dossier; and the resident is told through portaliq's change rule
`dossiq.wooRequest.published`. The request side is in
`woo-request-from-a-portal-dossier`.
