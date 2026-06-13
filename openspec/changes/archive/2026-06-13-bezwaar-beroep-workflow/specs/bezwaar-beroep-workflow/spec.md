# Spec: bezwaar-beroep-workflow

**Status:** proposed
**Scope:** procest
**Tier:** bezwaar-beroep
**Depends on:** workflow-engine-enhancement (REQUIRED), case-management, case-types, roles-decisions, deelzaak-support (RECOMMENDED), openregister (lifecycle + audit + retention per ADR-022), Nextcloud Calendar (hoorzitting invitations), Nextcloud Files (dossier documents), docudesk (beroep dossier PDF export, optional)

## ADDED Requirements

---

### Requirement: REQ-BBW-001 Bezwaar caseType seed SHALL be installed with AWB-compliant process configuration

A seeded `caseType` with identifier `bezwaar` (title: "Bezwaarschrift behandeling", Schema.org `schema:Project`) MUST be present after installation. Its configuration MUST declare the following per the AWB:

| Field | Value | Legal basis |
|---|---|---|
| `processingDeadline` | `"P6W"` | AWB art. 7:10 lid 1 |
| `extensionAllowed` | `true` | AWB art. 7:10 lid 3 (verdaging) |
| `extensionPeriod` | `"P6W"` | AWB art. 7:10 lid 3 |
| `suspensionAllowed` | `true` | AWB art. 7:10 lid 4-6 (opschorting) |
| `publicationRequired` | `true` | AWB art. 3:41 (bekendmaking) |
| `internalOrExternal` | `"extern"` | Citizen-initiated |
| `origin` | `"indienen"` | Bezwaarmaker files the objection |

The `beroep` caseType (identifier: `beroep`, title: "Beroepschrift
behandeling") MUST also be seeded with `processingDeadline: "P52W"`
(no fixed statutory deadline in AWB — 52 weeks is a reasonable tracking
period for court proceedings).

Both caseTypes MUST be associated with their respective `workflowTemplate`
via `caseType.workflowTemplate` reference.

#### Scenario: Bezwaar caseType is present and correctly configured after installation

- **GIVEN** the bezwaar-beroep-workflow change is installed
- **WHEN** an administrator queries `GET /api/caseTypes?identifier=bezwaar`
- **THEN** the response MUST contain exactly one record with
  `processingDeadline: "P6W"`, `extensionAllowed: true`,
  `suspensionAllowed: true`, and a linked `workflowTemplate`

#### Scenario: Reviewer confirms no parallel workflow engine

- **GIVEN** the procest codebase after this change
- **WHEN** scanned for classes named `BezwaarService`, `BezwaarWorkflow`,
  `BezwaarTermijnService`, or `BeroepService`
- **THEN** no such classes SHALL exist in `lib/`; all lifecycle is
  driven by the `workflowTemplate` configuration

---

### Requirement: REQ-BBW-002 Bezwaar workflow SHALL enforce AWB-mandated status step order and pre-conditions

The bezwaar `workflowTemplate` MUST declare 7 ordered process steps
matching the AWB procedure, plus 2 terminal status paths:

| Step | statusType | Required guard to advance | AWB basis |
|---|---|---|---|
| 1 | Ontvangen | objection record created | AWB art. 6:1 |
| 2 | Ontvankelijkheidstoets | ontvankelijkheidsbeoordeling checklist complete | AWB art. 6:6 |
| 3 | Hoorzitting plannen | `objection.isTimely = true` | AWB art. 7:2 |
| 4 | Hoorzitting | `hearingSession` record exists with `scheduledDate` set | AWB art. 7:2–7:9 |
| 5 | Advies commissie | `hearingSession.status = completed` OR `hearingWaived = true` | AWB art. 7:13 |
| 6 | Beslissing op bezwaar | `advisoryReport.adviceDate` set (commissie track) OR hearing waived without commissie | AWB art. 7:11-7:12 |
| 7 | Bekendmaking | `appealDecision.decisionDate` set | AWB art. 3:41 |
| — | Niet-ontvankelijk verklaard | `objection.isTimely = false` OR not a besluit | AWB art. 6:6 |
| — | Ingetrokken | bezwaarmaker withdraws (any non-terminal step) | — |

The Advies commissie step (5) MUST be skippable when no commissie is
configured: the `workflowTemplate` MUST support a direct transition from
Hoorzitting → Beslissing op bezwaar when `commissieTrack = false`.

All guards MUST be declared in the `workflowTemplate.transitions` JSON
using the standard guard types (`checklist`, `requiredField`,
`requiredDocument`, `roleGuard`). No imperative `PhpGuardService` class
is authored for lifecycle guards.

#### Scenario: Advancing to Hoorzitting plannen is blocked when objection is niet-ontvankelijk

- **GIVEN** a bezwaar case in status `Ontvankelijkheidstoets`
- **WHEN** the behandelaar sets `objection.isTimely = false`
  and attempts to advance the workflow
- **THEN** the transition to Hoorzitting plannen MUST be blocked; only
  the transition to `Niet-ontvankelijk verklaard` is available

#### Scenario: Advies commissie step is skipped when no commissie is configured

- **GIVEN** a bezwaar case where the caseType has `commissieTrack = false`
  in its `referenceProcess`
- **WHEN** the hoorzitting is marked as completed
- **THEN** the workflow MUST advance directly to `Beslissing op bezwaar`
  without requiring an `advisoryReport` record

#### Scenario: Beslissing op bezwaar requires hearing completion or valid waiver

- **GIVEN** a bezwaar case in status `Hoorzitting plannen` with no
  `hearingSession` record and no waiver registered
- **WHEN** the behandelaar attempts to advance to `Beslissing op bezwaar`
- **THEN** the transition MUST fail with a guard violation citing the
  hoorrecht (AWB art. 7:2)

---

### Requirement: REQ-BBW-003 Bezwaar case creation SHALL link to the primair besluit and record the formal objection

When a bezwaar case is created, the system MUST:

1. Accept a reference to the contested decision (primair besluit) —
   either as a procest `case` UUID (the original case) or a `decision`
   UUID within it.
2. Store the cross-reference via `case.relatedCases` (JSON-encoded array
   containing the primair besluit case UUID) AND via the `objection`
   entity's `contestedDecision` field (FK to the `decision` record).
3. Create an `objection` record linked to the bezwaar case capturing:
   `grounds`, `receivedDate`, `receivedChannel`, `requestedRelief`,
   `isTimely` (initial assessment), and `proVoorziening`.

The `objection` entity (Schema.org `schema:Message`) MUST be the single
record for the bezwaarschrift content. No free-text note on the case is
a substitute for the `objection` record.

No `PrimairBesluitLinkerService` class is authored; the cross-reference
is set by the `BezwaarCreationHook` (a targeted workflow engine hook,
not a parallel service) or via direct API call on case creation.

#### Scenario: Bezwaar case creation links to the original case

- **GIVEN** an existing case `2026-OGV-0117` with a `decision` record
  (omgevingsvergunning verleend)
- **WHEN** a bezwaar case is created with `contestedDecision: "<uuid-decision>"`
- **THEN** the new bezwaar case MUST have `relatedCases` containing the
  UUID of case `2026-OGV-0117`, AND an `objection` record MUST exist
  linking `case: <bezwaar-uuid>` and `contestedDecision: <uuid-decision>`

#### Scenario: Dossier view on the bezwaar case shows the original decision

- **GIVEN** a bezwaar case linked to primair besluit case `2026-OGV-0117`
- **WHEN** any user opens the bezwaar case detail page
- **THEN** the related cases panel MUST show `2026-OGV-0117` as the
  primair besluit case with its decision type and date

---

### Requirement: REQ-BBW-004 AWB 6-week beslissingstermijn SHALL be tracked declaratively with verdaging and opschorting support

The AWB decision deadline MUST be derived from the bezwaar caseType's
`processingDeadline: "P6W"` and must support:

- **Verdaging** (extension per AWB art. 7:10 lid 3): A single 6-week
  extension registered by the behandelaar with a mandatory reason. The
  `case.extensionCount` field tracks how many verdagingen have been
  applied. A second verdaging MUST trigger a workflow warning (AWB allows
  only one unilateral verdaging).
- **Opschorting** (suspension per AWB art. 7:10 lid 4-6): Pause the
  deadline while an opschorting condition exists. Opschorting start and
  end dates are recorded via `propertyDefinition` values on the case:
  `opschortingStartDatum`, `opschortingEindDatum`, `opschortingReden`.

The effective deadline displayed to the handler MUST be calculated as:
`startDate + P6W + (extensionCount × P6W) - opschortingDuration`.

Procest MUST NOT author an `AwbTermijnService` or
`OpschortingCalculator` class. Deadline display MUST use the `case.deadline`
field (updated declaratively by the workflow engine on each verdaging or
opschorting registration) and `x-openregister-calculations` for
the derived effective date.

#### Scenario: Verdaging extends the deadline by 6 weeks

- **GIVEN** a bezwaar case with `startDate: 2026-04-01` and no verdaging
  applied (deadline = 2026-05-13)
- **WHEN** the behandelaar registers a verdaging with reason "Complexe
  feitelijke situatie vereist nader onderzoek"
- **THEN** `case.extensionCount` MUST become 1 AND `case.deadline`
  MUST be recalculated to `2026-06-24` (13 May + 6 weeks)

#### Scenario: Opschorting pauses the deadline counter

- **GIVEN** a bezwaar case with deadline 2026-06-10 and opschorting
  registered from 2026-05-05 to 2026-05-19 (14 days)
- **WHEN** the opschorting ends on 2026-05-19
- **THEN** `case.deadline` MUST be updated to `2026-06-24` (original
  deadline + 14 suspended days)

#### Scenario: Second verdaging triggers a warning

- **GIVEN** a bezwaar case where `extensionCount = 1` (one verdaging
  already applied)
- **WHEN** the behandelaar attempts to register a second verdaging
- **THEN** the system MUST display a warning: "Een tweede verdaging is
  alleen toegestaan met instemming van de bezwaarmaker (AWB art. 7:10
  lid 3)" and MUST require explicit confirmation before proceeding

---

### Requirement: REQ-BBW-005 Ontvankelijkheidstoets SHALL assess admissibility and gate the hearing step

The ontvankelijkheidstoets is a mandatory pre-hearing assessment. The bezwaar workflowTemplate step 2 (Ontvankelijkheidstoets) MUST include a checklist enforcing the following AWB criteria:

- [ ] Bezwaar tijdig ingediend (AWB art. 6:7 — 6 weken na bekendmaking)
- [ ] Bezwaar ingediend door een belanghebbende (AWB art. 1:2)
- [ ] Bezwaar gericht tegen een besluit in de zin van AWB art. 1:3
- [ ] Bezwaarschrift voldoet aan de vereisten (AWB art. 6:5)

The outcome is captured in `objection.isTimely` (boolean) and
`objection.timelinessAssessment` (text). The workflow engine MUST
enforce the following gates on transition:

- `isTimely = true` AND all checklist items checked → advance to
  Hoorzitting plannen
- `isTimely = false` → only terminal transition Niet-ontvankelijk
  verklaard available; no path to Hoorzitting

The `objection.isTimely` field MUST be set by the behandelaar, not
calculated automatically, because pro-forma filings and special
circumstances (verschoonbare termijnoverschrijding) require human
judgement.

#### Scenario: Timely objection advances to hearing planning

- **GIVEN** a bezwaar case in status Ontvankelijkheidstoets with all
  checklist items checked and `objection.isTimely = true`
- **WHEN** the behandelaar advances the workflow
- **THEN** the case status MUST transition to `Hoorzitting plannen`
  and a task `Plan hoorzitting` MUST be created for the Behandelaar role

#### Scenario: Late objection is declared niet-ontvankelijk without a hearing

- **GIVEN** a bezwaar case where `objection.isTimely = false`
- **WHEN** the behandelaar attempts to advance to `Hoorzitting plannen`
- **THEN** the transition MUST be blocked; advancing to
  `Niet-ontvankelijk verklaard` MUST be the only available path;
  a task `Stel beslissing niet-ontvankelijk op` MUST be created

---

### Requirement: REQ-BBW-006 Hoorzitting SHALL be scheduled via Nextcloud Calendar with formal participant invitations

When a `hearingSession` record is created for a bezwaar case, the system MUST:

1. Create a Nextcloud Calendar event in the configured bezwaar agenda
   with title, date/time, location (physical or `videoCallUrl`), and
   description.
2. Send ICS invitation emails to all parties in `hearingSession.invitees`
   (bezwaarmaker, vertegenwoordiger, commissieleden, behandelaar).
3. Reflect RSVP responses in `invitees[*].status` (uitgenodigd →
   bevestigd / afgewezen).

The `hearingSession` entity (Schema.org `schema:Event`) is the canonical
record. The Nextcloud Calendar event is a transport convenience, not the
authoritative record. Calendar sync failure MUST be logged in the case
audit trail but MUST NOT prevent the `hearingSession` record from being
created.

Online hearings MUST set `hearingSession.videoCallUrl`. Physical hearings
MUST set `hearingSession.location`.

If the bezwaarmaker has waived the right to be heard (AWB art. 7:3),
the behandelaar MAY set `hearingSession.hearingWaived = true` with a
`waiverReason` instead of scheduling a hearing. The workflow engine MUST
accept a waived hearing as a valid completion of the Hoorzitting step.

#### Scenario: Calendar event is created on hearingSession creation

- **GIVEN** a bezwaar case in status `Hoorzitting plannen`
- **WHEN** a `hearingSession` POST is submitted with `scheduledDate`,
  `location`, and `invitees` containing at least the bezwaarmaker's email
- **THEN** a Nextcloud Calendar event MUST be created in the bezwaar
  agenda AND ICS invitation emails MUST be queued for all invitees;
  the `hearingSession` record MUST be created regardless of calendar sync
  result

#### Scenario: Hearing waiver is accepted as valid workflow completion

- **GIVEN** a bezwaar case in status `Hoorzitting plannen`
- **WHEN** the behandelaar creates a `hearingSession` record with
  `hearingWaived = true` and `waiverReason` set
- **THEN** the workflow MUST accept this as a completed hoorzitting and
  the transition to `Advies commissie` (or `Beslissing op bezwaar` if
  no commissie) MUST become available

#### Scenario: Calendar sync failure does not block the hearing record

- **GIVEN** the Nextcloud Calendar API is unreachable
- **WHEN** a hearingSession POST is submitted
- **THEN** the hearingSession record MUST be persisted in OR, an error
  MUST be logged in `case.auditTrail`, and the handler MUST receive a
  warning — but the API response MUST still return 201 Created

---

### Requirement: REQ-BBW-007 Bezwaarschriftencommissie advisory track SHALL produce an advisoryReport record per AWB art. 7:13

When a bezwaarschriftencommissie is installed (commissie track active), the Advies commissie step MUST result in an `advisoryReport` record
(Schema.org `schema:Report`) with the following required fields:

- `committeeChair` (UUID of the Commissievoorzitter role)
- `adviceDate` (date the advice was issued)
- `adviceType` (one of: `gegrond`, `ongegrond`, `gedeeltelijk_gegrond`,
  `niet_ontvankelijk`)
- `summary` (summary of the committee's reasoning)
- `grounds` (legal reasoning — motiveringsplicht)
- `recommendation` (recommended action for the bestuursorgaan)
- `deviationFromPrimaryDecision` (boolean — did the committee advise
  differently from the original decision)

When the `advisoryReport` deviates from the primair besluit AND the
bestuursorgaan does NOT follow the advice in the `appealDecision`, then
`appealDecision.deviationReason` is REQUIRED (AWB art. 7:13 lid 7:
deviation from committee advice must be reasoned).

The committee's `reportDocument` (full written advice) MUST be a
Nextcloud file referenced by URI, not stored in procest tables.

#### Scenario: Committee advice is recorded with all required fields

- **GIVEN** a bezwaar case in status `Advies commissie`
- **WHEN** an `advisoryReport` POST is submitted with `committeeChair`,
  `adviceDate`, `adviceType: "gegrond"`, `summary`, `grounds`,
  `recommendation`, and `deviationFromPrimaryDecision: true`
- **THEN** the `advisoryReport` record MUST be persisted, the workflow
  advance to `Beslissing op bezwaar` MUST become available, and a task
  `Stel beslissing op bezwaar op (advies: gegrond)` MUST be created

#### Scenario: Deviation from committee advice requires a reason

- **GIVEN** an `advisoryReport` with `deviationFromPrimaryDecision: true`
  and `adviceType: "gegrond"`
- **WHEN** an `appealDecision` is submitted with `followsAdvice: false`
  and `deviationReason` omitted
- **THEN** the API MUST return a 422 error citing AWB art. 7:13 lid 7:
  deviation from committee advice requires documented reasoning

---

### Requirement: REQ-BBW-008 Beslissing op bezwaar SHALL be recorded as an appealDecision with disposition and rechtsmiddelenclausule

The formal bezwaar decision MUST be recorded as an `appealDecision`
record (Schema.org `schema:LegalForceStatus`) with:

- `dispositionType` (one of: `gegrond`, `ongegrond`,
  `gedeeltelijk_gegrond`, `niet_ontvankelijk`)
- `dispositionDetails` — mandatory full motivation (AWB art. 7:12
  motiveringsplicht); minimum 50 characters enforced as a workflow guard
- `decisionDate` and `effectiveDate` — both required
- `appealInformation` — mandatory rechtsmiddelenclausule informing the
  citizen of the beroep deadline and court (AWB art. 7:11 jo. art. 8:1)
- `decisionMaker` — the bestuursorgaan that made the decision

When `dispositionType` is `gegrond` or `gedeeltelijk_gegrond`,
`remedialAction` MUST be set describing the corrective action
(herroeping, nieuw besluit, etc.).

When `dispositionType` is `gegrond`, the original case SHOULD be
automatically updated: a `caseProperty` or status update MUST indicate
the bezwaar outcome, enabling the original case handler to take follow-up
action. This update is triggered by a workflowTemplate `setField`
automatic action on the Bekendmaking transition.

#### Scenario: Gegrond beslissing requires remedial action

- **GIVEN** a bezwaar case in status `Beslissing op bezwaar`
- **WHEN** an `appealDecision` POST is submitted with
  `dispositionType: "gegrond"` and `remedialAction` omitted
- **THEN** the API MUST return 422: "remedialAction is required when
  dispositionType is gegrond or gedeeltelijk_gegrond"

#### Scenario: Beslissing without rechtsmiddelenclausule is rejected

- **GIVEN** an `appealDecision` POST body
- **WHEN** the `appealInformation` field is absent or empty
- **THEN** the API MUST return 422: "appealInformation (rechtsmiddelenclausule)
  is required per AWB art. 7:11"

#### Scenario: Gegrond bezwaar triggers status update on original case

- **GIVEN** a bezwaar case with a linked primair besluit case and
  `appealDecision.dispositionType = "gegrond"`
- **WHEN** the behandelaar advances to `Bekendmaking`
- **THEN** the workflowTemplate automatic action MUST add a note or
  caseProperty on the primair besluit case reading
  "Bezwaar gegrond verklaard — primair besluit herroepen" so the
  original case handler is informed

---

### Requirement: REQ-BBW-009 Bezwaar dossier SHALL compile documents from the original case and the bezwaar case into an exportable set

The system MUST support compiling the bezwaar dossier — a defined ordered set of documents spanning both the primair besluit case and the bezwaar case — for sharing with Juridische Zaken or court.

**Required dossier order (AWB-conventional):**

1. Primair besluit (kopie) — from original case
2. Bezwaarschrift — from bezwaar case
3. Verweerschrift (if present) — from bezwaar case
4. Hoorzittingverslag — from `hearingSession.minutesDocument`
5. Advies bezwaarschriftencommissie — from `advisoryReport.reportDocument`
6. Beslissing op bezwaar — from `appealDecision.decisionDocument`

The `DossierCompiler` MUST gather `caseDocument` references from both
cases, order them per the above sequence, and present them as a unified
dossier view on the bezwaar case detail page.

For sharing, the system MUST support:
- **Nextcloud share link**: generate a Nextcloud Files share link to the
  bezwaar case's file folder containing all dossier documents.
- **ZIP export** (beroep dossier): bundle all dossier documents into a
  downloadable ZIP for court submission (REQ-BBW-010).

The dossier compilation MUST NOT copy documents — it MUST reference
existing `caseDocument` records. No new document entity is introduced.

#### Scenario: Dossier view shows documents from both cases in prescribed order

- **GIVEN** a bezwaar case with `relatedCases` linking to primair besluit
  case `2026-OGV-0117`, and documents present on both cases
- **WHEN** a user opens the bezwaar dossier view
- **THEN** documents MUST be listed in the AWB-conventional order: primair
  besluit first, then bezwaarschrift, verweerschrift (if any), verslag,
  advies, beslissing — with each document's source case visible

#### Scenario: Reviewer confirms no document duplication

- **GIVEN** the DossierCompiler implementation
- **WHEN** reviewed for document copying or table-level duplication of
  Nextcloud file content
- **THEN** the compiler MUST only reference existing `caseDocument` UUIDs;
  no file copy operation is performed

---

### Requirement: REQ-BBW-010 Beroep case SHALL inherit the bezwaar dossier and maintain three-level case links

The system MUST, when a beroep case is created after an unsuccessful bezwaar (ongegrond, niet-ontvankelijk):

1. Set `case.parentCase` (or `case.relatedCases`) on the beroep case to
   reference the bezwaar case.
2. Inherit all `caseDocument` references from the bezwaar case's compiled
   dossier into the beroep case's dossier view — without copying files.
3. Carry the link to the primair besluit case through the three-level
   chain: primair besluit → bezwaar → beroep.

The beroep workflowTemplate MUST declare 4 steps:

| Order | statusType | Description |
|---|---|---|
| 1 | Beroepschrift ontvangen | Court summons or beroepschrift registered |
| 2 | Verweerschrift opstellen | Procesgemachtigde authors verweer |
| 3 | Zitting | Court hearing; dossier export prepared |
| 4 | Uitspraak | Court ruling received and recorded |

The **beroep dossier export** MUST produce a downloadable ZIP (and
optionally a merged PDF via docudesk) containing all dossier documents
in AWB-conventional order plus any beroep-specific documents
(beroepschrift, dagvaarding, verweerschrift). This export is the
mechanism for "dossier digitaal kunnen delen met Juridische Zaken"
required by tender specifications.

#### Scenario: Beroep case inherits bezwaar dossier without copying files

- **GIVEN** a bezwaar case with a compiled dossier of 6 documents
- **WHEN** a beroep case is created with `relatedCases` referencing
  the bezwaar case UUID
- **THEN** the beroep dossier view MUST show all 6 bezwaar dossier
  documents (via reference) PLUS any beroep-specific documents added
  to the beroep case — without duplicating files in Nextcloud Files

#### Scenario: Beroep dossier export produces a court-ready package

- **GIVEN** a beroep case with a complete compiled dossier
- **WHEN** the procesgemachtigde triggers the dossier export action
- **THEN** a ZIP file MUST be generated containing all dossier documents
  in AWB-conventional order, labelled with sequence numbers and document
  type names suitable for court submission

---

### Requirement: REQ-BBW-011 Bezwaar en beroep registers SHALL be reachable through the procest manifest navigation

`src/manifest.json` MUST declare:

- A navigation section `Juridisch > Bezwaar en beroep` containing:
  - `type: index` page `Bezwaarzaken` — filters `case` by
    `caseType: bezwaar`; columns: zaak-ID, bezwaarmaker (role),
    primair besluit (related case), deadline, status
  - `type: index` page `Beroepszaken` — filters `case` by
    `caseType: beroep`; columns: zaak-ID, appellant, bezwaar-zaak
    (related), rechtbank, status
  - `type: detail` page for bezwaar cases with side panels:
    Bezwaarschrift (objection), Hoorzitting (hearingSession),
    Commissie advies (advisoryReport), Beslissing (appealDecision),
    Dossier (compiled document list)
  - `type: detail` page for beroep cases with side panels:
    Procesdossier, Verweer, Uitspraak

All renderers MUST use the generic `@conduction/nextcloud-vue` page
renderers per ADR-024 Tier-4. No custom bezwaar Vue component is authored
for index or detail pages.

#### Scenario: Bezwaar index shows only bezwaar cases with correct columns

- **GIVEN** the manifest declares the bezwaarzaken page with
  `filter: { caseType: ["bezwaar"] }`
- **WHEN** a behandelaar opens `/index.php/apps/procest/bezwaarzaken`
- **THEN** the page MUST render via `CnIndexPage` showing only bezwaar
  cases with deadline column and primair besluit reference visible;
  no per-bezwaar controller is invoked

#### Scenario: Bezwaar detail page shows all required side panels

- **GIVEN** a bezwaar case with an objection, hearingSession,
  advisoryReport, and appealDecision
- **WHEN** a gebruiker opens the bezwaar case detail page
- **THEN** all four sub-entity panels MUST be visible on the detail page,
  each rendering via the generic OR detail renderer with the correct
  entity data
