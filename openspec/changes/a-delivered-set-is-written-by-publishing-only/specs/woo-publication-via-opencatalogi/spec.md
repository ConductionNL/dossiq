# woo-publication-via-opencatalogi Delta: a-delivered-set-is-written-by-publishing-only

**Status**: draft
**Scope**: the delivered-sets list on the Woo request case page.

## ADDED Requirements

### Requirement: A delivered set MUST be written by publishing only

The case page SHALL offer no way to create a delivered set by hand. Its empty delivered-sets list SHALL name the Publish (Woo) action as the way a set is made.

#### Scenario: A coordinator looks for the publish step

- **GIVEN** a Woo request with no delivered set
- **WHEN** the coordinator opens its delivered sets
- **THEN** there is no Add button and no form asking for a hash or a state
- **AND** the empty text reads "Vanuit deze zaak is nog niets aangeleverd voor publicatie. Publiceren (Woo) onder Meer maakt de aangeleverde set."
