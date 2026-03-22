## Why

Register Content Internationalization is not yet implemented in Procest. This change proposes adding this capability based on the detailed spec in `specs/register-i18n/spec.md`.

**Feature tier**: V1 (Dutch + English for type definitions), V2 (full language switching, API support, admin translation UI)

## What Changes

See `specs/register-i18n/spec.md` for full requirements and scenarios.

## Impact

- **Code**: New frontend views and/or backend services
- **Dependencies**: OpenRegister (data storage), Nextcloud platform APIs
- **Testing**: Unit tests for new services, integration tests for API endpoints
