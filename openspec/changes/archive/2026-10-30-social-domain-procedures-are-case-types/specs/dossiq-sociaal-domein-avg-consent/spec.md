# dossiq-sociaal-domein-avg-consent (delta)

## ADDED Requirements

### Requirement: The cross-domain lookup answers from cases too (REQ-AVG-CD-010)

The cross-domain existence lookup SHALL also answer from open cases (`isFinalStatus` not true)
whose `subjectBsn` is the person and whose case type's category belongs to a domain other than the
caller's. It SHALL read those cases as the system, return only exists, domain and contact (the
assignee, else the assigned group, else the domain), and log the act as before. A procedure record
that moved onto a case (`migratedTo`) SHALL answer only through its case. An unreadable case store
SHALL refuse, not answer "nothing exists".

#### Scenario: A confidential case in another domain is known, nothing more
- **GIVEN** an open Participatiewet case about the person that the caller cannot open
- **WHEN** a Wmo consulent looks the person up on a ground
- **THEN** the answer SHALL say an open case exists in Participatiewet and whom to call, and nothing else

### Requirement: Sensitive data on a case keeps its access (REQ-AVG-CD-011)

`case.subjectBsn` and `case.sensitiveProperties` SHALL be readable and writable only by the group
`dossiq-sensitive`. A case-type field flagged `sensitive` SHALL keep its answers in
`sensitiveProperties`.

#### Scenario: A social-domain handler outside dossiq-sensitive reads neither
- **GIVEN** a reader in dossiq-sociaal-domein and not in dossiq-sensitive
- **WHEN** they read a case with a subject BSN and a sensitive answer
- **THEN** neither SHALL be in what they read, and the ordinary answers SHALL be
