## ADDED Requirements

### Requirement: A case type picks the side cards of its cases

A case type MAY list `sideCards`, ids of the case page's side cards. A case of
a type that lists cards SHALL show those cards, in that order, each still
following its own condition. A case of a type that lists none SHALL show the
default column: deadline, requester, handling and work instruction, then the
domain cards whose conditions hold.

#### Scenario: A case type without the setting
- GIVEN a case of a case type with no `sideCards`
- WHEN the case page opens
- THEN the side column starts with deadline, requester, handling and work instruction

#### Scenario: A case type with the setting
- GIVEN a Melding Jeugdwet case, whose case type lists the banner stack, consent and who
- WHEN the case page opens
- THEN the side column shows those cards in that order and no generic card
