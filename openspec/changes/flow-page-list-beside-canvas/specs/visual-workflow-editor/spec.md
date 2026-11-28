# visual-workflow-editor Delta: flow-page-list-beside-canvas

## ADDED Requirements

### Requirement: A case type's flow reads as a list beside the canvas

The case type flow page SHALL offer a Canvas | List switch. The List view SHALL show the same flow as the canvas as an ordered list: the trigger first, then every status with its steps, numbered, each with its kind. A change made in either view SHALL change the one flow definition both views read.

#### Scenario: The list shows the flow in order

- **GIVEN** the case type "Environmental permit regular" whose flow starts on "Case created" and has the statuses Received and In progress with the steps Check completeness and Confirmation of receipt
- **WHEN** the handler opens its flow page and chooses List
- **THEN** the page SHALL show "When: Case created" first
- **AND** then the rows "1 Received (Status)", "2 Check completeness (Task)", "3 Confirmation of receipt (Message)" and "4 In progress (Status)", in that order

#### Scenario: A change in the list is the same change on the canvas

- **GIVEN** the List view of a flow with the steps Check completeness and Confirmation of receipt under Received
- **WHEN** the handler moves Confirmation of receipt up and switches to Canvas
- **THEN** the canvas SHALL draw Confirmation of receipt before Check completeness
- **AND** Save SHALL write one flow definition with that order

### Requirement: The step panel follows the board in both views

Choosing a step SHALL open one side panel, in the List and in the Canvas view, with the fields the board draws for that kind of step and the buttons Done and Delete step.

#### Scenario: A status step opens its panel

- **GIVEN** the List view of a flow with the status step In progress
- **WHEN** the handler chooses its edit button
- **THEN** the side panel SHALL show Step name, Name of the status, Steps in this status, Next status and Deadline in this status
- **AND** the buttons Done and Delete step
