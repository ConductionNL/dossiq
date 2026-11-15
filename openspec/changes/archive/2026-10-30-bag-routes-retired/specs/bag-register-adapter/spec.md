# bag-register-adapter (delta)

## REMOVED Requirements

### Requirement: The HTTP surface MUST expose graceful "not configured" responses, never a 500

**Reason**: The three `/api/external/bag/*` routes and `BagController` had no caller in dossiq or
in any sibling app, so they were deleted. BAG lookups move to integriq (ADR-118).

**Migration**: None needed. A future caller reads BAG through integriq's BAG reader, not through a
dossiq route.
