# Design: bezwaar-lifecycle

## Context

Bezwaar is the most legally formal case type Procest handles. Under the Algemene wet bestuursrecht (Awb hoofdstuk 6 + 7), municipalities have six weeks to decide on an objection against a primary decision (art. 7:10 lid 1), extendable once by another six weeks (art. 7:10 lid 3). Missing that deadline triggers automatic dwangsom liability under Wet dwangsom (Awb 4:17): up to € 1 442 per case, with daily increments. The lifecycle therefore needs a strict deadline scheduler, a status state machine that prevents illegal jumps (e.g. recording a beslissing op bezwaar before ontvankelijkheid is determined), and an audit trail that documents every Awb-relevant action. The capability is the spine; advisory-committee, hearing, and decision are vertebrae that compose onto it.

## Entity: bezwaar case (`case` with `zaaktype = Bezwaar`)

Defined in `lib/Settings/procest_register.json`. Bezwaar cases reuse the generic `case` schema but rely on a pre-seeded `caseType` named "Bezwaar" that declares `processingDeadline = P6W`, `extensionAllowed = true`, `extensionPeriod = P6W`, `suspensionAllowed = true`. Linked schemas: `objection` (one), `hearingSession` (0..n), `advisoryReport` (0..1), `decision` with `besluittype = "Beslissing op bezwaar"` (0..1).

## Lifecycle (status state machine)

```
Ontvangen ──(intake compleet)──► Ontvankelijkheidstoets
Ontvankelijkheidstoets ──(ontvankelijk)──► In behandeling
Ontvankelijkheidstoets ──(niet-ontvankelijk, motivering)──► Niet-ontvankelijk [terminal]
In behandeling ──(hoorzitting ingepland)──► Hoorzitting gepland
In behandeling ──(hoorrecht afgezien, reden vereist)──► Advies uitgebracht | Beslissing op bezwaar
Hoorzitting gepland ──(hoorzitting uitgevoerd)──► Hoorzitting afgerond
Hoorzitting afgerond ──(advies uitgebracht)──► Advies uitgebracht
Hoorzitting afgerond ──(geen commissie)──► Beslissing op bezwaar
Advies uitgebracht ──(beslissing op bezwaar genomen)──► Beslissing op bezwaar
Beslissing op bezwaar ──(verzonden + termijn doorgegeven)──► Afgehandeld [terminal]
* ──(bezwaarmaker trekt in, art. 6:21)──► Ingetrokken [terminal]
```

Terminal statuses: `Afgehandeld`, `Niet-ontvankelijk`, `Ingetrokken`. Skip rules: when `objection.hearingWaived = true`, `Hoorzitting gepland` and `Hoorzitting afgerond` are skipped — the audit trail records reason `"Belanghebbende heeft afgezien van het recht te worden gehoord"`. Backward transitions are not permitted; reopening a terminal-status case requires a new case via `beroep-escalation`.

## Deadline rules

| Trigger | Output field | Formula | Awb |
|---------|--------------|---------|-----|
| Case created | `ontvangstbevestigingDeadline` | `ontvangstdatum + P1W` | 6:14 |
| Case created | `afhandelDeadline` | `ontvangstdatum + P6W` | 7:10 lid 1 |
| Verdaging | `afhandelDeadline` | `afhandelDeadline + extensionPeriod` | 7:10 lid 3 |
| Opschorting start | `clockStopAt` | `now()` | 7:10 lid 4 |
| Opschorting end | `afhandelDeadline` | `afhandelDeadline + (clockStartAt − clockStopAt)` | 7:10 lid 4 |
| Ingebrekestelling | `dwangsomStartDate` | `ingebrekestellingDate + P2W` | 4:17 |
| Dwangsom day-1 | `dwangsomAccrued` | `min(P6W × € 1442/P6W, € 1442)` | 4:17 |

Deadlines are declared via OpenRegister `x-openregister-calculations` (ADR-022) on the bezwaar `case` schema — not via a procest-specific service. Each rule maps to `{ formula, inputs, outputField }` and is evaluated by OR's `RenderObject::renderCalculations`.

## Escalation triggers

- `afhandelDeadline − 5 workdays`: warning notification + dashboard "at-risk" flag (behandelaar)
- `afhandelDeadline + 0 days`: case becomes "overdue" — escalation notification to teamlead + bezwaar-coördinator
- `ingebrekestelling received`: 14-day grace clock starts; if no beslissing within those 14 days, dwangsom accrues automatically
- `dwangsom accruing`: case banner shows live counter and total liability

## Sister-capability integration

- **bezwaar-advisory-committee** — when the commissie publishes an `advisoryReport`, the lifecycle observer transitions the case to `Advies uitgebracht` and surfaces `deviationFromPrimaryDecision = true` as a behandelaar-attention flag
- **bezwaar-hearing** — `hearingSession.status = uitgevoerd` triggers transition to `Hoorzitting afgerond`; `hearingSession.hearingWaived = true` allows the lifecycle to skip both hearing statuses
- **bezwaar-decision** — recording a `decision` with `besluittype = "Beslissing op bezwaar"` transitions the case to `Beslissing op bezwaar`, and once `decisionLetterSent = true` to `Afgehandeld`. `dispositionType = niet_ontvankelijk` short-circuits straight to `Niet-ontvankelijk`

## Audit and compliance

Every status transition writes an audit entry with: `actor` (UID), `fromStatus`, `toStatus`, `reason`, `awbReference` (article ID), `timestamp`. Awb reference is required for any transition that changes the legal posture: ontvankelijkheidsoordeel, verdaging, opschorting, hoorrecht-afzien, niet-ontvankelijk, intrekking. The trail is append-only via OpenRegister's per-save audit log; no admin override. Archiefwet retention follows the parent `case` schema (V20 / V10 depending on dispositionType).

## Why a GENERATE-style change?

The schemas, seed data, and Vue components are already in production via the merged `bezwaar-beroep-workflow` change (archived 2026-03-22). What is missing is the canonical capability spec that the sister capabilities can compose onto. This change captures the contract that the existing code already honours — tasks are scoped to verification, gaps become follow-up issues.
