## ADDED Requirements

### Requirement: You follow a case you do not own (REQ-CM-40)

`#CaseDetail` SHALL offer Follow, creating the platform's subscription of
the signed-in user to the case, and Unfollow. `#Cases` SHALL carry a lens
Followed and `#MyWorkHome` a tile Cases I follow, both over the platform's
subscribed-by-me query. The People tab SHALL list the followers.

#### Scenario: Follow a colleague's case
@e2e tests/e2e/case-followers.spec.ts

- **GIVEN** a case assigned to Anna
- **WHEN** you press Follow
- **THEN** the case SHALL appear under Followed on Cases
- **AND** you SHALL be listed under Followers on the People tab

#### Scenario: A follower hears
@e2e tests/e2e/case-followers.spec.ts

- **GIVEN** you follow the case
- **WHEN** Anna changes its status
- **THEN** you SHALL receive the platform's notification for it

#### Scenario: Unfollow
@e2e tests/e2e/case-followers.spec.ts

- **GIVEN** you follow the case
- **WHEN** you press Unfollow
- **THEN** it SHALL leave the Followed lens
