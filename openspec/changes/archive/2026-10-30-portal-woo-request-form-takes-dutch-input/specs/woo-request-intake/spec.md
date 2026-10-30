# woo-request-intake Specification (delta)

**Status**: planned
**Scope**: dossiq
**OpenSpec changes**:
- [portal-woo-request-form-takes-dutch-input](../../) (this delta)

## ADDED Requirements

### Requirement: The Woo request reads the answers a resident gives (REQ-WDI-001)

The Woo request intake SHALL read a period date written as `jjjj-mm-dd` or day first (`dd-mm-jjjj`, also with `/` or `.` and without leading zeros) and SHALL keep it as `jjjj-mm-dd`. It SHALL read the requester kind from its stored value or from the Dutch word the form shows for it (Inwoner, Journalist, Organisatie), in any case.

#### Scenario: A resident writes the period day first
- GIVEN Sanne asks for documents from 01-01-2026 to 13/10/2026
- WHEN the portal forwards her request
- THEN the case is opened and keeps the period as 2026-01-01 to 2026-10-13
- @e2e exclude pinned through the portal route by `PortalWooRequestDutchAnswersTest::testADayWrittenDayFirstIsKeptAsADate`

#### Scenario: A resident's requester kind arrives as the word she read
- GIVEN the request says she asks as "Inwoner"
- WHEN it is opened
- THEN the case keeps `burger`
- @e2e exclude pinned by `PortalWooRequestDutchAnswersTest::testTheRequestersDutchWordIsKeptAsItsValue`

### Requirement: A refused Woo request names the field in the resident's language (REQ-WDI-002)

When the portal route refuses a Woo request because of one answer, it SHALL answer 400 with `errors` holding that field's name and one sentence, and SHALL word that sentence in Dutch unless the forward asks for English. Nothing SHALL be written.

#### Scenario: A day that does not exist
- GIVEN Sanne writes 31-02-2026 as the start of the period
- WHEN the portal forwards her request
- THEN the route answers 400 with `errors.periodeVan` "Schrijf de datum als dag-maand-jaar, bijvoorbeeld 1-3-2026." and opens no case
- @e2e exclude pinned by `PortalWooRequestDutchAnswersTest::testAnImpossibleDayIsRefusedOnItsFieldInDutch` and `testEveryRefusalNamesItsFieldInDutch`

### Requirement: The confirmation names its dates as days (REQ-WDI-003)

The answer to a Woo request that was opened SHALL carry each of its dates (term start, deadline, receipt) also as the day a person reads ("14 oktober 2026"), written by the formatter the letters use, beside the stored value. The portal confirmation SHALL name the readable days.

#### Scenario: Sanne reads her confirmation
- GIVEN her request was received on 14 October 2026 at 12:34 and must be answered by 11 November 2026
- WHEN the confirmation shows
- THEN it reads "Wij tellen de termijn vanaf 14 oktober 2026. U krijgt uiterlijk op 11 november 2026 antwoord."
- @e2e exclude pinned by `PortalWooRequestDutchAnswersTest::testTheConfirmationNamesItsDatesAsDays` and `PortalContributionProviderTest::testTheWooRequestSavesHalfwayAndNamesTheCaseWhenItIsIn`
