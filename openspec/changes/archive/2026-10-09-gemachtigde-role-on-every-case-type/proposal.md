---
kind: config
depends_on: []
---

# Proposal: gemachtigde-role-on-every-case-type

Competitor gap register, row 5.8 "Authorised representative (gemachtigde) on
a case" (`procest/_gaps/gap-register.md` in ConductionNL/market-intelligence,
2026-09-13). Rated partial, statutory, owner dossiq, size S.

## Why

Under Awb 2:1 anyone may let a representative act for them, in any case.
dossiq seeds a role type Gemachtigde once, for the bezwaar case type
(`rol-gemachtigde` in the seed data), and `role.delegate` exists on every
role. A vergunning or a subsidie has no Gemachtigde role type unless an
administrator adds one by hand, so the Parties tab of most cases cannot
record a representative.

The best competitor in the register: xxllnc Zaken,
`frontend-mono/apps/main/src/modules/case/views/relations/Relations.locale.ts`
(Gemachtigd) (`_round2/compare/M1-functionality.md`).

## What changes

- A generic role type Gemachtigde (`genericRole: gemachtigde`, no
  `caseType`) seeded by a repair step, offered on every case type's Add
  party form beside the type's own role types.
- The Parties tab shows the delegate column as Represented by, and a
  Gemachtigde row shows who is represented.
- The case gains no field. The representation is a `role` row with
  `roleType` Gemachtigde and `delegateFrom` the represented party.

## Ownership

dossiq builds the seed and the two manifest lines. It consumes the `role`
and `roleType` schemas and the Parties tab from `parties-on-the-case`, all
shipped. No other app is involved.

## ADRs

- Company ADR-031: the rule is declared data (a seeded role type), not a
  service.
- Company ADR-069: the seed is a repair step, idempotent, registered once.

## Capabilities

- Modified: `roles-decisions`: every case type offers Gemachtigde.

## Impact

Seed in `lib/Settings/dossiq_register.json`; `lib/Repair/
SeedGemachtigdeRoleType.php`; `src/manifest.json` `#CaseDetail` Parties
columns; `tests/e2e/case-parties.spec.ts` extended.
