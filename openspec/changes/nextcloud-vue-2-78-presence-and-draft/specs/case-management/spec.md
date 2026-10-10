# case-management Delta: nextcloud-vue-2-78-presence-and-draft

## ADDED Requirements

### Requirement: Leaving a case page tells OpenRegister the reader is gone

When a person closes or leaves a case detail page, the page SHALL send a
presence beacon to OpenRegister's departure route with the CSRF token in a
form body, so OpenRegister accepts it.

#### Scenario: Closing the tab of a case

- **GIVEN** a person has a case detail page open and their presence is active
- **WHEN** they close the tab or leave the page
- **THEN** a beacon goes to `/apps/openregister/api/objects/<register>/<schema>/<case id>/presence?_method=DELETE`
- **AND** its body is a form with the field `requesttoken`
- **AND** OpenRegister does not answer 405 or 412

### Requirement: A failed save never reads as saved

When saving a case form fails, the draft indicator SHALL NOT say "Saving" or
"Saved". It SHALL say "Draft kept on this device" when a local copy is
stored, and nothing otherwise.

#### Scenario: The server refuses the save

- **GIVEN** a person edits a case form and a local draft is stored
- **WHEN** the save fails and the form shows the error
- **THEN** the draft indicator says "Draft kept on this device"
- **AND** it does not say "Saved"

#### Scenario: A draft is still being written

- **GIVEN** the save has failed
- **WHEN** the local draft is being written
- **THEN** the draft indicator shows no text
