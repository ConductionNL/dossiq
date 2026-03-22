## Why

StUF Protocol Support Specification is not yet implemented in Procest. This change proposes adding this capability based on the detailed spec in `specs/stuf-support/spec.md`.

**Feature tier**: V1 (outbound StUF via OpenConnector), V2 (inbound StUF endpoints in Procest)

## What Changes

See `specs/stuf-support/spec.md` for full requirements and scenarios.

## Impact

- **Code**: New frontend views and/or backend services
- **Dependencies**: OpenRegister (data storage), Nextcloud platform APIs
- **Testing**: Unit tests for new services, integration tests for API endpoints
