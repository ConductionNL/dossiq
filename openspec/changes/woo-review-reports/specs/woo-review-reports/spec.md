## ADDED Requirements

### Requirement: Both reports are opt-in per organisation, off by default (REQ-WRR-001)

dossiq SHALL hold two admin settings, `wooReviewerThroughputReport` and `wooCollectionPartiesReport`,
both false on a fresh install. `wooReviewerThroughputReport` SHALL NOT be switchable on unless
`wooReviewerThroughputReaders` names an existing Nextcloud group. While a switch is off, its report
routes SHALL answer 403 with the sentence that the organisation has not switched the report on, and
its screen and CSV SHALL NOT be offered.

#### Scenario: Off on a fresh install
- **GIVEN** a fresh install
- **WHEN** a handler requests the throughput report of a Woo case
- **THEN** the route SHALL answer 403 with the not-switched-on sentence

#### Scenario: No reader group, no throughput report
- **GIVEN** an administrator with no reader group set
- **WHEN** they try to switch `wooReviewerThroughputReport` on
- **THEN** the setting SHALL be refused, naming the missing reader group

### Requirement: Throughput per reviewer per day, read by the named group only (REQ-WRR-002)

GET `/api/woo/reports/throughput?case={id}` and `?from=&to=` SHALL answer rows
`{reviewer, displayName, day, openbaar, deels_openbaar, niet_openbaar, total}` from
`wooDocumentAssessment.assessedBy` and the date of `assessedAt` in the instance time zone. Only
members of `wooReviewerThroughputReaders` SHALL read it; an administrator outside the group SHALL be
refused like anyone else. Each read SHALL write an audit entry with the reader, the time and the
scope. A CSV of the same rows SHALL be offered on the screen.

#### Scenario: Assessments per reviewer per day
- **GIVEN** the report switched on, reader group "woo-leiding", and on 2026-11-10 reviewer A assessed 3 documents openbaar and 1 niet_openbaar, and reviewer B assessed 2 deels_openbaar
- **WHEN** a member of "woo-leiding" reads the report for that case
- **THEN** it SHALL answer a row for A on 2026-11-10 with openbaar 3, niet_openbaar 1, total 4, and a row for B with deels_openbaar 2, total 2

#### Scenario: A reviewer cannot read their colleagues' numbers
- **GIVEN** the report switched on and reviewer A not in the reader group
- **WHEN** A requests the report
- **THEN** it SHALL answer 403

#### Scenario: Every read is recorded
- **GIVEN** a reader reads the report
- **WHEN** the audit trail is read
- **THEN** it SHALL hold that reader, the time and the case or period read

### Requirement: Collected mail keeps its header fields (REQ-WRR-003)

When a document added to a Woo case is an e-mail (`message/rfc822`, `.eml`, `.msg`, or a mail item
from an integriq mail source), the add SHALL store `provenance.mail` with `from`, `to`, `cc`,
`date` and `messageId`, read from the message headers, with addresses lower-cased. A mail whose
headers cannot be read SHALL be added with `provenance.mail.unreadable: true`, and SHALL be counted
under `unreadable` in the report rather than left out.

#### Scenario: An eml keeps its sender and recipients
- **GIVEN** an `.eml` from `a.jansen@gemeente.nl` to `b@aannemer.nl` and cc `c@gemeente.nl`
- **WHEN** it is added to a Woo case
- **THEN** its provenance SHALL hold those three addresses and the message id

### Requirement: The collection reported by sender, recipient and domain (REQ-WRR-004)

GET `/api/cases/{id}/woo/reports/parties` SHALL answer, over the case's collected mail, counts per
sender address, per recipient address (to and cc), and per mail domain (of sender or any recipient),
plus `unreadable`, and a CSV of the same. It SHALL require read access to the case and the
`wooCollectionPartiesReport` switch.

#### Scenario: By sender, recipient and domain
- **GIVEN** the switch on, and three collected mails: two from a.jansen@gemeente.nl to b@aannemer.nl, one from b@aannemer.nl to a.jansen@gemeente.nl
- **WHEN** the parties report is read
- **THEN** sender a.jansen@gemeente.nl SHALL count 2 and b@aannemer.nl 1
- **AND** domain gemeente.nl SHALL count 3 and aannemer.nl SHALL count 3
