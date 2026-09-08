## ADDED Requirements

### Requirement: You publish a draft after a validation check and a change note (REQ-ZV-01)

You publish a draft case type after a validation check and a change note.
The case type page SHALL offer Publish, which SHALL run the validation and
refuse with the finding list when it is not empty, and otherwise SHALL ask
for a change note, clear the draft flag and mark the active workflow
template published. A Versions tab SHALL list the type's workflow templates
with version, lifecycle status and change note.

**Feature tier**: MVP

#### Scenario: A valid draft is published
@e2e tests/e2e/case-type-authoring-extras.spec.ts

- **GIVEN** a draft type whose validation finds nothing
- **WHEN** you choose Publish and enter the change note Eerste versie
- **THEN** the type SHALL no longer be a draft
- **AND** the Versions tab SHALL show one row marked published with Eerste versie

#### Scenario: A draft with findings is not published
@e2e tests/e2e/case-type-authoring-extras.spec.ts

- **GIVEN** a draft type without an initial status
- **WHEN** you choose Publish
- **THEN** the page SHALL list the finding and the type SHALL stay a draft
