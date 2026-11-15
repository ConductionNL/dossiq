## ADDED Requirements

### Requirement: The overview greets and names its lists (REQ-ROD-001)
The citizen contribution's `overzicht` page MUST open with a portaliq `greeting` block without
the date, so the page greets the resident by the time of day and their first name. Its `tasks`
block MUST carry the label "Wat u nog moet doen", its `cases` block "Lopende zaken" and its
`inbox` block "Nieuwe berichten". Nothing else on the page MUST change.

#### Scenario: Sanne opens her overview in the afternoon
@e2e exclude PHPUnit tests/Unit/Portal/PortalContributionProviderTest.php reads the declaration; the words are portaliq's (GreetingBlock) and the coordinator sees them live
- **GIVEN** the Zuiddrecht example resident, signed in at 14:00
- **WHEN** the overview opens
- **THEN** it reads "Goedemiddag, Sanne", then "Wat u nog moet doen", "Lopende zaken" and "Nieuwe berichten" above their lists

#### Scenario: Only the declaration changes
@e2e exclude PHPUnit tests/Unit/Portal/PortalCasePageTest.php pins the case page's blocks
- **GIVEN** the citizen contribution
- **WHEN** the pages are built
- **THEN** the case page's blocks are as before, in the same order
