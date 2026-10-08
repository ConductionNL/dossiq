## ADDED Requirements

### Requirement: A resident's contact channel from the portal reaches their running cases (REQ-PORTAL-016)
When portaliq raises `PortalContactDetailsChangedEvent`, dossiq MUST set
`communicationChannel` on every case of that portal subject that has not
ended to the matching dossiq channel (`portal`, `email` or `post`), and MUST
record the change on each case's timeline. For `phone` it MUST leave the
channel unchanged and record the preference on the timeline. It MUST NOT
fail portaliq's request.

#### Scenario: A resident asks for letters by post
- **GIVEN** a resident with one running and one ended dossiq case
- **WHEN** they choose "By post" as their contact channel in the portal
- **THEN** the running case MUST carry `communicationChannel: post` and a timeline entry saying so
- **AND** the ended case MUST be unchanged

#### Scenario: A resident asks to be phoned
- **GIVEN** the same resident
- **WHEN** they choose "By phone"
- **THEN** the running case's channel MUST stay as it was and the handler MUST see "The applicant prefers to be phoned." on its timeline
