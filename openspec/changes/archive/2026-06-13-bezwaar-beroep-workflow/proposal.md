---
kind: config
depends_on:
  - workflow-engine-enhancement
chain: []
---

# Proposal: bezwaar-beroep-workflow

**Status:** proposed
**Scope:** procest
**Owner:** Conduction BV — Procest team

## Why

Dutch public administration handles roughly 1,070 documented requirements
and ~280 unique tender signals around bezwaar en beroep (Specter cluster
data: 801 requirements + 269 AWB-specific requirements). Representative
tenders from Werkorganisatie HLT Samen, Gemeente Beverwijk, Gemeente
Nissewaard and others all cite bezwaar/beroep dossier handling,
hearing scheduling, and automatic deadline tracking as core expectations
of a modern zaaksysteem.

Procest's workflow engine (`workflow-engine-enhancement`) ships a generic
process graph — but bezwaar en beroep is not generic. The Algemene wet
bestuursrecht (AWB) imposes:

- a non-negotiable 6-week (or 12-week with verdaging) beslissingstermijn;
- mandatory ontvankelijkheidstoets before any hearing is held;
- a right-to-be-heard (hoorplicht, AWB art. 7:2) that must be schedulable
  against Nextcloud Calendar with formal invitations;
- a bezwaarschriftencommissie advisory track (AWB art. 7:13) with a
  separate opinion document and deviation-from-advice reasoning;
- a rechtsmiddelenclausule in the beslissing op bezwaar informing the
  citizen of their beroep options.

Without pre-configured bezwaar/beroep workflows, every Procest customer
must hand-wire these legally mandated steps from scratch — creating
compliance risk and implementation effort that kills deal velocity.

This change installs:

1. **Seed configuration** — two caseType seeds (bezwaar, beroep) with
   their status lifecycles, role types, document types, decision types,
   result types, and custom property definitions.
2. **workflowTemplate** — a pre-wired AWB-compliant workflow for both
   zaaktypen, installed as active templates.
3. **Targeted code extensions** beyond the generic workflow engine:
   - Primair besluit linking (cross-case reference on creation)
   - Nextcloud Calendar integration for hoorzitting scheduling
   - Automated dossier compilation from original + bezwaar documents
   - Beroep dossier export (bundled PDF for court submission)

## What changes

### Seed configuration (kind: config)

| Seed object | Type | Description |
|---|---|---|
| `Bezwaarschrift behandeling` | caseType | AWB-compliant bezwaar case type with 6-week deadline |
| `Beroepschrift behandeling` | caseType | Administrative court appeal case type |
| 7 status types (bezwaar) | statusType | Ontvangen → Ontvankelijkheidstoets → Hoorzitting plannen → Hoorzitting → Advies commissie → Beslissing op bezwaar → Bekendmaking |
| 2 terminal statuses | statusType | Niet-ontvankelijk verklaard, Ingetrokken |
| 5 role types (bezwaar) | roleType | Bezwaarmaker, Vertegenwoordiger, Behandelaar, Commissievoorzitter, Commissielid |
| 3 role types (beroep) | roleType | Appellant, Verweerder, Procesgemachtigde |
| Document types | documentType | Bezwaarschrift, Primair besluit kopie, Verweerschrift, Hoorzittingverslag, Advies commissie, Beslissing op bezwaar, Beroepschrift, Dossier export |
| Decision types | decisionType | Gegrond, Ongegrond, Niet-ontvankelijk, Gedeeltelijk gegrond |
| Result types | resultType | Bezwaar gegrond (herroeping), Bezwaar ongegrond, Bezwaar niet-ontvankelijk, Beroep ingesteld |
| Property definitions | propertyDefinition | AWB-specifieke velden: verdagingReden, opschortingReden, opschortingStartDatum, opschortingEindDatum, proVoorzieningGevraagd |
| Bezwaar workflowTemplate | workflowTemplate | Active AWB workflow with 7 steps, guards, and automatic actions |
| Beroep workflowTemplate | workflowTemplate | Active beroep workflow with 4 steps |

### Code extensions (kind: code — follow-up chains)

The following extend beyond declarative seed data and land as targeted
code additions against the workflow engine's hook points:

- **Primair besluit linker**: on bezwaar case creation, cross-reference
  the contestedDecision field and link via `relatedCases` / `caseObject`.
- **Calendar integration**: `hearingSession` POST triggers a Nextcloud
  Calendar event with ICS invitations to all `invitees`.
- **Dossier compiler**: assembles `caseDocument` references from the
  original case and the bezwaar case into a single ordered dossier view.
- **Beroep dossier export**: bundles the compiled dossier as a
  downloadable ZIP (or merged PDF via docudesk) for court submission.

These four extensions are declared in this change's specs but implemented
in the code chain (`bezwaar-beroep-workflow-code`).

## Impact

- **Seeds installed:** 2 caseTypes, 9 statusTypes (bezwaar), 4 statusTypes
  (beroep), 8 roleTypes, 8 documentTypes, 4 decisionTypes, 4 resultTypes,
  5 propertyDefinitions, 2 workflowTemplates.
- **Code changed:** targeted extensions to the hoorzitting scheduling
  hook, dossier compilation service, and beroep export endpoint. No
  parallel workflow engine; no custom state machine service.
- **AWB compliance surface:** the 6-week deadline, verdaging, opschorting,
  hoorplicht, commissieadvies, and rechtsmiddelenclausule are all traceable
  to explicit workflowTemplate steps and guards.
- **No breaking changes** — existing case management, workflow engine,
  roles, and decisions continue unchanged. Bezwaar/beroep seeds sit beside
  them as additional caseType seeds.

## Out of scope

- Generic workflow engine (covered by `workflow-engine-enhancement`)
- Signalering/deadline alerts (covered by `signalering-widgets` — AWB
  deadlines are trackable via the deadline field; alerting is external)
- WOZ/tax-specific bezwaar processing (domain-specific to tax systems)
- Automatic propagation of bezwaar outcome to WOZ objects (domain-specific)
- Generic calendar integration beyond hoorzitting (separate calendar
  integration change if needed)
- Legal cost calculation (procordia)

## Reviewer gates this change should pass

- ADR-022: no parallel workflow engine — workflowTemplate is the
  single mechanism; no `BezwaarService::transition()` in procest `lib/`.
- ADR-031: all status lifecycles declared as `x-openregister-lifecycle`
  on the caseType seed; no `BezwaarTermijnService` class.
- ADR-024: each bezwaar/beroep entry in `src/manifest.json` per the
  manifest navigation requirement (REQ-BBW-011).
- AWB art. 7:2 hoorrecht: `hearingSession` required before
  Beslissing op bezwaar unless waived — enforced as workflow guard.
- AWB art. 7:10 termijn: `processingDeadline: "P6W"` on bezwaar
  caseType; `extensionPeriod: "P6W"` for verdaging; `suspensionAllowed`
  for opschorting.
- AWB art. 7:12 motiveringsplicht: `appealDecision.dispositionDetails`
  is a required field.
- AWB art. 7:13 commissieadvies: `advisoryReport` entity is the
  single record for committee advice; no separate advisory database.
