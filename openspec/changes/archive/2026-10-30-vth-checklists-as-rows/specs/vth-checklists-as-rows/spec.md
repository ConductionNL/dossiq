# vth-checklists-as-rows Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [vth-checklists-as-rows](../../)

## Purpose

The VTH checklists read as the board's list, with an accessible status pill.

## ADDED Requirements

### Requirement: The VTH checklists are library rows with a status pill

The VTH admin tab SHALL list each inspection checklist as a library list row: its name, its version and number of items,
and its state as a status pill (success when active, neutral when not). Edit and Delete SHALL be in the row's menu. The
pill's text SHALL meet WCAG AA contrast against its ground.

#### Scenario: An active checklist

- **GIVEN** the VTH tab with an active checklist "Brandveiligheid", version 3
- **THEN** its row shows "Brandveiligheid", "Versie 3 · …" and an "Actief" pill in the success tint at 5.77:1
