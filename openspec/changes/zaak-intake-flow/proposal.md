## Why

Zaak Intake Flow Specification is not yet implemented in Procest. This change proposes adding this capability based on the detailed spec in `specs/zaak-intake-flow/spec.md`.

**Feature tier**: MVP (manual + API intake, zaaktype assignment, status init, behandelaar notification), V1 (Open Formulieren integration, DSO intake, duplicate detection, batch intake, e-mail intake)

## What Changes

See `specs/zaak-intake-flow/spec.md` for full requirements and scenarios.

## Impact

- **Code**: New frontend views and/or backend services
- **Dependencies**: OpenRegister (data storage), Nextcloud platform APIs
- **Testing**: Unit tests for new services, integration tests for API endpoints
