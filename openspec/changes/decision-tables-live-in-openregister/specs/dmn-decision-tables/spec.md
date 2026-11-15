# DMN decision tables

## REMOVED Requirements

### Requirement: Decisions are also invokable standalone via REST

**Reason**: the `/api/decisions` API and the settings tab are retired; tables live in OpenRegister's decision-tables register and are evaluated through its `DecisionTableStore` (decision-tables-live-in-openregister).
**Migration**: the upgrade copies the stored tables into the register as `dossiq.<key>`; author them in OpenRegister.
