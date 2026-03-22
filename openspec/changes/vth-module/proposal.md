## Why

VTH Module Specification is not yet implemented in Procest. This change proposes adding this capability based on the detailed spec in `specs/vth-module/spec.md`.

**Feature tier**: V1 (DSO intake, permit workflow, basic checklists, advice management), V2 (enforcement strategies, supervision planning, mobile inspection, LHS matrix, risk-based scheduling)

## What Changes

See `specs/vth-module/spec.md` for full requirements and scenarios.

## Impact

- **Code**: New frontend views and/or backend services
- **Dependencies**: OpenRegister (data storage), Nextcloud platform APIs
- **Testing**: Unit tests for new services, integration tests for API endpoints
