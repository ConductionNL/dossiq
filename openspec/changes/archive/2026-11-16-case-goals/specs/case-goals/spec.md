## ADDED Requirements

### Requirement: A case can hold goals that say what counts as met

A goal SHALL belong to a case or to a family plan. Saving a goal that names neither SHALL be refused, and so SHALL a goal
without a title or without `metWhen`. A goal SHALL be closed only as met, not met or withdrawn, with an observation.

#### Scenario: a goal on a Woo case
- **GIVEN** case 710d44a4 (Woo-verzoek)
- **WHEN** a handler saves the goal "De verzoeker weet waarom de verlichting uitbleef" with `metWhen` "Het besluit noemt de reden"
- **THEN** the goal is stored with `case` 710d44a4 and state `open`

#### Scenario: a goal with no owner
- **WHEN** a goal is saved with neither `case` nor `plan`
- **THEN** it is refused with rule `goal-needs-an-owner`

### Requirement: Goals are read and written through the case's own access

`GET /api/cases/{caseId}/goals` SHALL answer the case's goals and the case type's `goalPrompt` only to a reader who may
read the case, and `POST` SHALL save only for one who may update it. The check SHALL run before the store is touched.

#### Scenario: a reader without access
- **GIVEN** a user who may not read case X
- **WHEN** they ask `GET /api/cases/X/goals`
- **THEN** the answer is 403 and no goal is read

### Requirement: The case type words the empty goals row

The simple case page's Zaakplan card SHALL end with a goals row when the case type sets `goalPrompt`: the bold lead
"Goals", then the goals, or with none the case type's prompt, and "Add goal" at the end of the row.

#### Scenario: Zaakplan on a Woo case without goals
- **GIVEN** a Woo case type with `goalPrompt` "nog geen doelen: wat wil de verzoeker dat er verandert?"
- **THEN** the Zaakplan card ends with "Doelen nog geen doelen: wat wil de verzoeker dat er verandert?" and "Doel toevoegen"
