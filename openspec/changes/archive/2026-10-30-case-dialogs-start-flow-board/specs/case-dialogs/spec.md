# case-dialogs Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [case-dialogs-start-flow-board](../../)

## Purpose

Flow starten says what it offers and where the list comes from.

## ADDED Requirements

### Requirement: Flow starten names its list and its limit

The Start a flow dialog SHALL list the case type's startable flows under the legend "Sub-process", SHALL say beneath
them that only flows the case type allows are listed, and SHALL confirm with "Start it".

#### Scenario: DqZaakDialogen

- **WHEN** the handler opens Flow starten on a case whose type lists flows
- **THEN** the flows are under "Deelproces" with their descriptions, "Alleen flows die het zaaktype toestaat." is beneath
  them, and the confirm reads "Starten"
