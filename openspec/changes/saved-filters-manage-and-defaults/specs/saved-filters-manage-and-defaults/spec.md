## ADDED Requirements

### Requirement: You rename a saved view and decide what happens to its criteria (REQ-SVM-01)

A saved view's menu SHALL offer "Bewerken". The dialog "Weergave bewerken" SHALL offer Naam, Beschrijving and Pictogram, and under Criteria the choice between "Behouden wat opgeslagen is" and "Vervangen door de huidige zoekterm, filters en sortering". The menu SHALL also offer "Bijwerken met huidige filters".

#### Scenario: Rename and keep the criteria

- **GIVEN** your saved view "Termijn deze week" and a list with other filters on it
- **WHEN** you open "Bewerken", change the name to "Deze week" and keep "Behouden wat opgeslagen is"
- **THEN** the view SHALL read "Deze week" in the side panel and the left menu
- **AND** its stored criteria SHALL be unchanged

#### Scenario: Replace the criteria

- **GIVEN** your saved view, shared with a colleague
- **WHEN** you choose "Vervangen door de huidige zoekterm, filters en sortering" and press "Opslaan"
- **THEN** the view SHALL hold the current search term, filters and sort
- **AND** the dialog SHALL have said "Wie de weergave met u deelt, ziet de wijziging meteen."

#### Scenario: A view shared with you

- **GIVEN** a colleague's view that you may only use
- **WHEN** you open its menu under "Gedeeld met mij"
- **THEN** it SHALL offer "Kopie opslaan" and no "Bewerken"

### Requirement: You delete a saved view after a confirmation (REQ-SVM-02)

The menu item "Verwijderen" SHALL say "Ook bij wie het is gedeeld". The dialog "Weergave verwijderen?" SHALL say what goes and what stays, and that it cannot be undone.

#### Scenario: Confirm a delete

- **GIVEN** your saved view, added to the left menu
- **WHEN** you press "Verwijderen"
- **THEN** the dialog SHALL read "De weergave verdwijnt uit uw lijst en uit het menu links. De zaken zelf blijven staan. Dit kunt u niet ongedaan maken."
- **WHEN** you press "Weergave verwijderen"
- **THEN** the view SHALL be gone from the side panel and the left menu
- **AND** the cases SHALL be unchanged

#### Scenario: Cancel a delete

- **GIVEN** the dialog "Weergave verwijderen?"
- **WHEN** you press "Annuleren"
- **THEN** the view SHALL still be there

### Requirement: A saved view keeps its sort order (REQ-SVM-03)

The side panel tab Zoeken SHALL offer "Sorteren op" and "Richting" ("Oplopend" or "Aflopend") and say the current sort. "Weergave opslaan" SHALL store that sort with the search term and the filters. The list's card layout has no column headers, so the side panel is its only way to sort. The list of saved views itself has no control to reorder it.

#### Scenario: Sort and save

- **GIVEN** the case list as cards
- **WHEN** you pick "Termijn" and "Oplopend" and save the view
- **THEN** the panel SHALL read "Nu: op termijn, oplopend."
- **AND** opening the view later SHALL sort on term, ascending

### Requirement: The views dossiq ships are read-only, and you copy one to change it (REQ-SVM-04)

The tab Weergaven SHALL list "Standaardweergaven" as "Alleen lezen", with "Geleverd door dossiq. Een nieuwe versie werkt ze bij." Under "Meer standaardweergaven" it SHALL list one view per case type, with the description from the case type. The menu of a shipped view SHALL offer "Kopie opslaan" and no "Bewerken" or "Verwijderen".

#### Scenario: Copy a shipped view

- **GIVEN** the shipped view "Mijn zaken"
- **WHEN** you press "Kopie opslaan"
- **THEN** a view of your own SHALL appear under "Mijn weergaven"
- **AND** you SHALL be able to edit and share it

### Requirement: You pick the views that stand in your left menu (REQ-SVM-05)

Every view's menu SHALL offer "Toevoegen aan menu", or "Uit menu halen" once it stands there. This is the only default a person picks: no view is marked as the one the list opens on.

#### Scenario: Add to the menu

- **GIVEN** your saved view "Termijn deze week"
- **WHEN** you press "Toevoegen aan menu"
- **THEN** it SHALL stand under "Mijn weergaven" in the left menu
- **AND** opening it SHALL show the list with its criteria

#### Scenario: Remove from the menu

- **GIVEN** a view in the left menu
- **WHEN** you press "Uit menu halen"
- **THEN** it SHALL leave the left menu
- **AND** the view SHALL still exist in the side panel
