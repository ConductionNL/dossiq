## ADDED Requirements

### Requirement: the deadline dashboard lists each notice of default by its case, with its penalty

The deadline dashboard MUST list every notice of default, newest receipt first, with its case (opening the case), the day it came in, its assessment, the penalty it started and where that money stands, read from `GET /api/deadlines/notices` behind the reporting audience gate.

#### Scenario: a fixed penalty
- GIVEN notice 2026-4863 Kapvergunning Parkstraat, received 7 August, valid, its penalty fixed at € 1.442
- THEN its row reads "Ontvangen 7 aug", "Geldig", "€ 1.442, vastgesteld" and "Betaalsignaal verstuurd"

#### Scenario: a running penalty
- GIVEN a valid notice whose penalty has run since 16 October and stands at € 207
- THEN its row reads "€ 207, loopt sinds 16 okt" and "Bedrag nog niet vast"

#### Scenario: no penalty
- GIVEN a notice received while the term still ran
- THEN its row reads "Te vroeg: termijn loopt nog", "Geen" and "Niet van toepassing"

#### Scenario: outside the audience
- GIVEN a caller outside the reporting audience
- THEN the endpoint answers 403 and reads nothing
