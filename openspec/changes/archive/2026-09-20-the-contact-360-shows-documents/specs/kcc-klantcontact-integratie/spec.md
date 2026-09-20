## ADDED Requirements

### Requirement: The contact view lists the documents this contact sent and received

A contact detail page and an organisation detail page SHALL each carry a
documents panel holding every document this contact sent and every document
sent to them. Each row SHALL show the direction, so a letter sent out and a
letter received are told apart at a glance, and SHALL offer the case the
document is on.

The panel reads the `dispatch` join rather than `informatieobject` itself.
A document names its correspondents in two fields, `sender` and
`recipients`, and a list filter is a map in which every entry narrows, so
there is no way to ask for a row matching either one. A dispatch is one
document, one party and one role, carrying the case, so a single equality
filter is the whole question and the role is the direction.

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

### Requirement: The documents panel never presents a partial list as the whole answer

The panel SHALL declare a row cap, so a contact with more documents than
fit shows the list is capped rather than reading as complete. dossiq SHALL
add no filtering of its own on top of what the objects endpoint answers:
whether that endpoint counts a document the reader may not read or drops it
in silence is OpenRegister's behaviour, and a second filter here would make
the answer wrong in a second place.

Whether the endpoint counts or drops is UNMEASURED as of 2026-09-18. If it
drops, the count is wrong on every page in the fleet that reads it, and the
fix belongs to OpenRegister rather than to this panel.

#### Scenario: A contact with more documents than the cap shows the cap
@e2e exclude a declaration compared between two source files; covered by the panel manifest unit test

- **GIVEN** a documents panel on a contact page
- **WHEN** its declaration is read
- **THEN** it SHALL carry a row cap
- **AND** it SHALL carry no client-side filter of its own

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
