# Spec delta: vth-beschikking-generation

## ADDED Requirements

### Requirement: Template-based beschikking generation

The system SHALL generate a beschikking document from a versioned template, substituting case merge fields and attaching the result to the case.

**Spec ref**: REQ-VTH-005-A, REQ-VTH-005-C

#### Scenario: Generate beschikking with merged fields

- **WHEN** a beschikking is generated for a case with all required fields
- **THEN** the service SHALL substitute merge fields (applicant, location, activities, conditions) into the template, render a PDF, and attach it to the case bijlagen relation

### Requirement: Required-field validation and template versioning

The system SHALL block generation when required fields are missing and SHALL only use the current template version for new generations.

**Spec ref**: REQ-VTH-005-B, REQ-VTH-005-D

#### Scenario: Missing required field blocks generation

- **WHEN** a required field is missing
- **THEN** generation SHALL be blocked with an error naming the missing field

#### Scenario: New generations use current version

- **WHEN** a template has a prior version with validUntil before today
- **THEN** new generations SHALL use only the current version

### Requirement: Beschikking template management UI

The system SHALL provide an admin UI to create, edit, view, and delete beschikking templates with a merge-field picker, test generation, and validity dates.

**Spec ref**: REQ-VTH-008-C

#### Scenario: Manage a beschikking template

- **WHEN** an administrator creates or edits a beschikking template
- **THEN** they SHALL be able to pick merge fields, run a test generation with sample data, and set validFrom/validUntil dates
