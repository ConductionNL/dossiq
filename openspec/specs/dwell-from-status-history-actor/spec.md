# dwell-from-status-history-actor Specification

## Purpose
What dossiq does for dwell from status history actor. The requirements were first written by the change `dwell-from-status-history-actor` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-dwell-from-status-history-actor/`) and cover: Time read from the status history has a holder.

## Requirements

### Requirement: Time read from the status history has a holder

When process mining reads a case's dwell from its `statusHistory`, each interval SHALL be held by the actor the entry
names (`actor`, else `by`), and otherwise by the case's `assignee`.

#### Scenario: Example data

- **GIVEN** a case handled by Fatma whose status history names Lars on its first entry and no one on the second
- **THEN** the per-handler dwell lists Lars and Fatma, not "Niet vastgelegd"
