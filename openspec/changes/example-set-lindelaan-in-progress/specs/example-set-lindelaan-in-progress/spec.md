# example-set-lindelaan-in-progress Delta

## ADDED Requirements

### Requirement: The example Woo case is in progress with its contact moments

The Zuiddrecht example set SHALL seed the Woo case "Verlichting fietspad Lindelaan" in a Woo status whose role is
in-progress, without `wooPublicationStatus`, and SHALL seed four contact moments on it, after it: an inbound telephone
call, an outbound and an inbound message through the portal (`webformulier`), and an inbound e-mail. Every channel,
direction and nature SHALL be a value the contact moment schema allows.

#### Scenario: the installed set

- **GIVEN** the Zuiddrecht example set installed
- **THEN** the case "Verlichting fietspad Lindelaan" shows the "Wat nu?" card of its in-progress stage
- **AND** its Contact tab counts 4 contact moments
