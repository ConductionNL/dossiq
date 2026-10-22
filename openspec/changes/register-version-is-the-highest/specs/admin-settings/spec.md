# admin-settings Delta

## ADDED Requirements

### Requirement: A merged schema carries the highest version its fragments declare

When register fragments are merged, a `version` holding a version number (`x.y.z`) SHALL keep the highest value the
base and the fragments declare, whatever their filename order. Every other scalar, and a `version` that is not a
version number, SHALL be overwritten by the later fragment as before.

#### Scenario: A bump sorted before an older fragment

- **GIVEN** `68-case-type-variants.json` declares caseType 1.29.0 and `99-case-type-which-macros.json` 1.28.0
- **WHEN** the register is merged
- **THEN** the merged caseType carries 1.29.0
