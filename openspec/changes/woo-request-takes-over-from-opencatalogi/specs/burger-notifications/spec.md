# burger-notifications (delta)

## ADDED Requirements

### Requirement: A case type declares where its sender's address is kept (REQ-ACK-ADDR-001)

The `acknowledgement` declaration on a case type SHALL accept `addressFields`, a list of dot paths
on the case. `CaseTypeAcknowledgement::addressesOn()` SHALL read the e-mail addresses at those
paths, in declared order, skipping a value that is not an address, and the acknowledgement SHALL
try them before the general contact fields (`email`, `initiator`, `betrokkenen`, `contacts`), none
of which the case schema declares. The Woo case type SHALL declare `wooRequest.verzoekerEmail`
(configuration, decision 182).

#### Scenario: A Woo request from the portal form is confirmed with its start and due date
- **GIVEN** a case written by `WooRequestIntake::receive()` from the portal form, received Friday 2026-11-27 10:00, its term ending 2026-12-28, the requester's address only in `wooRequest.verzoekerEmail`
- **WHEN** the acknowledgement is sent
- **THEN** it SHALL go to that address and its text SHALL name the day it was received, the day the term starts and 2026-12-28

#### Scenario: Without the declaration the duty is refused, not sent nowhere
- **GIVEN** the same case on a case type that declares no `addressFields`
- **WHEN** the acknowledgement is attempted
- **THEN** it SHALL be refused with `acknowledgement-no-address`
