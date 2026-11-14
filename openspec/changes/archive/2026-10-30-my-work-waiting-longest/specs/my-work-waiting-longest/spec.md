## ADDED Requirements

### Requirement: The waiting tile says how long the longest wait is

The "Waiting on the applicant" tile on the simple Mijn werk SHALL caption its count with the whole days since the
waiting case that entered its current status first did so, over the same cases the tile counts. Without a wait of
a day or more the caption SHALL be left out.

#### Scenario: Pieter's longest wait
- **GIVEN** Pieter has four cases waiting on the applicant, the oldest in its status since 9 days ago
- **WHEN** he opens Mijn werk
- **THEN** the tile reads 4 with "langste: 9 dagen" under it
- @e2e exclude the day count is nextcloud-vue's caption metric, unit-tested there against a fixed clock; the configuration is pinned by tests/vitest/waitingLongestCaption.spec.js

#### Scenario: Nobody waits
- **GIVEN** Pieter has no case waiting on the applicant
- **WHEN** he opens Mijn werk
- **THEN** the tile reads 0 and shows no caption
- @e2e exclude as above
