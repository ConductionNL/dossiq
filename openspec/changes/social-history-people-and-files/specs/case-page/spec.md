# case-page Delta

## ADDED Requirements

### Requirement: The social history shows what people did and which documents they viewed

The case page's social side history SHALL leave out entries by the System actor, and SHALL label a document view with the
document's name.

#### Scenario: Fatma Kaya opens the medical report

- **WHEN** Fatma Kaya opens "Medisch verslag" on the case
- **THEN** the Historie card shows "Inzage Medisch verslag" with her name and the date
