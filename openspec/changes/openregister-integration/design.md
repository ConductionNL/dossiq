# Design: openregister-integration

## Changes

### store.js — Data-driven schema registration
Replace 13 repetitive `if (config.register && config.X_schema)` blocks with a `SCHEMA_REGISTRATIONS` array mapping type names to config keys. Loop registers all 27 schemas. Debug log reports how many were registered.

### openregisterCheck.js — Frontend availability check
New utility providing `checkOpenRegisterStatus()` and `getStatusMessage()`. Queries the settings API to determine if OpenRegister is available and if the Procest register is configured.
