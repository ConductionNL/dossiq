## ADDED Requirements

### Requirement: Publishing MUST refuse a case type with an empty required field (REQ-CTPV-REQ-1)

A case type MUST NOT be published while its title, purpose, trigger, subject,
processing deadline, origin, confidentiality or responsible unit is empty. Each
empty field MUST be its own finding, and a translatable field counts as filled
when any language holds text. A draft MAY be saved with these fields empty.

#### Scenario: a draft without a responsible unit
- GIVEN a draft case type with every other required field filled
- WHEN an administrator publishes it
- THEN publication is refused with the finding "Give the case type a responsible unit."
- AND nothing is written

#### Scenario: a purpose in English only
- GIVEN a draft whose purpose holds text in English and none in Dutch
- WHEN an administrator publishes it
- THEN the purpose produces no finding
