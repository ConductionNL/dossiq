# case-management Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [case-page-answers-in-card-notices](../../)

## ADDED Requirements

### Requirement: An action on the case page answers with a card notice

An action endpoint a case page card calls SHALL answer its result as a `notice` `{ variant, role, title, text }`
the card shows in place of a toast: `role` is `alert` for an error and `status` otherwise, and the texts are in the
reader's language. A task is cancelled through the task engine only, behind the write guard on the task's own case.

#### Scenario: Task completed

- **WHEN** a handler completes the task "Documenten beoordelen"
- **THEN** the answer carries a success notice titled "Taak Documenten beoordelen afgerond."

#### Scenario: Cancel refused by the engine

- **WHEN** a handler cancels a task the engine will not cancel
- **THEN** the answer is 409 with an error notice titled "Annuleren geweigerd voor <task>:" and the engine's reason as
  its text
- **AND** the task stays open

#### Scenario: Cancel without write access

- **WHEN** a signed-in user without write access on the task's case cancels it
- **THEN** the answer is 403 and the engine is not asked
