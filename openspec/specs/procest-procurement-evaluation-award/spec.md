---
status: specified
status-note: "Synced 2026-06-14 from archived consolidation change add-procest-procurement-suite (kind:config). SPEC-COMPLETE; code chain pending (ADR-032). Reuses procest's existing decision register for awards (no Award register) per T16/REQ-EVA-001."
---

# procest-procurement-evaluation-award Specification

## Purpose
Define bid evaluation + award for procest: scoring as an `Evaluation`
register (one per bid per evaluator), awards as additive fields on
procest's existing `decision` register, data-driven scoring formulas,
calibration as child cases, declarative standstill enforcement, and
docudesk-generated award documents — anchored in Aw 2012 motiveringsplicht.
## Requirements
### Requirement: REQ-EVA-001 — Scoring SHALL be an `Evaluation` register attached to a Bid; the existing procest `decision` register SHALL carry the award

Scoring SHALL be a new `Evaluation` register and the award SHALL reuse procest's existing `decision` register; procest MUST NOT add an `Award` register.

To avoid duplicating procest's existing `decision` register, this spec
splits the surface in two:

1. **Evaluation work product** — modelled as a new `Evaluation`
   register (Schema.org `schema:AssessAction`), one record per bid
   per evaluator (so a 3-evaluator panel produces 3 records per bid).
2. **Award outcome** — reuses procest's *existing* `decision` register
   with additive fields for procurement (no new "Award" register).

The `Evaluation` schema:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `bid` | string | Yes | FK to `Bid` UUID |
| `tender` | string | Yes | FK to `Tender` UUID (denormalised for query) |
| `evaluator` | string | Yes | UID of the evaluator |
| `criterionScores` | object | Yes | Per-`awardCriteria[i].key` score (numeric) + narrative |
| `weightedTotal` | number | No | Calculated via `x-openregister-calculations` from criterionScores + tender's awardCriteria weights |
| `state` | enum | Yes | `draft`, `submitted`, `calibrated`, `finalised`, `withdrawn` |
| `calibrationNotes` | string | No | Captured during panel calibration session |
| `attachments` | array | No | docudesk URIs of supporting evidence (e.g. evaluator's narrative report) |

Procest MUST NOT define an `Award` register that mirrors `decision`.

#### Scenario: A bid receives one Evaluation per panel member

- **GIVEN** a tender with three evaluators in role `beoordelaar`
- **WHEN** evaluation begins on a bid
- **THEN** three `Evaluation` records MUST be created (one per
  evaluator), each with state `draft`.

#### Scenario: Reviewer confirms no duplicate Award register

- **GIVEN** the procest register file
- **WHEN** inspected for a schema named `Award`, `Gunning`, or
  `Gunningsbesluit`
- **THEN** no such schema SHALL exist; awards are recorded as
  `decision` records with `decisionType: "gunningsbesluit"`.

### Requirement: REQ-EVA-002 — Award decisions SHALL be procest `decision` records with seeded `decisionType`s

Procest MUST seed three `decisionType` records for procurement:

| decisionType | Purpose |
|---|---|
| `voorlopige-gunning` | Preliminary award — triggers tender's `beoordeling → voorlopige-gunning` transition |
| `gunningsbesluit` | Final award — triggers tender's `standstill → definitief-gegund` transition after standstill |
| `afwijzingsbesluit` | Rejection decision for a non-winning bid; carries motivering per Aw 2012 art. 2.130 |

Additive fields on the `decision` register (via additive register
patch — see proposal "Impact" section, no rename):

| Field | Type | Required | Purpose |
|---|---|---|---|
| `awardedBid` | string | No | FK to the winning `Bid` (for voorlopige-gunning + gunningsbesluit) |
| `rejectedBid` | string | No | FK to the rejected `Bid` (for afwijzingsbesluit) |
| `motivering` | string | No | Motiveringsplicht text (Aw 2012 art. 2.130 / Awb art. 3:46) |
| `standstillEndDate` | date | No | Auto-computed on voorlopige-gunning; mirrors `Tender.standstillEndDate` |
| `lot` | string | No | FK to a lot child case if award is per-lot |

#### Scenario: A voorlopige-gunning decision triggers the tender transition

- **GIVEN** a tender in `beoordeling` with all bids evaluated
- **WHEN** the procurement officer creates a `decision` of type
  `voorlopige-gunning` referencing the winning bid
- **THEN** the `Tender` lifecycle MUST advance to
  `voorlopige-gunning`, `Tender.standstillEndDate` MUST be set, and
  the audit trail MUST link the decision to the lifecycle event.

#### Scenario: A rejection decision carries motivering

- **GIVEN** a tender with five bids, one winner
- **WHEN** the procurement officer creates `afwijzingsbesluit`
  records for the four non-winners
- **THEN** each rejection MUST carry a non-empty `motivering` field;
  saves with empty motivering MUST fail validation.

### Requirement: REQ-EVA-003 — Scoring formulas SHALL be data, not code

Scoring formulas SHALL be declared as register data; procest MUST NOT hardcode formula behaviour in a service.

The scoring formula per `awardCriteria[i]` (linear, relatieve
prijsformule, S-curve, pass/fail) MUST be declared as part of the
tender's `awardCriteria[i].scoringMethod` field — interpretable via
an OR `x-openregister-calculations` formula reference.

Procest MUST NOT author a `ScoringFormulaService` switch statement
hardcoding formula behaviour. A formula registry register
(`ScoringFormula`) SHOULD be seeded with the common methods; operators
MAY add custom formulas via the register.

#### Scenario: Switching a formula does not require a code change

- **GIVEN** a procurement officer wants to add a new "weighted
  geometric mean" formula
- **WHEN** they add a `ScoringFormula` record carrying the formula
  expression
- **THEN** new tenders MUST be able to reference the new formula via
  `awardCriteria[i].scoringMethod`; no procest PHP changes.

### Requirement: REQ-EVA-004 — Calibration sessions SHALL be a child case under the tender, reusing existing procest case machinery

Calibration sessions SHALL be modelled as child cases reusing procest case machinery; procest MUST NOT author a parallel session service.

A calibration session (where evaluators reconcile divergent scores)
MUST be a procest child case under the tender (`caseType:
tender-calibration`), inheriting all standard case behaviour
(meeting scheduling via NC Calendar, role assignment, minutes via
docudesk). The session's outcome MUST move associated `Evaluation`
records from `submitted` to `calibrated`.

Procest MUST NOT author a `CalibrationSessionService` parallel to
`case`.

#### Scenario: A calibration session updates linked evaluations

- **GIVEN** a calibration child case is closed with `result: agreed`
- **WHEN** the closure transition fires
- **THEN** the `Evaluation` records referenced from the session MUST
  transition `submitted → calibrated` via OR's lifecycle relations,
  not via a per-app sync method.

### Requirement: REQ-EVA-005 — Standstill (Alcatel-termijn) SHALL be enforced via the declarative lifecycle, not a guard service

The standstill (Alcatel-termijn) SHALL be enforced by the declarative `Tender` lifecycle; procest MUST NOT author a standalone finalisation guard service.

The `Tender` lifecycle's `standstill → definitief-gegund` transition
(per TND spec REQ-TND-002) is the only gate; this spec restates the
EVA-side requirement: the `gunningsbesluit` decision MUST NOT be
finalisable before `standstillEndDate`, and any bezwaar case opened
during standstill MUST further block the transition.

Procest MUST NOT author an `AwardFinalisationGuard` PHP class beyond
the small `requires` guard called *by* the lifecycle engine (per
ADR-031 §"PHP guards remain a legitimate seam").

#### Scenario: A premature gunningsbesluit is blocked

- **GIVEN** a tender with `standstillEndDate: 2026-04-21`
- **WHEN** an officer attempts to create `gunningsbesluit` on
  `2026-04-15`
- **THEN** the save MUST fail with a guard violation citing the
  remaining standstill days.

#### Scenario: An open bezwaar blocks gunningsbesluit past standstill

- **GIVEN** a tender past `standstillEndDate` with a linked bezwaar
  case in state `behandeling`
- **WHEN** an officer attempts to create `gunningsbesluit`
- **THEN** the save MUST fail; the audit trail MUST cite the open
  bezwaar reference.

### Requirement: REQ-EVA-006 — Award documents SHALL be generated by docudesk, not by procest

Award documents SHALL be generated by docudesk; procest MUST NOT author PDF or letter rendering services.

The voorlopig + definitief gunningsbericht, the afwijzingsbrieven met
motivering, and the publishable gunningsverslag MUST be generated by
docudesk (using docudesk's template engine — registered templates
seeded as part of the EVA implementation chain). Procest MUST NOT
author a `GunningPdfService`, `RejectionLetterService`, or
`MotiveringRenderer`.

The decision register MUST carry a `documentRef` field (already
present in procest's existing `decision` schema as
`decisionDocument`) populated with the docudesk-generated URI.

#### Scenario: Reviewer scans for forbidden PDF generation in procest

- **GIVEN** the procest codebase
- **WHEN** scanned for `TCPDF`, `Mpdf`, `Dompdf`, or `wkhtmltopdf` in
  `lib/` related to award documents
- **THEN** no matches SHALL exist; docudesk owns rendering.

### Requirement: REQ-EVA-007 — Evaluation + award pages SHALL be reachable through the procest manifest navigation

`src/manifest.json` MUST declare:

- a navigation entry `Procurement > Evaluations` (`type: index`)
  binding to `Evaluation`;
- a `type: detail` page for individual evaluations with the panel
  view (all evaluators' scores side by side, calibration delta
  surfaced);
- a navigation entry `Procurement > Awards` filtered to `decision`
  records with `decisionType IN ("voorlopige-gunning",
  "gunningsbesluit")`;
- a navigation entry `Procurement > Evaluation dashboard` rendering
  `x-openregister-widgets` (in-progress evaluations, awaiting
  calibration count, awarded-vs-rejected rate).

All renderers MUST be the generic `@conduction/nextcloud-vue` page
renderers per ADR-024 Tier-4. Per-evaluator drill-down MUST be
gated by OR RBAC so an evaluator only sees their own draft scores
until calibration.

#### Scenario: An evaluator sees only their own drafts

- **GIVEN** an evaluator opens their dashboard while a panel is in
  `submitted` state
- **WHEN** the evaluation index renders
- **THEN** only the evaluator's own evaluations MUST be visible; OR
  RBAC enforces the scope.

