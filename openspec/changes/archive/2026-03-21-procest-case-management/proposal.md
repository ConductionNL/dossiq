# procest-case-management Specification

## Problem
Define the core case management domain for Procest: cases, tasks, statuses, roles, results, and decisions. All entities are stored in OpenRegister under the Procest register. The frontend provides list and detail views for cases and tasks, with case type configuration, status lifecycle management, deadline tracking, participant management, and activity timelines.

## Proposed Solution
Implement procest-case-management Specification following the detailed specification. Key requirements include:
- Requirement 1: Register and schemas MUST be auto-configured on install
- Requirement 2: Cases list view MUST display paginated, searchable case overview
- Requirement 3: Case create dialog MUST support type-driven case creation
- Requirement 4: Case detail view MUST display full case information with related data
- Requirement 5: Status lifecycle MUST support configurable status flows with mandatory result on closure

## Scope
This change covers all requirements defined in the procest-case-management specification.

## Success Criteria
#### Scenario 1.1: First install creates register and schemas
#### Scenario 1.2: Upgrade with newer version imports new schemas
#### Scenario 1.3: Settings endpoint returns register/schema configuration
#### Scenario 1.4: Admin can save schema configuration
#### Scenario 1.5: Missing OpenRegister detected gracefully
