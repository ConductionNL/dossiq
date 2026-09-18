## ADDED Requirements

### Requirement: The contact view lists the documents this contact sent and received

A contact detail page and an organisation detail page SHALL each carry a
documents panel over `informatieobject`, holding every document whose
`sender` or whose `recipients` name this contact. Each row SHALL show the
direction, so a letter sent out and a letter received are told apart at a
glance, and SHALL deep link to the case the document is on.

#### Scenario: A citizen who received a decision sees it on their contact page

- **GIVEN** a contact who is the recipient of one outgoing decision letter
- **AND** the sender of one incoming objection
- **WHEN** a handler opens that contact
- **THEN** the documents panel SHALL list both
- **AND** each row SHALL name its direction and open the case it belongs to

#### Scenario: A document on nobody's behalf does not appear

- **GIVEN** a document on a case with neither a sender nor a recipient set
- **WHEN** a handler opens the requester of that case
- **THEN** the documents panel SHALL NOT list it
- **AND** the case itself SHALL still be listed in the cases panel

### Requirement: A document the reader may not open is counted and never named

The documents panel SHALL count a document the reader may not read, and
SHALL NOT name it. A panel that dropped it would tell the reader this
contact has two documents when the truth is five, which is worse than
telling them less.

#### Scenario: A confidential document raises the count and shows no title
@e2e exclude an authorization branch over the objects endpoint; covered by the panel unit test against a listing carrying one unreadable row

- **GIVEN** a contact with three documents, one of them unreadable by this reader
- **WHEN** the panel renders
- **THEN** it SHALL show two rows
- **AND** it SHALL say that one more document exists that this reader may not open

### Requirement: An empty documents panel says so in a sentence

A contact with no documents SHALL be told that in words. A listing that
failed SHALL draw an error with a retry, and SHALL NOT render as empty,
because nothing to show and nothing reachable look identical otherwise.

#### Scenario: A failed listing is not an empty one
@e2e exclude a transport failure with no reachable gesture; covered by the panel unit test with a rejected listing

- **GIVEN** the objects endpoint answers with an error
- **WHEN** the documents panel renders
- **THEN** it SHALL show the error and a retry
- **AND** it SHALL NOT show the empty state
