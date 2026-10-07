---
kind: code
depends_on: []
---

# Proposal: refusals-carry-a-status

Competitor gap register, row Q10.14 "When a rule refuses a write, does the
caller find out" (`procest/_gaps/gap-register.md` in
ConductionNL/market-intelligence, 2026-09-13). Rated partial, owner dossiq,
size M. The register's fourth pick to build first: "Batch 2 named it the
row to take if only one is taken."

## Why

A rule that refuses and returns null looks exactly like a rule that passed.
Re-read on 2026-09-13 (addendum row Q10.14): 47 `catch (\Throwable)` sites
in 37 files under `lib/Service/` return `null` or `[]` within three lines,
out of 276 catches; 93 of 106 files under `tests/Unit/Controller/` mention
`getStatus()`, so 13 controller suites assert a state and never the code.
The register counted 24 of 160 on 2026-09-07; the tree grew. This is the
fail-open shape hydra gate 13 exists to catch, and the posture
`termijnbewaking-op-engine-timers` D-7 documents as legitimate (engine
absent, logged no-op) sits in the same list with no way to tell them apart.

The best competitor in the register: Forgejo 16, HTTP 412 with the rule
named on closing an issue with open dependencies, measured
(`_round4/compare/proposed-rows-batch2.md`).

## What changes

- A structural test lists every `catch (\Throwable)` in `lib/Service/`
  followed within three lines by `return null` or `return []`, and fails on
  any site not in a reason-bearing allowlist. The allowlist starts at the
  measured 47, each with a reason, and the test fails when the count goes
  up or when an allowlisted site disappears without its entry.
- Every site that is a rule refusing a write throws a typed exception
  instead; the controller translates it to a 4xx with `{message, error}`,
  the rule named in `error`. Sites that are degradation (a sibling app or
  the engine absent) keep the catch, log at warning, and carry their reason
  in the allowlist.
- Every controller unit test of a guarded method asserts the status code
  beside the state. The 13 suites without `getStatus()` gain one.

## Ownership

dossiq builds all of it; it is dossiq code. It consumes ADR-105's tracked
exception list and OpenRegister's exception classes, shipped.

## ADRs

- Company ADR-105: a service `@throws` is translated or re-declared, never
  swallowed.
- Company ADR-050: the error envelope is `{message, error}`.
- Company ADR-102: config-absence fails closed with a status.
- Company ADR-060: a test that cannot fail is phantom green; the mutation
  step in the tasks is how each new assertion is proven.

## Capabilities

- Modified: `quality-gates`: refusal sites are counted and ratcheted; a
  refusal carries a status.

## Impact

`tests/Unit/Architecture/ServiceCatchReturnsNullTest.php` and
`tests/Unit/Architecture/catch-return-null.allowlist.json`; the 47 sites,
triaged; 13 controller suites; `lib/Exception/` gains the refusal classes
the triage needs.
