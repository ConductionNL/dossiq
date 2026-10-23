# case-types Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [case-type-conditional-rules](../../)

## Purpose

A functional administrator says, per case type, what happens when a case meets a condition, without code.

## ADDED Requirements

### Requirement: A case type carries conditional rules

A case type SHALL carry an ordered list of rules. Each rule SHALL have a moment (created, saved, status entered), a list
of conditions over the case's fields, status and documents, all of which must hold, and a list of actions: add a task,
set a field, choose a variant, require a document, send a message. A rule SHALL be on or off.

#### Scenario: Structural review on large building costs

- **GIVEN** a permit case type with the rule "when saved, if bouwkosten is greater than 100000, add task Toets door constructeur"
- **WHEN** a case of that type is saved with bouwkosten 150000
- **THEN** the case has the task Toets door constructeur, once, also after it is saved again

### Requirement: Rules are evaluated by the engines that own the actions

Publishing a case type SHALL project its rules onto OpenRegister's lifecycle, schema rules and notifications, and onto
the case type's flow where no engine action exists. The projection SHALL be scoped to the case type and SHALL leave
other case types' entries untouched. Dossiq SHALL NOT evaluate a rule itself.

#### Scenario: Two case types publish rules

- **GIVEN** two case types that each declare rules
- **WHEN** one of them is published again without one of its rules
- **THEN** that rule is gone from the case schema and every rule of the other case type is still there

### Requirement: The Rules tab reads and writes the case type's rules

The Rules tab SHALL list the case type's rules as "Als … / dan …" rows with an On or Off pill above the calculated
fields, and "Regel toevoegen" SHALL add one through a form that offers this case type's own fields, tasks, variants,
document types and message templates.

#### Scenario: DqZaaktypeRegels

- **GIVEN** the Omgevingsvergunning case type with three rules
- **THEN** the Rules tab reads "Als Bouwkosten groter is dan € 100.000 / dan taak Toets door constructeur toevoegen" with "Aan"
