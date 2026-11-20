# remedy-lodging Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [dialogs-answer-what-the-boards-draw](../../)

## ADDED Requirements

### Requirement: The lifecycle menu offers the remedies the case type declares

`GET /api/case/{id}/acts` SHALL answer `remedies`, one entry per remedy the case type declares, with the id, label
and description the case type gives it, `available: false` and a reason until a decision of the case was made known.

#### Scenario: Before the decision

- **GIVEN** a Woo request without a decision made known
- **THEN** `remedies` holds `objection`, "Bezwaar registreren", not available, with the reason

#### Scenario: After the decision went out

- **GIVEN** the case's `decisionSentOn` is set
- **THEN** the objection is available
