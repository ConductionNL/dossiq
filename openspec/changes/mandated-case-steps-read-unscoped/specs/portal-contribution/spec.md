# portal-contribution Delta: mandated-case-steps-read-unscoped

**Status**: draft
**Scope**: how the steps and documents providers read a case the portal proved the reader may see.

## ADDED Requirements

### Requirement: A case read under a mandate shows its steps and documents (REQ-PORTAL-023)

The `caseSteps` and `caseDocuments` provider methods SHALL read the case and its documents as the system and without RBAC or multitenancy, so the answer does not depend on who is signed in. Portaliq proves the case is the reader's, or one they hold a mandate for, before it asks.

#### Scenario: A mandated reader sees where the case stands
@e2e exclude a mandate needs two portal accounts on a shared instance; covered by PortalCaseStepsTest
- **GIVEN** Linda, signed in as herself, holds a mandate from Henk
- **WHEN** she opens Henk's case on the portal
- **THEN** "Waar staat uw aanvraag?" lists the case type's public steps, with the current one marked

#### Scenario: A mandated reader sees the published documents
@e2e exclude covered by PortalCaseDocumentsTest
- **GIVEN** the same mandate and a final outgoing letter on the case
- **WHEN** Linda opens the case
- **THEN** the letter is listed under Documenten
