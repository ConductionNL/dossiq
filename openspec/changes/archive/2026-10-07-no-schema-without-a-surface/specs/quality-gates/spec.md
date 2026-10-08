## ADDED Requirements

### Requirement: Every declared schema has a surface (REQ-QG-SHS-1)

A structural test SHALL read every schema declared in
`lib/Settings/dossiq_register.json` and `lib/Settings/register.d/*.json`
and SHALL fail for any slug that has no manifest page or widget, no
`deepLinks` entry, no reference under `lib/` outside `lib/Settings/`, no
parent with a surface, and no reason-bearing allowlist entry naming the
change that surfaces it or the app that reads it.

#### Scenario: A new schema without a surface fails
@e2e exclude structural; covered by SchemaHasSurfaceTest over a fixture register

- **GIVEN** a register fragment declaring schema `orphan`
- **WHEN** the test runs
- **THEN** it SHALL fail naming `orphan`

#### Scenario: A child of a shown parent passes
@e2e exclude structural; same test, one-hop branch

- **GIVEN** schema `case` on a page and schema `caseObject` referenced from `case`
- **WHEN** the test runs
- **THEN** `caseObject` SHALL pass

### Requirement: Schema-only registrations are retired or surfaced (REQ-QG-SHS-2)

The schemas the test names on `development` at the time of the change SHALL
each be retired with their seeds, surfaced by a named change, or
allowlisted with the app that reads them. The allowlist ceiling SHALL only
go down.

#### Scenario: A retired schema leaves no trace
@e2e exclude structural; covered by the per-retirement grep recorded in each PR

- **GIVEN** a schema is retired
- **WHEN** `lib/`, `src/` and `tests/` are searched for the slug
- **THEN** no reference SHALL remain
- **AND** the mock register SHALL hold no seed with that schema

#### Scenario: A schema promised by a shipped spec is surfaced, not retired
@e2e exclude structural; covered by the triage table and the allowlist's ownerChange field

- **GIVEN** a schema with no surface whose owning change shipped its storage
  and its seeds
- **WHEN** it is triaged
- **THEN** its fate SHALL be surface, with the owning change named
- **AND** it SHALL NOT be deleted on the strength of having no reader,
  because an instance may have been filling it since the change shipped
