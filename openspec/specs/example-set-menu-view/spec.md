# example-set-menu-view Specification

## Purpose
What dossiq does for example set menu view. The requirements were first written by the change `example-set-menu-view` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-example-set-menu-view/`) and cover: Pieter's own view is in his menu.

## Requirements

### Requirement: Pieter's own view is in his menu

The Zuiddrecht set SHALL put Pieter Jansen's saved view "Termijn deze week" in his menu on the Cases page.

#### Scenario: DqFilterInMenu

- **GIVEN** the Zuiddrecht set is installed
- **THEN** Pieter's menu lists "Termijn deze week" under Mijn weergaven, and its side-panel row carries the pin
