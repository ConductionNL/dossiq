# besluitvorming-workflow delta: besluitvorming-package

## MODIFIED Requirements

### Requirement: REQ-BVW-001 Zaaktype templates MUST be pre-configured for the three core besluitvorming types

The system SHALL ship the College-besluit, Raadsbesluit and Mandaatbesluit case types, each with its `statusType`, `propertyDefinition`, `roleType`, `documentType` and `resultType` records and its `workflowTemplate`, as the case definition package `besluitvorming`, marked `install: on-request` because decision types are managed in decidiq. An administrator SHALL install it through `POST /api/packages/besluitvorming/install` or `occ dossiq:package:install besluitvorming`. Installing it again SHALL write nothing, and on an instance where the former besluitvorming seeder already wrote these case types, installing it SHALL adopt them rather than write them a second time.

#### Scenario REQ-BVW-001-A: Activate College-besluit template
@e2e tests/e2e/packages/besluitvorming.spec.ts

- **GIVEN** an administrator on an instance where the package is not installed
- **WHEN** they install the package `besluitvorming`
- **THEN** the install SHALL answer `installed` or `current`
- **AND** the College-besluit, Raadsbesluit and Mandaatbesluit case types SHALL open on the case type page, each with its flow
- **AND** installing it again SHALL answer `current` and write nothing

#### Scenario REQ-BVW-001-B: Raadsbesluit template includes griffier role and extended deadline
@e2e exclude a property of the shipped rows, read back by tests/integration/packages/besluitvorming.postman_collection.json on a clean instance

- **GIVEN** the besluitvorming package is installed
- **THEN** the Raadsbesluit case type SHALL have `processingDeadline = 'P60D'`
- **AND** a `roleType` named "Griffier" SHALL exist on it

#### Scenario REQ-BVW-001-C: Mandaatbesluit template has confidentiality set to intern
@e2e exclude a property of the shipped rows, read back by tests/integration/packages/besluitvorming.postman_collection.json on a clean instance

- **GIVEN** the besluitvorming package is installed
- **THEN** the Mandaatbesluit case type SHALL have `confidentiality = 'intern'` and `publicationRequired = false`
