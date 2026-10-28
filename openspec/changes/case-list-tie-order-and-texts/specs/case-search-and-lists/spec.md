# case-search-and-lists

## ADDED Requirements

### Requirement: Cases with the same deadline keep one order

Every deadline order the case list declares MUST order cases with the same deadline by when they were received,
earliest first.

#### Scenario: Two cases due the same day

- GIVEN 2026-4865 received 23 Oct and 2026-4866 received 24 Oct, both due 3 Nov
- WHEN the list is sorted by deadline
- THEN 2026-4865 comes first

### Requirement: The team queue deadline reads late under five days

The team queue MUST read a deadline fewer than five days away as late and one up to seven days away as soon.

#### Scenario: Four and six days left

- GIVEN queue rows due in four and in six days
- WHEN the team queue renders
- THEN the first reads late and the second soon
