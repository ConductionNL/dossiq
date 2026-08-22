# automatic-actions-surface

## ADDED Requirements

### Requirement: Procest contributes its case actions as OpenRegister flow nodes

Per ADR-065 OpenRegister owns the flow engine and no leaf app grows a second
one. Procest SHALL present each of its case actions to that engine as a
contributed `IFlowNode`, registered through
`OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent`. Procest SHALL NOT keep
an action engine of its own.

Each node's id SHALL be derived from its handler's own `type()` slug
(`procest.<type>`), so a node id cannot drift from the handler it runs.

#### Scenario: The six actions appear in the node catalogue

- **GIVEN** an instance with procest and OpenRegister installed
- **WHEN** the flow node catalogue is read
- **THEN** it offers `procest.sendEmail`, `procest.notifyRole`, `procest.callWebhook`, `procest.createDocument`, `procest.mergeTemplate` and `procest.scheduleReminder`

#### Scenario: The app still boots without OpenRegister

- **GIVEN** an instance where OpenRegister is absent
- **THEN** procest registers no flow nodes
- **AND** the app boots normally

### Requirement: A failed action is never a silent pass-through

A node whose action does not complete SHALL throw. It SHALL NOT return the items
unchanged, because the engine's per-step `onError` policy only ever sees
failures that propagate out of `execute()`, and an unchanged item leaves the
output key absent so a downstream router takes its default branch as though the
action had succeeded.

A node SHALL validate its config inside `execute()` as well as in
`validateConfig()`: the latter only runs when a flow is saved, and a flow that
was imported or seeded reaches execution unvalidated.

#### Scenario: An action that fails stops the step

- **GIVEN** a flow step whose action reports failure
- **THEN** `execute()` throws
- **AND** the run's `onError` policy decides what happens next

#### Scenario: An unvalidated flow is rejected at execution

- **GIVEN** a seeded flow whose action step is missing a required config key
- **WHEN** the step executes
- **THEN** it throws rather than passing the items through

### Requirement: Existing action references keep working across the upgrade

`caseType.workflowSteps` references actions in three places —
`automaticActions[]`, `config.autoActions[]` and `config.escalationRule`. A
repair step SHALL rewrite those references to the corresponding flow node ids.
The rewrite SHALL be idempotent and SHALL NOT fail an upgrade.

#### Scenario: A case type's actions survive the move

- **GIVEN** a case type whose workflow steps reference actions by type
- **WHEN** the upgrade runs
- **THEN** each reference names the corresponding `procest.<type>` node
- **AND** running the upgrade again changes nothing further

### Requirement: Procest hosts no automatic-actions administration pages

Automatic actions SHALL be administered through OpenRegister's flows surface.
Procest SHALL NOT declare `/settings/automatic-actions` or its detail page.

#### Scenario: The pages are gone

- **GIVEN** the procest manifest
- **THEN** neither `/settings/automatic-actions` nor `/settings/automatic-actions/:id` exists
- **AND** one navigation entry deep-links to OpenRegister's flows surface
