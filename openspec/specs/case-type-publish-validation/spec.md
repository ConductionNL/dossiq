# case-type-publish-validation Specification

## Purpose
TBD - created by archiving change case-types-02-backend-validation. Update Purpose after archive.
## Requirements
### Requirement: Backend MUST enforce publish prerequisites
The system MUST enforce publish prerequisites on the backend. Frontend-only
validation is insufficient as the API can bypass it. Server-side enforcement
prevents incomplete case types from reaching production.

#### Scenario: Publish blocked — no status types
- GIVEN a draft case type "Testtype" with no linked status types
- WHEN the API receives a PATCH/PUT setting `isDraft = false`
- THEN the server MUST return HTTP 422 with:
  `{ "errors": ["At least one status type must be defined before publishing"] }`
- AND the case type MUST remain in draft state

#### Scenario: Publish blocked — no final status type
- GIVEN a draft case type with 2 linked status types, neither has `isFinal = true`
- WHEN the API attempts to publish
- THEN the server MUST return HTTP 422 with:
  `{ "errors": ["At least one status type must be marked as final"] }`

#### Scenario: Publish blocked — validFrom not set
- GIVEN a draft case type with valid status types but `validFrom` is null/empty
- WHEN the API attempts to publish
- THEN the server MUST return HTTP 422 with:
  `{ "errors": ["'Valid from' date must be set before publishing"] }`

#### Scenario: Publish succeeds with all prerequisites met
- GIVEN a draft case type with 3 status types (one `isFinal = true`),
  `validFrom = "2026-01-01"`, and all required fields filled
- WHEN the API sets `isDraft = false`
- THEN the server MUST return HTTP 200 with the updated case type
- AND `isDraft` MUST be `false` in the response

#### Scenario: Multiple validation errors returned together
- GIVEN a draft case type with no status types and no `validFrom`
- WHEN the API attempts to publish
- THEN the server MUST return HTTP 422 with BOTH errors in the `errors` array
- AND the admin UI MUST be able to display all errors simultaneously

### Requirement: Backend MUST block deletion of case types with active cases
The system MUST prevent deletion of a case type that has active (non-final) cases.
This protects live cases from losing their type reference.

#### Scenario: Deletion blocked — active cases exist
- GIVEN a case type "Omgevingsvergunning" with 7 active cases (non-final status)
- WHEN the admin attempts to delete the case type (via UI or API)
- THEN the server MUST return HTTP 409 with:
  `{ "message": "Cannot delete case type 'Omgevingsvergunning': 7 active cases are using this type. Close or reassign all cases first." }`
- AND the case type MUST remain in OpenRegister

#### Scenario: Deletion warned — only closed cases exist
- GIVEN a case type "Testtype" with 0 active cases and 3 closed cases
- WHEN the admin attempts to delete
- THEN the system MUST require a confirmation (`requiresConfirmation: true`)
  naming the 3 closed-case references
- AND if confirmed (`?confirm=true`), the deletion MUST proceed

#### Scenario: Deletion succeeds — no cases
- GIVEN a case type "Ongebruikt Type" with no associated cases
- WHEN the admin deletes it and confirms
- THEN the case type MUST be deleted from OpenRegister

### Requirement: Backend validation MUST be unit-tested
The system SHALL pin the publish and deletion guard behaviour with unit tests
covering both happy and error paths.

#### Scenario: Unit tests cover publish and deletion paths
- GIVEN the `ZgwZtcRulesServiceTest` suite
- WHEN `composer test` runs
- THEN it MUST include ≥4 `validatePublish()` tests (no status types, no final
  status, missing validFrom, all-prerequisites-met)
- AND ≥2 `validateDeletion()` tests (blocked-when-active, allowed-when-none)
- AND all tests MUST pass under `composer check:strict`

