# semantic-case-intake Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [case-completeness-checklist](../../)

## ADDED Requirements

### Requirement: A case answers how complete its application is

A case type MAY declare the items an application must hold (`intakeRequirements.completenessItems`). The system
SHALL answer, for a reader who may read the case, each item as present or missing with its evidence, the counts and
a verdict `complete` or `incomplete`. A document the reader may not read SHALL count as present without its name. A
case type without a list SHALL answer an empty list.

#### Scenario: Two of five present

- **GIVEN** the Woo request case type with five items and a case holding `verzoek-2026-0110.pdf` and the requester's
  e-mail address
- **WHEN** a handler reads `/api/cases/{caseId}/completeness`
- **THEN** the answer lists five items, two present, with `verzoek-2026-0110.pdf` as the first item's evidence
- **AND** `present` is 2, `missing` is 3 and `verdict` is `incomplete`

#### Scenario: A reader who may not read the case

- **WHEN** a signed-in user without read access on the case asks for its completeness
- **THEN** the answer is 403 and nothing about the case is read

#### Scenario: No list

- **GIVEN** a case type without `completenessItems`
- **THEN** the answer is `{ items: [] }` and the card stays hidden
