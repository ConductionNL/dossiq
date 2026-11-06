## MODIFIED Requirements

### Requirement: The case page offers bezwaar and klacht for the case on screen

The citizen actions `createBezwaar` and `createKlacht` SHALL declare `onCase`
on their `contestedCase` cross reference, so portaliq lists them on the page of
the resident's own case and fills in that case. The bezwaar SHALL come first.
The bezwaar's placement SHALL carry a window of 42 days from the case field
`decisionSentOn` and a closing sentence. Each action SHALL carry a
`successMessage`. Neither the overview nor the case page SHALL declare them as
calls to action. A klacht's case reference SHALL NOT be required.

#### Scenario: A resident objects from their case page

- **GIVEN** a resident on the page of one of their cases, whose decision was sent less than six weeks ago
- **WHEN** portaliq lists the actions placed on that case
- **THEN** "Bezwaar maken" SHALL be listed, placed on `contestedCase`, before "Klacht indienen"
- @e2e exclude {a declaration; asserted in tests/Unit/Portal/PortalCasePageTest.php::testBezwaarAndKlachtArePlacedOnTheCase}

#### Scenario: The bezwaar closes after its term

- **GIVEN** a case whose decision was sent more than 42 days ago
- **WHEN** the resident opens its page
- **THEN** portaliq SHALL show the bezwaar's closing sentence instead of a button
- @e2e exclude {the window is computed by portaliq; dossiq's declaration is asserted in tests/Unit/Portal/PortalCasePageTest.php::testBezwaarAndKlachtArePlacedOnTheCase}

#### Scenario: A klacht without a case is still accepted

- **GIVEN** the "Klacht indienen" page, which opens no case
- **WHEN** the resident files a klacht
- **THEN** the klacht SHALL be accepted without `contestedCase`
- @e2e exclude {a declaration; asserted in tests/Unit/Portal/PortalContributionProviderTest.php::testEveryCitizenCreateNamingACaseGuardsIt}

## ADDED Requirements

### Requirement: A case records the day its decision was sent (REQ-CAP-001)

Sending a decision SHALL write the day it went out on its case, in the
server-set date field `decisionSentOn`. A later decision on the same case SHALL
overwrite it. A decision without a case SHALL write nothing.

#### Scenario: Sending a decision stamps its case

- **GIVEN** a case with a signed decision
- **WHEN** the decision is sent on 2026-09-01
- **THEN** the case SHALL carry `decisionSentOn` 2026-09-01
- @e2e exclude {a server-side stamp; asserted in tests/Unit/Service/RemedyTermBindingTest.php::testSendingStampsTheDaySentOnTheCase}
