# cases-filter-labels-for-views Delta: lists-and-dashboards-as-drawn

## ADDED Requirements

### Requirement: The cases list names a label filter Label

The Cases page SHALL name the `tags` filter "Label" on the Active line, so a link with `tags=wijk-noord` reads "Label: wijk-noord", as board DqZaken draws it.

#### Scenario: A label filter on the Active line
@e2e exclude manifest filter label; covered by tests/vitest/casesLabelChip.spec.js
- **GIVEN** the cases list filtered on the label wijk-noord
- **THEN** the Active line reads "Label: wijk-noord" with its own remove button
