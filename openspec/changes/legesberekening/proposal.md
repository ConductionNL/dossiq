## Why

Legesberekening Specification is not yet implemented in Procest. This change proposes adding this capability based on the detailed spec in `specs/legesberekening/spec.md`.

**Feature tier**: V1 (basic calculation, single verordening, manual export), V2 (multiple verordeningen, automatic DSO import, 4-ogen principe, versioned calculations, financial system connectors)

## What Changes

See `specs/legesberekening/spec.md` for full requirements and scenarios.

## Impact

- **Code**: New frontend views and/or backend services
- **Dependencies**: OpenRegister (data storage), Nextcloud platform APIs
- **Testing**: Unit tests for new services, integration tests for API endpoints
