# Tasks: party-contact-moments-board-lines

- [x] 1.1 Test: the card's meta parts, value labels, extend and header note (`tests/vitest/partyContactMomentsLines.spec.js`, red on development).
- [x] 1.2 Config on ContactDetail and OrganisationDetail, l10n en/nl.
- [x] 1.3 Live: DqContact and DqOrganisatie rows read as the board on :8080.
  - Done on a sandbox instance, 25 Nov 2026 (NC 34.0.6, MariaDB, dossiq/opencatalogi development, openregister main, Zuiddrecht set), Dutch locale: Buurtvereniging Oost (OrganisationDetail) and Henk Bakker / Fatima El-Amrani (ContactDetail) read "31 okt, 12:00 · e-mail · inkomend · zaak 2026-4838" lines, lower-case channel and direction, with "4 in totaal" right of the title, as DqOrganisatie draws them.
