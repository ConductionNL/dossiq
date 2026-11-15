# bag-register-adapter (delta)

## REMOVED Requirements

### Requirement: The BAG adapter MUST be selected by configuration, defaulting to no external calls

**Reason**: Dossiq's BAG client is deleted (ADR-118). BAG reads go through integriq.

**Migration**: Set `integration.bag.check` to `on` for the location check; the `integration.bag.*` keys are no longer read.

### Requirement: Address lookup MUST validate Dutch postcode format before any outbound call

**Reason**: The address lookup had no caller once the BAG routes left (change bag-routes-retired).

**Migration**: None.

### Requirement: Object lookup MUST distinguish not-found from transport failure

**Reason**: Moved to integriq's `BagObjectReader` (change bag-object-read in integriq).

**Migration**: None for users; the location check reads through integriq.

### Requirement: The normalization mapper MUST be a pure, independently-testable unit

**Reason**: `BagResponseMapper` is deleted with the client.

**Migration**: None.
