# subsidie-settlement-case-costs Specification

## Purpose
What settling a grant does to the case it belongs to. Under ADR-081 and decision 258 the settled amount stays on the vaststelling and the case gets no cost line; Shillinq is the only general ledger.

## Requirements

### Requirement: Settling a grant records the settled amount on the vaststelling and writes nothing to the linked case (REQ-SSC-005)

`VaststellingService::finalize()` SHALL store the settled amount (`determinedAmount`) and status `determined` on the vaststelling itself. It SHALL NOT write the case linked through `subsidieUitvoering`, `subsidieAanvraag` and `case`, and SHALL NOT append any entry to that case's `kosten` (ADR-081, decision 258: Shillinq is the only general ledger).

#### Scenario: A settlement leaves the linked case unchanged
@e2e exclude {backend settlement with no screen of its own; asserted in tests/Unit/Service/Subsidie/VaststellingServiceTest.php}

- **GIVEN** a vaststelling linked through its subsidieUitvoering and subsidieAanvraag to case `case-1`
- **WHEN** `finalize()` settles it at a positive amount
- **THEN** the vaststelling SHALL hold that amount as `determinedAmount` with status `determined`
- **AND** `case-1` SHALL be unchanged, with no `kosten` field added

#### Scenario: A settlement with no linked case still completes
@e2e exclude {backend settlement with no screen of its own; asserted in tests/Unit/Service/Subsidie/VaststellingServiceTest.php}

- **GIVEN** a vaststelling whose subsidieAanvraag has no `case` link
- **WHEN** `finalize()` runs
- **THEN** the vaststelling SHALL be patched to status `determined`
- **AND** no case SHALL be written
