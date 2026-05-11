# Proposal: Bezwaar Hearing (Hoorzitting)

## Summary

Formalize the `bezwaar-hearing` capability as a first-class OpenSpec change so that the hoorzitting step in the bezwaar lifecycle has explicit, validatable specification coverage. The hoorzitting (Awb art. 7:2–7:7) is the formal step at which a bezwaarmaker is given the opportunity to be heard before a decision on the objection is made. It is a sister spec to `bezwaar-lifecycle` (the case-type configuration and statussen) and `bezwaar-advisory-committee` (the commissie advisory report). This change introduces an explicit `bezwaar-hearing` capability spec covering scheduling, the waiver of the right to be heard, invitations with accessibility safeguards (low-literacy, interpreters), inspection-of-file before the hearing, the attendee list, minutes capture (text + optional audio), and the legal compliance hooks that wire the hearing into the bezwaar audit trail.

## Why

The current `openspec/specs/bezwaar-hearing/spec.md` was committed without a corresponding change record, leaving traceability between spec and implementation broken. Three requirements live there today (session management, waiver, participant access) but they do not cover invitation accessibility, inspection-of-file mechanics, attendee tracking, or audio recording — all of which appear in tender requirements and Awb compliance audits. Formalizing the capability now gives reviewers an explicit lifecycle, gives tender responses a traceable spec ID, and unblocks downstream changes (case-email-integration, mijn-overheid-integration) that need a stable hearing contract to reference.

## What Changes

- Adds the `bezwaar-hearing` capability spec under this change's `specs/` directory with eight `## ADDED Requirements` entries (REQ-BH-1..8) in delta format with Given/When/Then scenarios
- Adds a hearing lifecycle diagram, inspection-of-file timing, and accessibility hooks to `design.md`
- Adds verification + authoring tasks (T01–T10), each backed by `openspec validate --strict`
- NO code changes — implementation belongs to a follow-up apply change

## Affected Projects

- [ ] Project: `procest` — Formalize the `bezwaar-hearing` capability with proposal, design, tasks, and a delta-format spec. NO CODE.

## Cross-Project Dependencies

- **bezwaar-lifecycle** (sister spec): owns case type, statussen, and deadlines that reference hearing transitions
- **bezwaar-advisory-committee** (sister spec): consumes `hearingSession` UUID on advisory reports
- **case-email-integration**: outbound invitation channel
- **mijn-overheid-integration**: Berichtenbox channel for invitations
