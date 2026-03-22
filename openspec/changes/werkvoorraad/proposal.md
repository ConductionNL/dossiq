## Why

Werkvoorraad (Work Queue) Specification is not yet implemented in Procest. This change proposes adding this capability based on the detailed spec in `specs/werkvoorraad/spec.md`.

**Feature tier**: V1 (team overview, unassigned queue, reassignment), V2 (workload balancing, capacity planning, SLA monitoring)

## What Changes

See `specs/werkvoorraad/spec.md` for full requirements and scenarios.

## Impact

- **Code**: New frontend views and/or backend services
- **Dependencies**: OpenRegister (data storage), Nextcloud platform APIs
- **Testing**: Unit tests for new services, integration tests for API endpoints
