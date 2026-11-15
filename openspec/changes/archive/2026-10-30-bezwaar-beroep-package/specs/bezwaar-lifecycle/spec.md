# bezwaar-lifecycle delta: bezwaar-beroep-package

## REMOVED Requirements

### Requirement: SeedBezwaarBeroepData SHALL seed shared reference data for bezwaar + beroep

**Reason**: The bezwaar and beroep case types are a case definition package now (hydra ADR-118). The repair step seeded nothing since the data was parked, and is deleted.

**Migration**: `lib/Settings/packages/bezwaar-beroep/`, installed on request by the package installer (case-types REQ-CT-50). `occ dossiq:package:install bezwaar-beroep` or the setup action `seed`.

### Requirement: SeedBezwaarWorkflowDefinition SHALL seed the canonical bezwaar workflow definition

**Reason**: It looked for a case type identifier no seed wrote, so it never ran. The package carries the bezwaar flow, published, with fixed status ids.

**Migration**: `lib/Settings/packages/bezwaar-beroep/flows/bezwaar.json` (case-types REQ-CT-50).
