## ADDED Requirements

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

## REMOVED Requirements

### Requirement: VaststellingService::finalize() SHALL append a subsidy_disbursement kosten entry to the linked case

**Reason:** ADR-081 and decision 258. A domain app does not hold a ledger-shaped array; Shillinq is the only general ledger. `finalize()` no longer appends to `kosten`.

**Migration:** the settled amount stays on the vaststelling (`determinedAmount`). Cost reporting reads it from Shillinq once the cost allocation exists.

### Requirement: The kosten append SHALL be idempotent per vaststelling and fail-soft on every unresolvable link

**Reason:** there is no append left to make idempotent. The fail-soft case that still matters (no linked case) is covered by REQ-SSC-005.

**Migration:** none.

### Requirement: Iv3ReportService SHALL count subsidy_disbursement entries toward totalCosts

**Reason:** `Iv3ReportService` is gone under ADR-081 and no `subsidy_disbursement` entry is written.

**Migration:** none in dossiq. IV3 cost reporting belongs to Shillinq.
