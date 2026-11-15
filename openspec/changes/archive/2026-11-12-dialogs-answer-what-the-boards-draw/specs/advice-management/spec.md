# advice-management Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [dialogs-answer-what-the-boards-draw](../../)

## ADDED Requirements

### Requirement: An advice request keeps its kind, subject and outcome

An advice request SHALL be stored in the advice request schema the list reads, with its kind (`intern`/`extern`),
subject and question. An external advisor is an organisation and SHALL NOT be notified as a user. A received advice
SHALL keep its conclusion, text and the conditions checked, and the notification SHALL carry the summary.

#### Scenario: External advice

- **WHEN** a handler asks the Veiligheidsregio for advice
- **THEN** the request is stored with type `extern` and nobody is notified as its advisor

#### Scenario: Received with conditions

- **WHEN** the advice is received as positive with conditions
- **THEN** the request keeps the conclusion, the text and the conditions
