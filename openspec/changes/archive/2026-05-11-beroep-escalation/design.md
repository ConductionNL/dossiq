# Design: beroep-escalation

## Context

Beroep is the citizen's formal appeal of a `beslissing op bezwaar` at the administrative court (`rechtbank`) under the Algemene wet bestuursrecht (Awb), hoofdstuk 8. The court process itself lives entirely with the rechtbank — court schedules, hearing minutes, deliberations, the ruling text — and is governed by procesrecht that has nothing to do with Procest. What the municipality *does* need to track is the escalation envelope around the court process: that beroep was filed, against which beslissing, with which court under which reference number, what file inspection requests it had to fulfill, what the court eventually ruled, and what — if anything — that ruling forces the municipality to do next.

That asymmetry is the central design choice: **procest tracks the wrapper, the rechtbank runs the case.** The beroep schema is therefore relatively thin (a handful of identifiers + a judgment outcome) but its links — source bezwaar, contested beslissing, cascade back into procest — are dense.

## Entity: `beroep`

Schema.org type `schema:LegalAction`. Stored as an OpenRegister object on the parent `case` (which itself uses the pre-seeded "Beroep" case type from the existing `beroep-escalation` spec, REQ from the earlier 5-requirement version).

| Property | Type | Required | Role |
|----------|------|----------|------|
| `case` | UUID | yes | The beroep case in Procest |
| `sourceBezwaar` | UUID | yes | The bezwaar that escalated |
| `contestedDecision` | UUID | yes | The `beslissing op bezwaar` being contested |
| `courtReference` | string | no | Rechtbank zaaknummer, populated once the court assigns it |
| `responsibleChamber` | enum | no | `enkelvoudig`, `meervoudig`, `voorzieningenrechter` |
| `competentCourt` | string | no | Name of the rechtbank (e.g. "Rechtbank Midden-Nederland") |
| `appellantFilingDate` | date | yes | Date the beroepschrift was filed at the court |
| `appellantNotifiedDate` | date | no | Date the municipality received the rechtbank notification |
| `filingDeadline` | date | computed | `beslissing.effectiveDate + P6W` |
| `voorzieningRequested` | boolean | no | Voorlopige voorziening also filed (retained from existing spec) |
| `judgmentOutcome` | enum | no | See below |
| `judgmentDate` | date | no | Date of the uitspraak |
| `judgmentDocument` | UUID | no | NC file id of the ruling |
| `cascadeAction` | enum | no | `reopen_bezwaar`, `new_primary_decision`, `none` |
| `cascadeBezwaarCase` | UUID | no | The reopened bezwaar case, if `cascadeAction = reopen_bezwaar` |

`judgmentOutcome` enum: `vernietigd`, `in_stand_gelaten`, `niet_ontvankelijk`, `ongegrond`, `gegrond_rechtsgevolgen_in_stand`, `ingetrokken`, `schikking`.

## Deadline tracking and dwingende status flip

The beroep filing window is 6 weeks from the `effectiveDate` of the contested beslissing op bezwaar (Awb art. 6:7, 6:8). On receipt of a beroepschrift the system computes `filingDeadline` and verifies the bezwaar's `effectiveDate` is within the window. If `appellantFilingDate > filingDeadline`, the system flags `latefilingNotice` for the rechtbank to weigh `verschoonbare termijnoverschrijding` — Procest never decides timeliness itself; only the court does.

In parallel, the source bezwaar's `status` SHALL flip from terminal (`Afgehandeld` / `Beslissing op bezwaar`) to a derived **dwingende** marker visible in dashboards: the bezwaar is no longer "done" because a court may reopen it. The bezwaar case is read-only while a beroep is active.

## Court reference + chamber

`courtReference` is populated as soon as the court returns one (usually a few weeks after filing). `responsibleChamber` defaults to `enkelvoudig` and is updated when the court announces the chamber composition. These two fields drive the case detail header so handlers and Juridische Zaken can identify the court file at a glance.

## File inspection requests (`op de zaak betrekking hebbende stukken`)

Awb art. 8:42 obliges the bestuursorgaan to submit *all* documents pertaining to the contested decision to the court within 4 weeks of being notified of the beroep. Procest does NOT generate the export itself — Juridische Zaken curates and submits — but it MUST record the request, the deadline, and the response status:

- `fileInspectionRequest.requestedAt` — date the rechtbank issued the request
- `fileInspectionRequest.deadline` — `requestedAt + P4W`
- `fileInspectionRequest.submittedAt` — date the dossier was sent
- `fileInspectionRequest.dossierBundle` — UUID of the compiled bundle (link to a `document-zaakdossier` capability artifact when present, else a flat NC folder reference)

## Judgment outcome and cascade back into procest

When the rechtbank issues its uitspraak, the handler records `judgmentOutcome`, `judgmentDate`, and uploads `judgmentDocument`. If the outcome is `vernietigd` and the ruling orders the municipality to take a new decision on the bezwaar, the system creates a `cascadeAction = reopen_bezwaar`: a fresh bezwaar case is forked from the source bezwaar with status `In behandeling`, a link back to the beroep, and a court-imposed deadline. If the ruling instead orders a new *primary* decision (zelden), `cascadeAction = new_primary_decision` and a follow-up task is created on the original primary case. Otherwise `cascadeAction = none` and the beroep simply moves to `Afgehandeld` — the source bezwaar's dwingende marker is cleared and it returns to its terminal status.

## Security & audit

- All status changes derive identity from `IUserSession`; frontend UID overrides are ignored.
- Only `Behandelaar bezwaar` and `Juridische Zaken` roles on the source bezwaar may edit beroep records.
- After `appellantFilingDate` is set, `sourceBezwaar` and `contestedDecision` are immutable — the OpenRegister audit trail captures all subsequent changes.
- The beroep case is readable by anyone with access to the source bezwaar.

## Why a GENERATE-style change?

The existing `beroep-escalation` spec (5 requirements) was committed without a change record. Rather than retrofitting that gap by introducing code, this change *describes* the missing contract — entity, deadlines, cascade, security — so future implementation work can validate against it. Tasks are verification + spec-authoring only; any drift between this contract and the prior 5-requirement spec is reconciled in the delta.
