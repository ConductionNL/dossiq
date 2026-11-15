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

### Requirement: A case's communication channel is a slug, mapped at every boundary (REQ-PORTAL-017)

`case.communicationChannel` MUST hold one of the slugs `email`, `portal`, `post`, `website`, `zgw-api`, or nothing. Every writer that receives another value MUST map it before the write: the ZGW Zaken API maps a `communicatiekanaal` URL to the slug an administrator configured for that URL, else `zgw-api`, so no zaak is refused for its channel; channel intake maps known words to their slug and writes no channel for a word that names none. The value the case arrived as MUST be kept in `communicationChannelSource`, and a zaak read MUST answer with that URL. A repair step MUST convert every stored value outside the slugs the same way, once, and name each converted case in the log.

#### Scenario: A zaak with a channel URL is accepted

- **GIVEN** a ZGW client creating a zaak with `communicatiekanaal` `https://referentielijsten.example/api/v1/communicatiekanalen/1`, a URL no administrator mapped
- **WHEN** dossiq writes the case
- **THEN** the case holds `communicationChannel: zgw-api` and `communicationChannelSource` holds the URL
- **AND** reading the zaak back answers with that same URL
- @e2e exclude ZGW API boundary, covered by PHPUnit `ZgwCommunicationChannelBoundaryTest` and `CommunicationChannelTest`

#### Scenario: Stored values are converted once

- **GIVEN** a case holding `Brief` and a case holding a ZGW URL from before the enum
- **WHEN** the upgrade runs
- **THEN** the first holds `post` and the second `zgw-api`, each with its old value in `communicationChannelSource`, and the step does not run again
- @e2e exclude repair step, covered by PHPUnit `NormaliseCommunicationChannelValuesTest`
