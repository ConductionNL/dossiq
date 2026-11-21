## ADDED Requirements

### Requirement: A handler searches the organisation's sources from a Woo case (REQ-WOO-012)
A Woo request case MUST offer Gather documents. It MUST search the sources the
instance has in one go: Nextcloud files through the platform's unified search,
documents on other cases through OpenRegister, and each source integriq
connects. Each source MUST answer with the searcher's own access. Results MUST
be listed per source with name, location, date and a snippet, and a source that
cannot answer MUST say why instead of showing no results.

#### Scenario: Search files and other cases at once
- **GIVEN** a Woo case in the status "Zoeken documenten" and files about the
  subject in two team folders the handler can read
- **WHEN** the handler opens Gather documents and searches on the subject
- **THEN** the dialog MUST list those files under Files
- **AND** matching documents on other cases MUST be listed under Cases

#### Scenario: A source that is not connected
- **GIVEN** an instance without a Microsoft Graph connection in integriq
- **WHEN** the handler opens Gather documents
- **THEN** SharePoint, Teams and mail MUST be shown as not connected, with the
  reason
- **AND** the other sources MUST still be searchable

### Requirement: Picked results become documents on the case (REQ-WOO-013)
The handler MUST be able to add picked results to the case. A file or a fetched
item MUST land in the case folder and so become a document on the case,
outstanding for assessment. A document of another case MUST be linked, not
copied. Each pick MUST succeed or be refused on its own, with the reason.

#### Scenario: Two files added, one refused
- **GIVEN** three picked results, one of them a file the handler may not read
- **WHEN** the handler presses Add selected
- **THEN** two documents MUST appear on the case and in the outstanding list
  for assessment
- **AND** the third MUST be reported as refused, naming why

### Requirement: Every gathered document records where it was found (REQ-WOO-014)
A document added through Gather documents MUST record its source, its location,
the search terms, when it was searched and by whom. The record MUST be written
once, at the add.

#### Scenario: Provenance is on the document
- **GIVEN** a document added from a SharePoint search on "bouwvergunning 2024"
- **WHEN** a colleague opens the document's properties on the case
- **THEN** they MUST see the source SharePoint, the site and folder, the terms
  and the date of the search
