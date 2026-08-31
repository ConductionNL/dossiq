---
kind: code
---

# Proposal: migrate-committees-to-decidiq

## Summary

Retire dossiq's `bezwaaradviescommissie` schema and read objection advisory committees from decidiq's `GovernanceBody` instead. The target only became capable of holding one in decidiq#874, which added the four fields that were missing and the write path that made a migration possible at all; this is the dossiq half that consumes it.

## Motivation

A bezwaaradviescommissie is a governance body. decidiq owns governance bodies — `GovernanceBody`, `Membership`, `Post`, seeded and surfaced — and dossiq carrying a parallel committee schema is duplication that shows up as drift: two places to add a field, two places to fix a bug, and no shared view of who sits on what.

This was originally scoped as "move the schema" and could not be done that way. Measured on 2026-08-24, decidiq's `GovernanceBody` could not represent the committee at all: no `active` (the ONE field dossiq's live code reads and throws on), no numeric `quorum` for Awb 7:13, no `jurisdiction`, no `Membership.external` for 7:13(2), and no writable cross-app seam — the API was GET-only. decidiq#874 closes all five.

## Affected Projects

- [x] Project: `dossiq` — this change. A migration, a read path, and the retirement of the local schema.
- [ ] Project: `decidiq` — already done in #874. Nothing further is asked of it.

## Scope

### In Scope

1. **A migration** that, for each `bezwaaradviescommissie`, causes a decidiq `GovernanceBody` to exist with `bodyType: advisory-body`, `statutoryBasis: Awb 7:13`, and the mapped fields, and records the resulting id on the dossiq side so the mapping is auditable and the migration idempotent.

   **It commands decidiq with a TYPED EVENT, not by calling the REST seam and not by writing into decidiq's register.** ADR-041 is explicit that cross-app *commands* travel as typed `IEventDispatcher` events, and ADR-066 amended it only for *collection* — it left the command rule standing, with gate-27 (`no-phantom-cross-app-rpc`) enforcing it. "Create a governance body in decidiq" is a command.

   ⚠️ **This corrects the first draft of this proposal**, which said the migration would go "via the cross-app write path" that decidiq#874 added. That seam is real and correct — for EXTERNAL callers. For an in-process app-to-app command it is the wrong door, and an in-process HTTP call to our own instance would also have no session to authenticate with.

   The pattern already exists in this app: `ContractDecisionDelegationService` commands decidiq by dispatching `DecisionRequestedEvent` with an `externalReference` / `correlationId`, and the result returns as `DecisionConcludedEvent` carrying that correlation. The committee migration follows the same shape.

   🔴 **This means decidiq needs work that #874 did NOT include**: a `GovernanceBodyRequestedEvent` (or equivalent), a listener that creates the body, and a "created" event carrying the correlation and the new id back. That is a decidiq-side change and a prerequisite for this one.
2. **A fan-out for the roster.** `members[]` is a list of uids on one object; decidiq models it as `Person` + `Membership` rows. Each member becomes a Membership on the new body, with `role` from chair/secretary/member and `external` set for members outside the administrative organ.
3. **A read path** — the eight call sites that read the local schema today (`AdvisoryCommitteeService`, `PanelIndependenceChecker`, `BezwaarAdviceRequestedListener`, `BezwaarAuditTrail`, `SettingsService`, `SchemaSlugMap`, and two more) resolve committees from decidiq, with the local schema as fallback until the migration has run everywhere.
4. **Retirement** of the local schema once the fallback is no longer reachable.

### Out of Scope

- **`bacAdviceRequest`.** The advice REQUEST has its own lifecycle and stays in dossiq for now; only the COMMITTEE moves. (Its `bezwaar` foreign key was fixed separately — see the page-topology-cleanup change.)
- Any change to decidiq. #874 is the whole of it.

## Risks

- 🔴 **A fan-out migration is not idempotent by default.** Re-running must not mint a second Person and Membership per member. The mapping record from In Scope 1 is what makes re-runs safe, and it has to be written BEFORE the memberships, not after.
- 🔴 **`active` is load-bearing.** `AdvisoryCommitteeService` throws "Committee is archived and cannot accept new bezwaaren" on it. A migration that drops or defaults it starts routing objections to disbanded committees, and nothing errors.
- ⚠️ **The read path must not fail closed on decidiq's absence.** decidiq is an optional runtime dependency; a dossiq install without it must still function, which means the fallback is not a migration-window convenience but a permanent branch until decidiq becomes required.
- ⚠️ **Cross-app id references are runtime lookups.** The committee id stored on a bezwaar points into decidiq's register after this; see the fleet lesson about pinned references dying silently when the other side moves.

## Status

**UNBLOCKED 2026-08-30.** Both halves of the target now exist. decidiq#874
merged the schema half; the command seam this proposal said "does not yet
exist" landed as decidiq's `governance-body-events` change —
`GovernanceBodyRequestedEvent`, a listener, `GovernanceBodyCommandService`, and
`GovernanceBodyCreatedEvent` carrying the correlation back.

The correction in Scope 1 above stands and was followed: the command travels as
a typed event, not over the REST seam.

One thing moved from this change to that one. The idempotency risk
— "a fan-out migration is not idempotent by default", listed under Risks above — is answered on the
decidiq side rather than re-implemented here: the seam resolves the body on
(sourceApp, externalReference) and each seat on (person, governanceBody) BEFORE
it writes, and writes the body before the memberships. dossiq still records the
returned id locally, but as a short-circuit rather than as the only thing
standing between a re-run and a duplicated roster.

Delivered here so far: the delegation service, the repair-step migration, the
`dossiq.ensureCommittee` flow node and the seeded `Bezwaar advies` flow
(tasks 1, 2 and 5). Still open: the read path with a permanent fallback
(task 3), and retirement of the local schema (task 4, deliberately deferred
until the fallback is provably unreachable).
