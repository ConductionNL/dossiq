---
kind: code
depends_on: [omgevingsvergunning-starts-its-term]
---

# Proposal: seeded-case-types-start-their-terms

## Why

Ten case types the register ships declare a `processingDeadline` and have no
TermijnDefinitie, so a case of that type starts no term at all
(`omgevingsvergunning-starts-its-term`, task 2.1). Ruben decided on 10 Oct that each
Dutch type gets the term its legal basis gives, cited by article and source, and
that the four English demo types get a service norm rather than a statutory term.
Where the basis is a gemeente's own rule and cannot be stated generically, no term
is invented: the type is listed as an open question instead.

Two engine gaps make that decision real rather than a seed row that does nothing:

- `TermDefinitions::activeFor()` ignores `kind`. A case type whose only definition is
  a service norm (`kind: planned`) would have that norm armed as its STATUTORY term
  through the fallback in `TermijnService::createTermijnInstance()`.
- No code binds a planned clock from a definition. The planned end is bound only from
  the case type's own `plannedLeadTime`, so a `kind: planned` definition armed nothing.

## What Changes

### Per type

| Case type (binding key) | Kind | Basis | Term | Extension | Source |
|---|---|---|---|---|---|
| `klacht-behandeling` | statutory | Awb art. 9:11 lid 1 and 2 | 42 calendar days | one, at most 28 days | wetten.overheid.nl BWBR0005537, art. 9:11 |
| `evenementenvergunning-2024` (v1) | statutory | APV art. 1:2 lid 1 (model-APV VNG), Awb art. 4:13 lid 2 as fallback | 56 calendar days | one, at most 56 days (model-APV art. 1:2 lid 2) | model-APV VNG; wetten.overheid.nl BWBR0005537, art. 4:13 |
| `evenementenvergunning` (v2) | statutory | as v1 | 56 calendar days | one, at most 56 days | as v1 |
| `zaaktype-cultuur-subsidie-2026` | statutory | Awb art. 4:13 lid 1 and 2 (no term in a wettelijk voorschrift assumed) | 56 calendar days | none fixed (Awb art. 4:14 lid 3 names a reasonable later term) | wetten.overheid.nl BWBR0005537, art. 4:13, 4:14 |
| `melding-openbare-ruimte` | planned | service norm, no statutory term | 14 calendar days | none | none |
| `building-permit` | planned | service norm (demo) | 56 calendar days | none | none |
| `grant-application` | planned | service norm (demo) | 42 calendar days | none | none |
| `citizen-complaint` | planned | service norm (demo) | 42 calendar days | none | none |
| `foi-request` | planned | service norm (demo) | 30 calendar days | none | none |

Left open on 10 Oct, decided by Ruben on 11 Oct (see design.md): both now take the Awb
4:13 lid 2 fallback of eight weeks, with Awb 4:14 lid 3 as the notice of a later term.
The reasoning that kept them open:

- `subsidieaanvraag` (P42D, extension P28D). Awb title 4.2 sets no general
  beslistermijn for a subsidy application. The generic fallback, Awb art. 4:13 lid 2,
  is eight weeks with a notified reasonable later term (art. 4:14 lid 3), not six
  weeks plus four. Six weeks can only come from the gemeente's own ASV.
- `zaaktype-innovatiefonds-2026` (P13W, ASV art. 3). Thirteen weeks is longer than the
  Awb fallback allows, so it can only come from the gemeente's own ASV.

### Schema

- `deadlineDefinition` gains `legalBasisSources`: the citations behind `legalBasis`,
  each an article and the URL of the authoritative text. Optional and additive.

### Engine

- `TermDefinitions::activeFor()` answers statutory definitions only (an absent `kind`
  still reads as statutory).
- The case-created listener resolves a `kind: planned` definition through
  `TermResolution` and `CaseTermsService::bindForCase()` binds the planned clock from it
  when the case type declares no `plannedLeadTime` of its own, counted in the mode the
  definition declares.

### Tests

- `ShippedDeadlineDefinitionCoverageTest` sweeps the register's case types too, keyed
  the way `CaseTypeSlugResolver` resolves them (identifier first, then the slug), reads
  week durations, and pins each row above. The open questions are a named list the
  test holds to: each must still be shipped and still have no definition.

## Impact

- `lib/Settings/termijnbewaking_seed_data.json`, `lib/Settings/register.d/60-termijnbewaking.json`
- `lib/Service/Termijn/TermDefinitions.php`, `lib/Service/CaseTermsService.php`,
  `lib/Listener/DeadlineCaseCreatedListener.php`
- Existing installs get the new rows from `SeedDeadlineMonitoringData`, which runs in
  `<post-migration>` and skips rows it already has by id.
