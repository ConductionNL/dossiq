## Why

Mobiel Inspectie Specification is not yet implemented in Procest. This change proposes adding this capability based on the detailed spec in `specs/mobiel-inspectie/spec.md`.

**Feature tier**: V2 (online PWA with photo/GPS), V3 (offline capability, sync queue, field signatures)

## What Changes

See `specs/mobiel-inspectie/spec.md` for full requirements and scenarios.

## Impact

- **Code**: New frontend views and/or backend services
- **Dependencies**: OpenRegister (data storage), Nextcloud platform APIs
- **Testing**: Unit tests for new services, integration tests for API endpoints
