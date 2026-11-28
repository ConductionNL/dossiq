# portal-contribution Delta: case-page-shows-the-outcome

**Status**: draft
**Scope**: the `mijnZaken` collection dossiq contributes to the resident portal.

## ADDED Requirements

### Requirement: The case collection MUST name its outcome fields

The `mijnZaken` collection SHALL declare `outcome` with `labelField` `resultPublicLabel` and `descriptionField` `resultPublicDescription`, and both fields SHALL be among the fields the collection projects, so the portal's case page shows what was decided and why on an ended case.

#### Scenario: An ended case shows its outcome

- **GIVEN** a resident's case on a final status with `resultPublicLabel` "Vergunning verleend" and an explanation in `resultPublicDescription`
- **WHEN** the resident opens the case page
- **THEN** the page shows "Uitkomst" with "Vergunning verleend" and the explanation above the status steps
