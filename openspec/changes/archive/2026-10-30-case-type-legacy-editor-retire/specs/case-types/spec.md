## ADDED Requirements

### Requirement: The Case types pages are the only case type editor (REQ-CT-EDIT-1)

Case types MUST be created and edited on the Case types and CaseTypeDetail
pages; the Nextcloud admin settings MUST link there instead of carrying an
editor of their own.

#### Scenario: an administrator looks for the case type editor in admin settings
- GIVEN the dossiq admin settings page
- WHEN the administrator reads the Case type management section
- THEN it links to the Case types page

#### Scenario: edit a status's checklist
- GIVEN a case type with a status
- WHEN the administrator opens the case type's Statuses tab
- THEN the statuses editor offers the status's checklist and field rules

#### Scenario: edit or reorder a status that has a capacity
- GIVEN a status with capacity 12 and a phase term
- WHEN the administrator renames it, or drags it to another position
- THEN the saved status still has capacity 12 and its phase term
