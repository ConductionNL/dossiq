# pipelinq-client-management Specification

## Problem
Define the client and request management domain for Pipelinq: clients, requests (verzoeken), and contacts. All entities are stored in OpenRegister under the Pipelinq register. Requests represent the pre-state of a case -- a yet-to-be-determined or incoming case before it enters formal case management in Procest. The client entity links organizations and persons across both Pipelinq (CRM) and Procest (case management) contexts.

## Proposed Solution
Implement pipelinq-client-management Specification following the detailed specification. Key requirements include:
- Requirement 1: Client-management schemas MUST be defined in the Pipelinq register
- Requirement 2: Client list view MUST display paginated, searchable client overview
- Requirement 3: Client detail view MUST display full client information with related data
- Requirement 4: Client CRUD operations MUST work through OpenRegister
- Requirement 5: Request list view MUST display paginated, searchable request overview

## Scope
This change covers all requirements defined in the pipelinq-client-management specification.

## Success Criteria
#### Scenario 1.1: Client schema definition
#### Scenario 1.2: Request schema definition
#### Scenario 1.3: Contact schema definition
#### Scenario 1.4: Schema auto-configuration stores IDs
#### Scenario 1.5: Existing register detection on re-enable
