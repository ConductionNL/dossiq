## ADDED Requirements

### Requirement: Anything that asks a person for something declares a queue source (REQ-QUEUE-01)

Every mechanism that can ask a person for something SHALL declare itself
as a queue source: a name, how it is read for a given person, what an item
points at, and what closes the item. The personal queue SHALL be built
from the declared sources and SHALL NOT hold its own list of queries. A
structural test SHALL fail when a mechanism that asks a person for
something declares no source and carries no reason-bearing allowlist
entry.

#### Scenario: a new mechanism reaches the queue by declaring itself
@e2e tests/e2e/one-personal-queue.spec.ts

- **GIVEN** a mechanism that asks a handler for an approval
- **WHEN** it declares a queue source
- **THEN** its items SHALL appear in that handler's queue
- **AND** no queue page SHALL be changed

#### Scenario: a mechanism that asks and declares nothing fails the build

- **GIVEN** a mechanism assigning work to a person
- **WHEN** it declares no source and is not allowlisted
- **THEN** the structural test SHALL fail
- **AND** it SHALL name the mechanism

#### Scenario: a source that cannot be read says so

- **GIVEN** a declared source whose read fails
- **WHEN** a person opens their queue
- **THEN** the queue SHALL name the source as unavailable
- **AND** it SHALL NOT silently show fewer items
