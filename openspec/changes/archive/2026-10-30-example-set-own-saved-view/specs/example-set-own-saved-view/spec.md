# example-set-own-saved-view Delta

## ADDED Requirements

### Requirement: The handler the boards draw owns a saved view on the case list

The Zuiddrecht example set SHALL declare a private saved view "Termijn deze week" on the case list (`scope:
dossiq/case`, registers `dossiq`, schemas `case`), owned by the cast's `pieter`, whose filters are the open cases
assigned to the reader with a deadline from today to seven days ahead, sorted on deadline ascending.

#### Scenario: the installed set
- **GIVEN** the Zuiddrecht example set installed
- **WHEN** Pieter Jansen opens the case list's side panel on Weergaven
- **THEN** "Termijn deze week" stands under his own views with its row menu (edit, share, add to the menu, delete)
