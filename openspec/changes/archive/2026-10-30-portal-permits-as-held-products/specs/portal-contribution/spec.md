# portal-contribution Specification (delta)

## ADDED Requirements

### Requirement: REQ-PORTAL-030: The citizen contribution MUST offer the resident's permits as products tagged with their theme

Dossiq MUST record a `permit` for the resident when a case whose case type declares `issuesPermit` gets a granted decision, with the decision's validity and the details the case type names, and MUST mark it revoked when a later decision revokes it. The citizen contribution MUST declare a collection `mijnVergunningen` on schema `permit`, scoped by `portalSubject`, with `kind: products`, the permit's `theme`, and the title, validity, meta and count fields portaliq's products contract reads. It MUST NOT project a revoked permit. It MUST declare an `update` action that asks for a new licence plate by opening a change case, and MUST NOT let the portal write the permit itself.

#### Scenario: a granted resident permit shows on the theme page
- **WHEN** a "Parkeervergunning bewoners" case of resident S gets a granted decision effective 1 January 2026 until 31 December 2026
- **THEN** a permit for S exists with theme `parkeren`, title "Bewonersvergunning binnenstad", the licence plate from the case and those dates
- **AND** dossiq's citizen contribution for S lists it in `mijnVergunningen` with `kind: products`
- @e2e exclude cross-app render belongs to portaliq's ThemaOverzicht e2e; the contribution and the issuing are covered by PHPUnit

#### Scenario: a revoked permit leaves the list
- **WHEN** a later decision on that case revokes the permit
- **THEN** the permit's status is `revoked` and it is no longer in the collection
- @e2e exclude backend listener; covered by PHPUnit

#### Scenario: the resident asks for a new licence plate
- **WHEN** resident S submits "Kenteken wijzigen" with plate GZ-123-X on her permit
- **THEN** a change case is opened with the permit and the new plate, and the permit's plate is unchanged until that case is decided
- @e2e exclude cross-app write path; covered by PHPUnit and portaliq's action e2e

#### Scenario: another resident's permit
- **WHEN** resident T reads `mijnVergunningen`
- **THEN** S's permit is not returned
- @e2e exclude scope check; covered by PHPUnit
