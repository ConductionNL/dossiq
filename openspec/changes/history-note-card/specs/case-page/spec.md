## ADDED Requirements

### Requirement: The note form is its own card under the history

The case Historie tab SHALL show the history card and, under it, a "Notitie
toevoegen" card holding the note form. The history card's "Notitie toevoegen"
button SHALL bring that card into view.

#### Scenario: writing a note
- GIVEN the Historie tab of a case
- WHEN the handler writes a note in the "Notitie toevoegen" card and adds it
- THEN the history card above lists the note

#### Scenario: both cards framed and titled
- GIVEN the Historie tab of a case
- WHEN it opens
- THEN the history and the note form each stand in a card, the note card titled "Notitie toevoegen" with "Komt op de tijdlijn hierboven, intern tenzij u hem zichtbaar maakt" under it
