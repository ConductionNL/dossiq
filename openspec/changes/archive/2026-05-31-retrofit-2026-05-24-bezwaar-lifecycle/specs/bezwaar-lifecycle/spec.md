---
retrofit_extensions:
  - REQ-001
  - REQ-002
  - REQ-003
---

# Bezwaar Lifecycle — listener + seed surface (retrofit)

## Requirements

### REQ-001: BezwaarLifecycleListener SHALL react to lifecycle events and update bezwaar state

`OCA\Procest\Listener\BezwaarLifecycleListener` SHALL implement `OCP\EventDispatcher\IEventListener::handle($event)` and react to: bezwaar created (set initial status + assigned reviewer), bezwaar hearing scheduled (block status changes until hearing concludes), bezwaar decision made (compute decision deadline + propagate to case timeline), and bezwaar withdrawn (terminate the lifecycle). The listener SHALL be idempotent on repeated event delivery — handler effects SHALL be guarded by the bezwaar's current state so a re-played event is a no-op when the transition already occurred.

#### Scenario: Replayed creation event is a no-op
- **GIVEN** a bezwaar already at status `in-behandeling` from a prior creation event
- **WHEN** the same `BezwaarCreatedEvent` is dispatched again
- **THEN** the listener SHALL detect the existing state and SHALL NOT re-trigger status / assignment side effects

### REQ-002: SeedBezwaarBeroepData SHALL seed shared reference data for bezwaar + beroep

`OCA\Procest\Repair\SeedBezwaarBeroepData` SHALL run on app install/upgrade and create the shared reference data for bezwaar + beroep workflows: status types, role types, decision-type enumerations, and any standing notification templates. The seeder SHALL be idempotent — pre-existing records (matched by slug/code) SHALL be left untouched, and re-running the repair SHALL be safe.

#### Scenario: Repair re-runs on upgrade
- **WHEN** a procest upgrade triggers repair steps
- **THEN** `SeedBezwaarBeroepData::run($output)` SHALL skip rows already in the database and only add net-new reference data introduced in this release

### REQ-003: SeedBezwaarWorkflowDefinition SHALL seed the canonical bezwaar workflow definition

`OCA\Procest\Repair\SeedBezwaarWorkflowDefinition` SHALL create the canonical bezwaar workflow definition (status nodes, transitions, role guards, deadline rules) as a published version 1 record. The seeder SHALL be idempotent — if a published bezwaar workflow already exists, the repair SHALL be a no-op rather than creating a competing version. If the existing record is on an older schema version, the seeder SHALL migrate it forward in-place rather than seeding a parallel record.

#### Scenario: Existing v1 workflow is preserved
- **GIVEN** a bezwaar workflow already published as v1
- **WHEN** `SeedBezwaarWorkflowDefinition::run($output)` runs
- **THEN** no new workflow record SHALL be created and the output SHALL log the no-op
