# case-management

## MODIFIED Requirements

### Requirement: You follow a case you do not own (REQ-CM-40)

`#CaseDetail` SHALL offer one Follow control, creating the platform's follow of the signed-in user on the case, and Unfollow. While you follow, the control SHALL offer a notifications switch that turns the notifications of your follow on or off and keeps the follow. A new follow SHALL notify. Every case list SHALL offer a Follow row action. `#Cases` SHALL carry a lens Following and `#MyWorkHome` a tile Cases you follow, both over the platform's followed-by-me query, quiet follows included. The People tab SHALL list the followers. When a case is assigned to a user, that user SHALL follow it with notifications on. There SHALL be no separate star.

#### Scenario: Follow a colleague's case
@e2e tests/e2e/case-followers.spec.ts

- **GIVEN** a case assigned to Anna
- **WHEN** you press Follow
- **THEN** the case SHALL appear under Following on Cases
- **AND** you SHALL be listed under Followers on the People tab

#### Scenario: A follower hears
@e2e tests/e2e/case-followers.spec.ts

- **GIVEN** you follow the case with notifications on
- **WHEN** Anna changes its status
- **THEN** you SHALL receive the platform's notification for it

#### Scenario: Unfollow
@e2e tests/e2e/case-followers.spec.ts

- **GIVEN** you follow the case
- **WHEN** you press Unfollow
- **THEN** it SHALL leave the Following lens

#### Scenario: A quiet follow from the case page
@e2e tests/e2e/case-number-and-favourites.spec.ts

- **GIVEN** a case page with no star on it
- **WHEN** you press Follow and then the bell beside it
- **THEN** the case SHALL stay followed after a reload
- **AND** the bell SHALL read notifications off

#### Scenario: Following leaves the case untouched
@e2e tests/e2e/case-number-and-favourites.spec.ts

- **GIVEN** a case with a known version
- **WHEN** you follow it and read it back
- **THEN** the case SHALL carry the same version

#### Scenario: You follow a case from a list row
@e2e tests/e2e/case-number-and-favourites.spec.ts

- **GIVEN** the Cases list
- **WHEN** you use Follow or stop following on a row
- **THEN** that case SHALL appear under the Following lens

#### Scenario: The assignee follows the case
@e2e exclude {the follow is written by OpenRegister's AssigneeFollowListener on save; asserted in openregister tests/Unit/Listener/AssigneeFollowListenerTest.php, dossiq only declares x-openregister-role on the property}

- **GIVEN** a case
- **WHEN** it is assigned to Anna
- **THEN** Anna SHALL follow it with notifications on

## ADDED Requirements

### Requirement: Following and recently opened are lenses and tiles (REQ-FAV-03)

The Cases index SHALL offer a Following chip and a Recently opened chip, each narrowing the list through OpenRegister's own lens rather than a dossiq query. The Dashboard SHALL carry a Cases you follow tile and a Recently opened tile over the same two lenses, each linking through to the matching chip. There SHALL be no Favourites chip and no favourites tile.

**Feature tier**: MVP

#### Scenario: The Following chip lists what you follow, quiet follows included
@e2e tests/e2e/case-number-and-favourites.spec.ts

- **GIVEN** a case you follow with notifications off and a case you stopped following
- **WHEN** you pick the Following chip on Cases
- **THEN** the list SHALL hold the first case and not the second

#### Scenario: The Recently opened chip leads with the last case you read
@e2e tests/e2e/case-number-and-favourites.spec.ts

- **GIVEN** three cases you opened, the last of them case C
- **WHEN** you pick the Recently opened chip on Cases
- **THEN** case C SHALL be the first row

#### Scenario: The dashboard tiles show the same two lists
@e2e tests/e2e/case-number-and-favourites.spec.ts

- **GIVEN** a case you follow and a case you opened
- **WHEN** you open the Dashboard
- **THEN** the Cases you follow tile SHALL show
- **AND** the Recently opened tile SHALL show

## REMOVED Requirements

### Requirement: You star a case and it stays starred for you alone (REQ-FAV-01)

**Reason**: Following and favourites are one feature (Ruben, 9 October 2026, on DqMijnWerk; OpenRegister `merge-follow-and-favourites`). A star is now a follow with notifications off, and who follows a case is visible to whoever may update it.

**Migration**: OpenRegister moves every star into its follows with notifications off. The star on the case page, the Favourites chip, the tile and the row action are replaced by REQ-CM-40 and REQ-FAV-03.

### Requirement: Favourites and recently opened are lenses and tiles (REQ-FAV-02)

**Reason**: The Favourites chip and tile went with the star. Following and Recently opened carry on as REQ-FAV-03.

**Migration**: The Favourites chip is gone; the Following chip answers the same question and includes every former favourite. The dashboard tile keeps its widget id `favourite-cases` and now reads `_watching`.
