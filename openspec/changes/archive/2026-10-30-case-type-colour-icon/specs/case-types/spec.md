# case-types Delta

**Status**: proposed
**OpenSpec changes**:

- [case-type-colour-icon](../../)

## ADDED Requirements

### Requirement: A case type carries a colour and an icon

A case type SHALL have an optional `colour` from the hue list `statusType.colour` uses (which includes `teal`) and an
optional `icon` naming a registered icon. Every shipped case type SHALL name both. The case type form and card SHALL
offer the colour as a select with a swatch per hue and the icon as a select that shows each icon. The type pill on a
case, the case type tag in the case lists and a pinned case type's menu entry SHALL show the case type's colour and
icon.

#### Scenario: a Woo request

- **GIVEN** a case of the shipped Woo-verzoek case type
- **THEN** its type pill is red with the document icon
- **AND** the Woo-verzoek entry in the menu shows the document icon

#### Scenario: a case type without them

- **GIVEN** a case type with no colour and no icon
- **THEN** its pill and tags draw as before and its menu entry shows the filter icon
