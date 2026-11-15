# status-transition-engine (delta)

## ADDED Requirements

### Requirement: A transition can require delegated authority, as decidiq answers it

The engine SHALL offer a guard type `delegatedAuthorityGuard` that passes only when decidiq's delegatie- en mandaatregister answers that the acting person holds the authority for the act the guard names. The act is the guard's `act`, or the value of the case field or case-type property named by `actField`. The actor is the case field named by `actorField` (default `signatory`), or the current user when the case names nobody. When `amountField` is set, its value in euro cents is passed as euro. The guard type `mandaatGuard` MUST stay answered by the same guard, reading the act from `mandaatCategorie`.

#### Scenario: An authorised signatory passes

- GIVEN a case whose signatory decidiq reports as authorised for the act
- WHEN the guarded transition is attempted
- THEN the guard passes and records the covering allocation

#### Scenario: Every uncertain answer refuses

- GIVEN decidiq is not installed, throws, answers `no-allocation`, `over-ceiling` or `register-unreadable`, or the case names no act, or the amount is not a number
- WHEN the guarded transition is attempted
- THEN the guard refuses with a sentence for that reason, and never passes silently
