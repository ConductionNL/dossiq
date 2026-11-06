# portal-contribution Specification (delta)

**Status**: planned
**Scope**: dossiq
**OpenSpec changes**:
- [portal-woo-request-form-takes-dutch-input](../../) (this delta)

## ADDED Requirements

### Requirement: The Woo request form asks dates as dates and the requester as a choice (REQ-WDI-004)

Both Woo request actions (`startWooVerzoek`, `startWooVerzoekAlgemeen`) SHALL declare `periodeVan` and `periodeTot` with `input: date`, and `verzoekerType` as choices whose Dutch words (Inwoner, Journalist, Organisatie) stand over the values the route keeps.

#### Scenario: The period step
- GIVEN a resident opens the Woo request from the overview tile or from a dossier
- WHEN she reaches the period step
- THEN the two dates are asked as dates
- @e2e exclude pinned by `PortalContributionProviderTest::testTheWooRequestAsksDatesAsDatesAndTheRequesterAsAChoice`; the rendering is portaliq's
