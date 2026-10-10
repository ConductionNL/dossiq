# case-access-control

## MODIFIED Requirements

### Requirement: A colleague follows a case they do not handle (REQ-ACC-09)

A case SHALL offer one Follow control and Unfollow, taking the platform's follow of the signed in user on that case, with a switch that turns the notifications of that follow on or off. The Cases page SHALL carry a Following lens over the platform's followed by me query, and the People tab SHALL list every follower, quiet follows included, so that interest in a case is visible to the person handling it. A favourite is a follow with notifications off and is listed the same way.

Following SHALL grant nothing. It is a subscription, not a right.

Built by `case-followers`, requirement REQ-CM-40, and amended by `one-follow-control`.

#### Scenario: Follow survives a reload
@e2e tests/e2e/case-followers.spec.ts

- **GIVEN** a case assigned to somebody else
- **WHEN** you press Follow and reload the page
- **THEN** the case SHALL still be followed

#### Scenario: The People tab names who follows the case
@e2e tests/e2e/case-followers.spec.ts

- **GIVEN** a case you follow
- **WHEN** a handler opens the People tab
- **THEN** you SHALL be listed under the followers

#### Scenario: A quiet follow is still listed
@e2e exclude {the follower list is OpenRegister's GET .../watchers, which lists every row of openregister_watchers whatever its notify switch; asserted in openregister tests/Unit/Service/Interaction/WatcherServiceTest.php}

- **GIVEN** a colleague who follows a case with notifications off
- **WHEN** the handler opens the People tab
- **THEN** the colleague SHALL be listed under the followers
