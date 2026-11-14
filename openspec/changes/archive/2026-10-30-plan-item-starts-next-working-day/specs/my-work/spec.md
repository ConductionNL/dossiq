# my-work Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [plan-item-starts-next-working-day](../../)

## ADDED Requirements

### Requirement: A planned item starts at the next working day at 10:00

The "Plan an item" dialog SHALL open with "When" set to the next working day after today at 10:00, the working day read
from the organisation's working-day calendar (the built-in list when no calendar answers). The reader SHALL be able to
change it before planning.

#### Scenario: On a Friday

- GIVEN today is Friday 9 October 2026 and the weekend is not worked
- WHEN the reader opens "Iets inplannen"
- THEN "Wanneer" reads Monday 12 October 2026 10:00

#### Scenario: Before a closure day

- GIVEN today is Monday and the organisation is closed on Tuesday
- THEN "Wanneer" reads Wednesday 10:00
