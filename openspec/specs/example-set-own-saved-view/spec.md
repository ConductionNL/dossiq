# example-set-own-saved-view Specification

## Purpose
What dossiq does for example set own saved view. The requirements were first written by the change `example-set-own-saved-view` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-example-set-own-saved-view/`) and cover: The handler the boards draw owns a saved view on the case list.

## Requirements

### Requirement: The handler the boards draw owns a saved view on the case list

The Zuiddrecht example set SHALL declare a private saved view "Termijn deze week" on the case list (`scope:
dossiq/case`, registers `dossiq`, schemas `case`), owned by the cast's `pieter`, whose filters are the open cases
assigned to the reader with a deadline from today to seven days ahead, sorted on deadline ascending.

#### Scenario: the installed set
- **GIVEN** the Zuiddrecht example set installed
- **WHEN** Pieter Jansen opens the case list's side panel on Weergaven
- **THEN** "Termijn deze week" stands under his own views with its row menu (edit, share, add to the menu, delete)
