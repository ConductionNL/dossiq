## ADDED Requirements

### Requirement: A milestone is offset from the item it names (REQ-MST-01)

`milestoneDefinition.dependsOn` SHALL be read. A milestone that names a
predecessor SHALL be dated as an offset from that item, counted on the
organisation's working calendar. A milestone that names none SHALL keep
counting from the case start as it does today. Moving a predecessor SHALL
move every item downstream of it.

#### Scenario: The hearing moves and the timeline follows
@e2e tests/e2e/task-dependencies-and-the-next-planned-action.spec.ts

- **GIVEN** a milestone dated two weeks after the hearing
- **WHEN** the hearing moves by nine days
- **THEN** that milestone SHALL move by nine days
- **AND** every item downstream of it SHALL move with it

#### Scenario: An item with no predecessor is unchanged
@e2e exclude unit, behaviour parity; DependsOnOffsetTest

- **GIVEN** a milestone declaring no `dependsOn`
- **WHEN** the timeline is computed
- **THEN** its date SHALL be the cumulative offset from the case start, as it is today

#### Scenario: A cycle is refused when the case type is saved
@e2e exclude unit; CycleRefusedTest

- **GIVEN** three milestone definitions that depend on each other in a circle
- **WHEN** the case type is saved
- **THEN** the save SHALL be refused
- **AND** the refusal SHALL name the items in the cycle

### Requirement: A milestone has an owner resolved from the case (REQ-MST-02)

A `milestoneDefinition` SHALL be able to name the role that owns it, and the
milestone on a case SHALL resolve that role to the person holding it on that
case. A milestone that names no role SHALL have no owner rather than a
guessed one.

#### Scenario: The role resolves to the person on this case
@e2e tests/e2e/task-dependencies-and-the-next-planned-action.spec.ts

- **GIVEN** a milestone owned by the role of case handler
- **WHEN** a case with that handler reaches the milestone
- **THEN** the milestone SHALL be owned by that handler

#### Scenario: The owner follows a change of handler
@e2e exclude unit; MilestoneAssigneeTest

- **GIVEN** the same milestone
- **WHEN** the case's handler changes
- **THEN** the milestone's owner SHALL be the new handler
