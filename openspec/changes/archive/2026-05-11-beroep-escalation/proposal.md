# Proposal: Beroep Escalation

## Summary

Formalize the `beroep-escalation` capability as a first-class OpenSpec change so that the existing partial spec (5 requirements covering case type, status types, escalation, document management and hoger beroep awareness) is upgraded and bound to a traceable change record. Beroep is the formal appeal of a `beslissing op bezwaar` at the administrative court (`rechtbank`) under Awb hoofdstuk 8. Procest does NOT run the court process — that lives entirely with the rechtbank — but the municipality still has to track the escalation: filing windows, court reference numbers, the chamber that hears the case, the document inspection requests it has to fulfill (file inspection / `op de zaak betrekking hebbende stukken`), and the judgment outcome that may cascade back into the bezwaar workflow (e.g. `vernietigd` → re-decide).

## Why

After PR #362 (`voorstel-management`), `bezwaar-lifecycle` and `bezwaar-decision` are the only two formalized objection-side specs. The escalation that follows — beroep at the rechtbank — exists only as a 5-requirement spec without a change record, with no clear contract for deadline tracking, the dwingende-status flip on the source bezwaar, or the cascade back into procest when the court overturns the decision. Tender requirements (cluster *Bezwaar en beroep*, 801 requirements / 209 tenders) repeatedly demand: deadline supervision, file inspection request fulfillment, court ruling registration, automatic re-opening when the court orders a new decision. Without an explicit `beroep-escalation` capability spec these requirements have nowhere to anchor.

## What Changes

- Adds the `beroep-escalation` change directory (proposal, design, tasks, delta spec).
- Promotes the existing 5-requirement spec at `openspec/specs/beroep-escalation/spec.md` to a formal 8-requirement contract (REQ-BE-1..8): beroep entity linked to source bezwaar, 6-week filing deadline + dwingende status flip, court reference + chamber, file inspection request fulfillment, judgment outcomes, cascade back into bezwaar workflow, audit & immutability, and authorization.
- Adds a beroep lifecycle + cascade diagram in `design.md`.
- Spec only — NO code changes. Procest still does not run the court process; this captures only what the municipality tracks.
- On archive, the delta spec under this change's `specs/beroep-escalation/` replaces the current unowned spec.

## Affected Projects

- [ ] Project: `procest` — Formalize the `beroep-escalation` capability with a complete change record. NO CODE; tasks are verification-only.

## Scope

### In Scope (V1, spec only)

- Beroep entity formalization (REQ-BE-1) — case ref, source bezwaar, contested beslissing, court ref, chamber, filing date.
- 6-week filing deadline tracking + dwingende status flip on source bezwaar (REQ-BE-2).
- Court reference + responsible chamber (REQ-BE-3).
- File inspection request fulfillment (`op de zaak betrekking hebbende stukken`) (REQ-BE-4).
- Judgment outcome registration: vernietigd, in stand gelaten, niet-ontvankelijk, gegrond-met-rechtsgevolgen-in-stand, etc. (REQ-BE-5).
- Cascade back into procest when the court vernietigt and orders a new decision (REQ-BE-6).
- Audit & immutability after submission to the rechtbank (REQ-BE-7).
- Authorization: only the bezwaar handler and the case Juridische Zaken role may edit (REQ-BE-8).

### Out of Scope

- Running the court process itself (court schedules, court documents-as-source).
- Hoger beroep workflow (RvS / CRvB) — informational only, retained from existing spec.
- Voorlopige voorziening as a separate case type — handled as a flag on the beroep case.
- WOZ / fiscaal beroep — domain-specific to tax systems.

## Dependencies

- `bezwaar-lifecycle` (REQUIRED): source bezwaar that escalates.
- `bezwaar-decision` (REQUIRED): the beslissing op bezwaar that is contested.
- `case-management` (REQUIRED): beroep is modeled as a case.
- `openregister-integration` (REQUIRED): schema + audit trail.
