---
kind: code
depends_on: []
---

# Proposal: no-schema-without-a-surface

Competitor gap register, row Q11.31 "Does the installation create storage
for capabilities the installed edition cannot use"
(`procest/_gaps/gap-register.md` in ConductionNL/market-intelligence,
2026-09-13). Rated no, owner dossiq, size M. The ledger's own first pick,
sixth in the register's order because "the re-read of the 34 archived rows
will shrink it before anyone sweeps it". Re-read on 2026-09-13, addendum row
Q11.31: it grew.

## Why

dossiq registers storage for things it cannot show. The matrix named eight
cells "schema only". The re-read counts 153 schemas across
`lib/Settings/dossiq_register.json` and `register.d/`; a word-bounded grep
finds 72 names nowhere under `src/` and 27 of those nowhere under `lib/`
outside `lib/Settings/` either: `adviceResponse`, `advisoryBody`,
`avgIncident`, `callbackRequest`, `complaintCategory`,
`complaintDisposition`, `indicatiestelling`, `jeugdwetZaak`, `kccAgent`,
`kccQuickAction`, `mandateArrangement`, `mandateEscalation`, `mandateUsage`,
`mdoOverleg`, `medewerkerRolToewijzing`, `milestoneRecord`,
`participatiewetZaak`, `portaalNotificatieVoorkeur`, `reIntegratieTraject`,
`specialistBeschikbaarheid`, `subsidieVaststelling`, `supplierKpi`,
`supplierUser`, `syncQueue`, `termijnGebeurtenis`,
`wmoZaak`, `zaaktypeInformatieobjecttype`. A grep by name misses slug
constants and Dutch aliases, so 27 is an upper bound for the structural
test to replace. A registered schema with no surface is storage ahead of
the feature, and a privacy question when the schema holds personal data.

The best competitor in the register: Znuny 7.3, 126 tables and every one
reachable, packages add theirs on install, measured
(`_round4/compare/proposed-rows-batch4.md`).

## What changes

- A structural test: every schema slug declared in the register files has
  a manifest page or widget over it, a reference under `lib/` outside
  `lib/Settings/`, or an entry in a reason-bearing allowlist naming the
  change that will surface or retire it. It fails on a schema with none.
- A triage of the 27 into retire, surface, allowlist. Retire means the
  schema and its seed go, the way `remove-casetask` section 4 did it, with
  the grep count recorded. Surface means an open change owns it and is
  named. Allowlist is for a schema another app reads through the register
  (a data-provider leaf), with that app named.
- The retirements, in batches, each a PR with the grep evidence.

## Ownership

dossiq builds the test and does the triage: it registers its own schemas.
It consumes nothing new. Where a schema turns out to be read by another
app, that app is named in the allowlist and nothing is deleted.

## ADRs

- Company ADR-100: a well-formed app's `lib/` vocabulary is closed; a
  schema nobody reads is outside it.
- Company ADR-031: a declared schema is a promise of a surface.
- Company ADR-060: the test replaces a grep that cannot see aliases.

## Capabilities

- Modified: `quality-gates`: no schema without a surface.

## Impact

`tests/Unit/Architecture/SchemaHasSurfaceTest.php` and
`schema-surface.allowlist.json`; `lib/Settings/dossiq_register.json` and
`register.d/*.json` (retirements); `lib/Settings/dossiq_mock_register.json`
seeds; `tests/e2e` fixture schema lists.
