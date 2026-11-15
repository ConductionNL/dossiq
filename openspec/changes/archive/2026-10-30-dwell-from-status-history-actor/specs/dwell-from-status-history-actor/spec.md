# dwell-from-status-history-actor Delta

## ADDED Requirements

### Requirement: Time read from the status history has a holder

When process mining reads a case's dwell from its `statusHistory`, each interval SHALL be held by the actor the entry
names (`actor`, else `by`), and otherwise by the case's `assignee`.

#### Scenario: Example data

- **GIVEN** a case handled by Fatma whose status history names Lars on its first entry and no one on the second
- **THEN** the per-handler dwell lists Lars and Fatma, not "Niet vastgelegd"
