# vth-admin-settings Delta: admin-tabs-carry-the-settings-board-blocks

**Status**: draft

## ADDED Requirements

### Requirement: VTH MUST show the advice and public notice settings beside the checklists

The VTH tab SHALL lay Inspection checklists, Advice and advisers, Public notices and Enforcement strategy out three abreast. Advice and advisers SHALL show the standard term and the reminder from the consultation settings and the active advisory bodies with how each is reached. Public notices SHALL show the template, the publication date rule and the objection period of six weeks after the publication date (Awb 6:7) unless the case type sets its own; a template or rule that is not set SHALL read Not set.

#### Scenario: The advisers
@e2e exclude tests/vitest/settingsBoardBlocks.spec.js asserts the rows.

- **GIVEN** the advisory bodies Welstandscommissie (internal) and Brandweer (external, with an email address)
- **THEN** Advisers reads "Welstandscommissie (internal), Brandweer (external, by email)"
