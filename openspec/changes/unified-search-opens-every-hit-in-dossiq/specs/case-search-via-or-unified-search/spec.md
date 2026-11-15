## ADDED Requirements

### Requirement: A hit on a record with no page opens its case

A dossiq schema that belongs to a case and has no detail page of its own SHALL
have a `deepLinks` entry whose `urlTemplate` is `/apps/dossiq/cases/{<field>}`,
where `<field>` is the schema property that holds the case uuid (`case`,
`caseId`, `caseRef` or `parentCase`). A schema whose case field does not hold a
bare uuid SHALL be flagged `searchable: false` instead. Audit and log schemas
SHALL be flagged `searchable: false` even when they name a case.

#### Scenario: A document hit opens its case
@e2e exclude Requires the OR ObjectsProvider pipeline and the Nextcloud search UI; the manifest mapping is asserted by vitest and the provider by openregister's own suite.

- **GIVEN** a `caseDocument` titled "Situatietekening Dorpsstraat 12" on case `<case uuid>`
- **WHEN** a handler who may read the case searches "Situatietekening" in the Nextcloud search
- **THEN** the hit SHALL carry the url `/apps/dossiq/cases/<case uuid>`

#### Scenario: A contact moment hit opens its case
@e2e exclude Same surface; asserted by vitest on the manifest.

- **GIVEN** a `contactmoment` with summary "Belt over de stukken" whose `case` is `<case uuid>`
- **WHEN** a handler searches "stukken"
- **THEN** the hit SHALL carry the url `/apps/dossiq/cases/<case uuid>`

#### Scenario: An audit log is not a hit
@e2e exclude Declarative flag; asserted by vitest on the register JSON.

- **GIVEN** a `sociaalDomeinAuditLog` entry that names a case and a resident
- **WHEN** a handler searches the resident's surname
- **THEN** the entry SHALL NOT be a hit

### Requirement: Every dossiq schema is linked or kept out

Every schema dossiq declares, in `lib/Settings/dossiq_register.json` and every
`lib/Settings/register.d/` fragment, SHALL either have a `deepLinks` entry in
`src/manifest.json` or carry `searchable: false`. No dossiq hit in the
Nextcloud search SHALL open `openregister.objects.show`. A schema with a
detail page SHALL link to that page, not to a case.

#### Scenario: A configuration object is not a hit
@e2e exclude Declarative flag; asserted by vitest on the register JSON.

- **GIVEN** a `decisionType` titled "Omgevingsvergunning verleend"
- **WHEN** a handler searches "Omgevingsvergunning"
- **THEN** the `decisionType` SHALL NOT be a hit

#### Scenario: A bezwaar decision opens its own page
@e2e exclude Requires the Nextcloud search UI; the mapping is asserted by vitest.

- **GIVEN** a `bezwaarDecision` the handler may read
- **WHEN** the handler searches its title
- **THEN** the hit SHALL carry the url `/apps/dossiq/bezwaar-decisions/<uuid>`

#### Scenario: A new schema that does neither fails the build
@e2e exclude A build-time guard, not a user flow.

- **GIVEN** a register fragment that adds a schema with no `deepLinks` entry and no `searchable: false`
- **WHEN** `tests/vitest/searchableSchemas.spec.js` runs
- **THEN** it SHALL fail and name the schema

#### Scenario: A deep link names a field the schema has
@e2e exclude A build-time guard, not a user flow.

- **GIVEN** a `deepLinks` entry with `urlTemplate` `/apps/dossiq/cases/{caseId}`
- **WHEN** the guard runs
- **THEN** it SHALL fail unless the entry's schema declares a `caseId` property and `/cases/:id` is a manifest route
