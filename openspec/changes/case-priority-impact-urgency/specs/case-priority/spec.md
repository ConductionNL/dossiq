## ADDED Requirements

### Requirement: A case stores impact and urgency (REQ-PRI-01)

A case SHALL carry `impact` and `urgency`, each an administered value with
an order. Neither SHALL be derived from the other. A case created without
them SHALL take the case type's declared defaults.

#### Scenario: a handler records how much it matters and how soon
@e2e tests/e2e/case-priority.spec.ts

- **GIVEN** a case type declaring impact and urgency values
- **WHEN** a handler sets both on a case
- **THEN** both SHALL be stored on the case

#### Scenario: a case created by intake takes the declared defaults
@e2e tests/e2e/case-priority.spec.ts

- **GIVEN** a case type declaring default impact and urgency
- **WHEN** a case is created from an intake channel
- **THEN** the case SHALL carry those defaults

### Requirement: Priority is derived from a matrix the case type declares (REQ-PRI-02)

Priority SHALL be derived from impact and urgency through a matrix
declared on the case type, with an instance default where a case type
declares none. The derived value SHALL be written to `case.priority` and
SHALL use its existing values. dossiq SHALL NOT ask a person to type a
priority directly.

#### Scenario: changing urgency changes the priority
@e2e tests/e2e/case-priority.spec.ts

- **GIVEN** a case whose derived priority is `normal`
- **WHEN** a handler raises its urgency
- **THEN** the derived priority SHALL rise per the matrix

#### Scenario: two case types read the same impact differently
@e2e tests/e2e/case-priority.spec.ts

- **GIVEN** a bezwaar case type and a melding case type with different matrices
- **WHEN** a case of each is created with the same impact and urgency
- **THEN** the two derived priorities SHALL differ per their matrices

#### Scenario: a case type with no matrix still derives a priority

- **GIVEN** a case type declaring no matrix
- **WHEN** a case is created
- **THEN** the instance default matrix SHALL derive its priority

### Requirement: A person may override the derived priority, on the record (REQ-PRI-03)

A person with the right to do so SHALL be able to override the derived
priority. The override SHALL record who set it, when, and the reason.
While an override stands, derivation SHALL NOT replace it. Clearing the
override SHALL return the case to its derived value and SHALL NOT leave
the overridden value behind.

#### Scenario: an override survives the next derivation
@e2e tests/e2e/case-priority.spec.ts

- **GIVEN** a case whose priority a teamleider raised to `urgent` with a reason
- **WHEN** the derivation next runs
- **THEN** the priority SHALL still read `urgent`

#### Scenario: the override says who and why
@e2e tests/e2e/case-priority.spec.ts

- **GIVEN** an overridden priority
- **WHEN** a handler opens the case
- **THEN** the person, the moment and the reason SHALL be shown

#### Scenario: clearing the override returns to the derived answer
@e2e tests/e2e/case-priority.spec.ts

- **GIVEN** an overridden priority and a since-changed urgency
- **WHEN** the override is cleared
- **THEN** the priority SHALL be the value the matrix now derives

### Requirement: A rule may raise the priority as the term approaches, never lower it (REQ-PRI-04)

A declared rule SHALL be able to raise a case's priority as its statutory
term approaches. Such a rule SHALL NOT lower a priority. The raise SHALL
be recorded with the rule that caused it. dossiq SHALL declare the rule
and SHALL NOT implement a rules engine.

#### Scenario: a case two days from its term rises
@e2e tests/e2e/case-priority.spec.ts

- **GIVEN** a declared rule raising priority inside two days of the term
- **WHEN** a case crosses that threshold
- **THEN** its priority SHALL rise
- **AND** the case SHALL record the rule that raised it

#### Scenario: an extended term does not lower a raised priority
@e2e tests/e2e/case-priority.spec.ts

- **GIVEN** a case whose priority a term rule raised
- **WHEN** the term is extended
- **THEN** the priority SHALL NOT fall

#### Scenario: dossiq ships no engine for the rule

- **GIVEN** the dossiq tree
- **WHEN** it is read for a rule evaluator behind this rule
- **THEN** none SHALL exist, and the declaration SHALL name openregister's engine

### Requirement: Priority orders and colours the working list (REQ-PRI-05)

Each priority value SHALL declare an order and an NL Design System colour
token. A case list SHALL be sortable by priority using that order, and a
row SHALL be able to carry the colour. dossiq SHALL declare both and SHALL
NOT hardcode a colour in a component.

#### Scenario: a handler sorts the queue by priority
@e2e tests/e2e/case-priority.spec.ts

- **GIVEN** a case list holding cases of several priorities
- **WHEN** the handler sorts by priority
- **THEN** the cases SHALL be ordered by the declared order

#### Scenario: the colour is a token, not a hex value

- **GIVEN** the declared priority values
- **WHEN** their colours are read
- **THEN** each SHALL be an NL Design System token

### Requirement: The escalation reads the case's priority (REQ-PRI-06)

Deadline escalation SHALL read the case's priority rather than a
vocabulary of its own. Where escalation needs to say how urgent a
notification is, that value SHALL be named as a notification urgency and
SHALL NOT be called a priority.

#### Scenario: escalation and the case agree on the word
@e2e tests/e2e/case-priority.spec.ts

- **GIVEN** a case whose priority is `urgent`
- **WHEN** an escalation fires on it
- **THEN** the escalation SHALL report that case priority

#### Scenario: nothing else in the tree is called a case priority

- **GIVEN** the dossiq tree
- **WHEN** it is read for a second field named priority on a case
- **THEN** none SHALL exist
