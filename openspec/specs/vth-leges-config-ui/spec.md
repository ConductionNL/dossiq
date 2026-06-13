---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# vth-leges-config-ui Specification

## Purpose
TBD - created by archiving change vth-workflow-configuration-04-leges-config-ui. Update Purpose after archive.
## Requirements
### Requirement: Leges rule configuration UI

The system SHALL provide an admin UI to view and edit leges rule sets (base fee, modifiers, exemptions, verrekening, teruggaaf) with input validation.

**Spec ref**: REQ-VTH-004-E, REQ-VTH-008-B

#### Scenario: Edit leges rule fields

- **WHEN** an administrator opens the leges rule editor
- **THEN** they SHALL be able to edit the base fee, add/edit/delete modifiers, and set exemptions, verrekening and teruggaaf rules, with validation rejecting negative amounts and duplicate modifiers

### Requirement: Leges rule versioning on save

The system SHALL version leges rule sets on save so existing cases keep their original rule version.

**Spec ref**: REQ-VTH-004-E

#### Scenario: Save creates a new effective version

- **WHEN** an administrator saves edited leges rules
- **THEN** a new rule version SHALL be created (validFrom=tomorrow), the prior version SHALL be marked validUntil=today, and the UI SHALL confirm the rules are effective for new cases from tomorrow

