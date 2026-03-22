## Why

B&W Parafering & Besluitvorming Specification is not yet implemented in Procest. This change proposes adding this capability based on the detailed spec in `specs/bw-parafering/spec.md`.

**Feature tier**: V1 (ambtelijk parafering, sequential routing, audit trail), V2 (parallel parafering, mobile parafering, iBabs/NotuBiz connector, vergaderbeheer)

## What Changes

See `specs/bw-parafering/spec.md` for full requirements and scenarios.

## Impact

- **Code**: New frontend views and/or backend services
- **Dependencies**: OpenRegister (data storage), Nextcloud platform APIs
- **Testing**: Unit tests for new services, integration tests for API endpoints
