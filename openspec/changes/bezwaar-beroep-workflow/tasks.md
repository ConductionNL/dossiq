# Tasks: bezwaar-beroep-workflow

This change installs AWB-compliant bezwaar en beroep workflow configuration
(seed data + workflowTemplates) plus targeted code extensions for
hoorzitting scheduling, dossier compilation, and beroep dossier export.

## Artifact authoring (this change)

- [x] **T1** — Author `proposal.md` with demand evidence (1,070 requirements /
  ~280 tenders), scope (config + targeted code extensions), AWB compliance
  reviewe gates, and out-of-scope boundaries.
  - files: `proposal.md`

- [x] **T2** — Author `design.md` with domain framing (three-level case chain),
  AWB step sequence table, workflowTemplate design (steps + transitions +
  guards), entity usage table, seed data (3-5 Dutch examples per entity),
  declarative-vs-imperative classification, and risks.
  - files: `design.md`

- [x] **T3** — Author `specs/bezwaar-beroep-workflow/spec.md` with 11
  REQ-BBW-* requirements covering caseType seeds, AWB step order,
  primair besluit linking, deadline engine, ontvankelijkheidstoets,
  hoorzitting scheduling, commissieadvies, beslissing, dossier compilation,
  beroep case, and manifest navigation.
  - files: `specs/bezwaar-beroep-workflow/spec.md`
  - acceptance: 11 REQ-BBW-* requirements, each with ≥1 GIVEN/WHEN/THEN
    scenario; AWB article citations in each requirement

## Seed data implementation

- [ ] **T4** — Install `Bezwaarschrift behandeling` caseType seed with
  AWB-compliant fields: `processingDeadline: "P6W"`, `extensionAllowed: true`,
  `extensionPeriod: "P6W"`, `suspensionAllowed: true`, `publicationRequired: true`.
  - files: `lib/Settings/procest_register.json` (caseType entry)
  - acceptance: `GET /api/caseTypes?identifier=bezwaar` returns 1 record
    with all required AWB fields

- [ ] **T5** — Install `Beroepschrift behandeling` caseType seed with
  `processingDeadline: "P52W"`, `extensionAllowed: false`,
  `suspensionAllowed: false`.
  - files: `lib/Settings/procest_register.json` (caseType entry)
  - acceptance: `GET /api/caseTypes?identifier=beroep` returns 1 record

- [ ] **T6** — Install bezwaar statusType seeds: 7 ordered steps
  (Ontvangen through Bekendmaking) + 2 terminal statuses
  (Niet-ontvankelijk verklaard, Ingetrokken).
  - files: `lib/Settings/procest_register.json` (statusType entries)
  - acceptance: 9 statusType records exist linked to the bezwaar caseType;
    Bekendmaking has `isFinal: true`

- [ ] **T7** — Install beroep statusType seeds: 4 steps (Beroepschrift
  ontvangen, Verweerschrift opstellen, Zitting, Uitspraak).
  - files: `lib/Settings/procest_register.json` (statusType entries)
  - acceptance: 4 statusType records exist linked to the beroep caseType

- [ ] **T8** — Install roleType seeds for bezwaar (Bezwaarmaker,
  Vertegenwoordiger, Behandelaar, Commissievoorzitter, Commissielid)
  and beroep (Appellant, Verweerder, Procesgemachtigde).
  - files: `lib/Settings/procest_register.json` (roleType entries)
  - acceptance: 8 roleType records installed; each linked to the correct
    caseType

- [ ] **T9** — Install documentType seeds for bezwaar and beroep:
  Bezwaarschrift (required), Primair besluit kopie, Verweerschrift,
  Hoorzittingverslag, Advies commissie, Beslissing op bezwaar,
  Beroepschrift, Dossier export.
  - files: `lib/Settings/procest_register.json` (documentType entries)
  - acceptance: 8 documentType records installed; Bezwaarschrift has
    `isRequired: true`

- [ ] **T10** — Install decisionType seeds: Gegrond, Ongegrond,
  Niet-ontvankelijk, Gedeeltelijk gegrond — linked to bezwaar caseType.
  - files: `lib/Settings/procest_register.json` (decisionType entries)
  - acceptance: 4 decisionType records linked to bezwaar caseType

- [ ] **T11** — Install resultType seeds: Bezwaar gegrond (herroeping),
  Bezwaar ongegrond, Bezwaar niet-ontvankelijk, Beroep ingesteld —
  linked to bezwaar caseType with archival periods.
  - files: `lib/Settings/procest_register.json` (resultType entries)
  - acceptance: 4 resultType records with `archivalPeriod` set per
    gemeentelijke selectielijst (bezwaar: P10Y)

- [ ] **T12** — Install propertyDefinition seeds for AWB-specific fields:
  `verdagingReden` (text), `opschortingReden` (text),
  `opschortingStartDatum` (date), `opschortingEindDatum` (date),
  `proVoorzieningGevraagd` (boolean) — all linked to bezwaar caseType.
  - files: `lib/Settings/procest_register.json` (propertyDefinition entries)
  - acceptance: 5 propertyDefinition records linked to bezwaar caseType

## workflowTemplate implementation

- [ ] **T13** — Author bezwaar `workflowTemplate` JSON with 7 ordered steps,
  checklist items per step, and guard-annotated transitions.
  - files: `lib/Settings/bezwaar_workflow.json` (or inline in register seed)
  - acceptance: workflowTemplate activates on bezwaar caseType; all 7 steps
    present with correct statusType references; Niet-ontvankelijk and
    Ingetrokken terminal transitions declared

- [ ] **T14** — Declare ontvankelijkheidstoets guard in workflowTemplate:
  `objection.isTimely = true` required for Ontvankelijkheidstoets →
  Hoorzitting plannen; `isTimely = false` routes to Niet-ontvankelijk
  verklaard.
  - files: `lib/Settings/bezwaar_workflow.json`
  - acceptance: attempt to advance to Hoorzitting plannen with
    `isTimely = false` returns guard violation error

- [ ] **T15** — Declare hoorzitting guard in workflowTemplate:
  `hearingSession` record exists with `scheduledDate` set OR
  `hearingWaived = true` required for Hoorzitting → Advies commissie
  (or Beslissing op bezwaar when commissie track off).
  - files: `lib/Settings/bezwaar_workflow.json`
  - acceptance: attempt to advance without hearingSession or waiver
    returns "Hoorrecht (AWB art. 7:2) niet vervuld" guard violation

- [ ] **T16** — Declare commissieadvies skip path in workflowTemplate:
  when `caseType.referenceProcess.commissieTrack = false`, allow direct
  transition Hoorzitting → Beslissing op bezwaar without advisoryReport.
  - files: `lib/Settings/bezwaar_workflow.json`
  - acceptance: bezwaar case without commissie track advances from
    Hoorzitting to Beslissing op bezwaar without requiring advisoryReport

- [ ] **T17** — Declare workflowTemplate automatic actions:
  - Ontvangen entry: `createTask("Registreer bezwaarschrift", behandelaar)`
  - Ontvankelijkheidstoets → Hoorzitting plannen: `createTask("Plan hoorzitting")`
  - Beslissing op bezwaar entry: `createTask("Stel beslissing op bezwaar op")`
  - Bekendmaking entry: `setField(primairBesluitCase, note, "Bezwaar gegrond")`
    when `dispositionType = gegrond`
  - files: `lib/Settings/bezwaar_workflow.json`
  - acceptance: tasks are auto-created at the correct step transitions

- [ ] **T18** — Author beroep `workflowTemplate` JSON with 4 ordered steps
  (Beroepschrift ontvangen, Verweerschrift opstellen, Zitting, Uitspraak).
  - files: `lib/Settings/beroep_workflow.json`
  - acceptance: workflowTemplate activates on beroep caseType

## Code extensions (targeted — no parallel services)

- [ ] **T19** — Implement `BezwaarCreationHook`: on bezwaar case creation,
  read `contestedDecision` from request body, resolve the primair besluit
  case via the decision's `case` reference, and write the UUID into
  `case.relatedCases`. Create the `objection` record linking
  `case: <bezwaar-uuid>` and `contestedDecision: <decision-uuid>`.
  - files: `lib/Hooks/BezwaarCreationHook.php`
  - acceptance: after POST `/api/cases` with caseType bezwaar and
    `contestedDecision` set, `case.relatedCases` contains the primair
    besluit case UUID and an `objection` record exists

- [ ] **T20** — Implement `HoorzittingCalendarSync`: on `hearingSession`
  POST/PUT, create or update a Nextcloud Calendar event (using the
  Nextcloud Calendar API / ICS) and send email invitations to
  `hearingSession.invitees`. On sync failure, log to `case.auditTrail`
  but return 201/200 regardless.
  - files: `lib/Service/HoorzittingCalendarSync.php`
  - acceptance: hearingSession POST creates a calendar event; calendar
    API down → hearingSession still persisted; audit trail entry present

- [ ] **T21** — Implement `DossierCompiler`: given a bezwaar case UUID,
  collect `caseDocument` references from the bezwaar case AND the linked
  primair besluit case, order them per the AWB-conventional sequence
  (primair besluit → bezwaarschrift → verweerschrift → verslag →
  advies → beslissing), and return the ordered list as a read-only view.
  - files: `lib/Service/DossierCompiler.php`
  - acceptance: dossier view for a complete bezwaar case shows documents
    in correct order from both cases; no file copying occurs

- [ ] **T22** — Implement `BeroepDossierExport`: given a beroep case UUID,
  call `DossierCompiler` to gather inherited + beroep-specific documents,
  produce a downloadable ZIP with documents named `01-primair-besluit.pdf`,
  `02-bezwaarschrift.pdf`, etc. If docudesk is available, optionally
  produce a merged PDF via docudesk template; ZIP is always the baseline.
  - files: `lib/Service/BeroepDossierExport.php`,
    `lib/Controller/DossierExportController.php`
  - acceptance: GET `/api/cases/<beroep-uuid>/dossier-export` returns a
    ZIP download with all documents in AWB-conventional order

## Manifest navigation

- [ ] **T23** — Add `Juridisch > Bezwaar en beroep` section to
  `src/manifest.json` with:
  - `Bezwaarzaken` index page (filter: caseType bezwaar; columns:
    zaak-ID, bezwaarmaker, primair besluit, deadline, status)
  - `Beroepszaken` index page (filter: caseType beroep)
  - Bezwaar detail page with side panels: Bezwaarschrift, Hoorzitting,
    Commissie advies, Beslissing, Dossier
  - Beroep detail page with side panels: Procesdossier, Verweer, Uitspraak
  - files: `src/manifest.json`
  - acceptance: `/apps/procest/bezwaarzaken` renders via `CnIndexPage`
    with correct caseType filter; no custom Vue components authored for
    index/detail pages

## Reviewer verification (pre-merge)

- [ ] **T24** — Reviewer confirms no parallel workflow service classes
  exist: scan `lib/` for `BezwaarService`, `BezwaarWorkflow`,
  `BezwaarTermijnService`, `BeroepService`, `TermijnCalculator`,
  `OpschortingService`.
  - acceptance: zero matches in `lib/`

- [ ] **T25** — Reviewer confirms AWB article citations are present in
  each REQ-BBW requirement prose (art. 6:7, 7:2, 7:10, 7:11-7:12, 7:13,
  3:41).
  - acceptance: 11/11 requirements cite at least one AWB article

- [ ] **T26** — Reviewer confirms all 9 bezwaar statusType seeds and all
  4 beroep statusType seeds are present and linked to their caseTypes.
  - files: `lib/Settings/procest_register.json`
  - acceptance: 9 bezwaar statusTypes (incl. 2 terminal) + 4 beroep
    statusTypes installed

- [ ] **T27** — Reviewer confirms `hearingSession.hearingWaived` path is
  tested: a waived hearing without a calendar event advances the workflow
  to Advies commissie or Beslissing op bezwaar.
  - acceptance: integration test scenario passes for waiver path

- [ ] **T28** — Reviewer confirms manifest navigation entries use generic
  `CnIndexPage` and `CnDetailPage` renderers — no custom bezwaar-specific
  Vue page component authored.
  - acceptance: scan `src/views/` for `Bezwaar*.vue` or `Beroep*.vue`
    files; none found (panels are OR-rendered sub-entities)
