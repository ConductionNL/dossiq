## Why

Zaaktype Configuratie Specification is not yet implemented in Procest. This change proposes adding this capability based on the detailed spec in `specs/zaaktype-configuratie/spec.md`.

**Feature tier**: V1 (basic CRUD UI, status diagram editor, document type config, property definition config, role type config, result type config), V2 (visual flow designer, import/export, ZTC sync, versioning, test mode)

## What Changes

See `specs/zaaktype-configuratie/spec.md` for full requirements and scenarios.

## Impact

- **Code**: New frontend views and/or backend services
- **Dependencies**: OpenRegister (data storage), Nextcloud platform APIs
- **Testing**: Unit tests for new services, integration tests for API endpoints
