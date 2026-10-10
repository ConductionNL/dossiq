---
kind: code
depends_on: []
---

# Proposal: omgevingsvergunning-starts-its-term

## Why

On the review instance (10 Oct, finding B5) a case filed through the new case form
as "Omgevingsvergunning" got no term instance. The register ships that case type
(slug and identifier `omgevingsvergunning`, `processingDeadline` P56D,
`extensionPeriod` P42D), and a TermijnDefinitie binds to a case type by slug, but
`termijnbewaking_seed_data.json` had no definition for `omgevingsvergunning`. The
definitions it has are for the VTH and case-flow variants
(`omgevingsvergunning-bouwactiviteit`, `-kleinbouw`, `-regulier`).

`ShippedDeadlineDefinitionCoverageTest` did not catch it because it sweeps the VTH
and case-flow seeds only, not the case types in the register.

## What Changes

- Add `td-omgevingsvergunning` to `lib/Settings/termijnbewaking_seed_data.json`:
  Ow 16.64 lid 1, 56 days (8 weeks), one extension of 42 days, the same rule the
  bouwactiviteit definition carries. `DeadlineMonitoringSeedDataService` seeds rows
  by id, so an existing install gets it on the next repair.
- Pin it in `ShippedDeadlineDefinitionCoverageTest`.

## Impact

- `lib/Settings/termijnbewaking_seed_data.json`, one test.
- Left open: the other register case types with a `processingDeadline` and no
  definition (`subsidieaanvraag`, `klacht-behandeling`, `melding-openbare-ruimte`,
  the two subsidy schemes, the two event permit versions and the English demo
  types). Each needs its own legal basis, which this change does not invent.
