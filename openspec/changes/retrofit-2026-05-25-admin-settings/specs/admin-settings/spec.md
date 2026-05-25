---
retrofit_extensions:
  - REQ-ADMIN-016
  - REQ-ADMIN-017
---

# Admin Settings — controller + service runtime (retrofit)

## Requirements

### REQ-ADMIN-016: SettingsController SHALL expose `index`, `create`, and `load` JSON endpoints for the admin UI runtime

`OCA\Procest\Controller\SettingsController` SHALL expose three action endpoints:
- `index()` — `#[NoAdminRequired]`. SHALL return `{success: true, openRegisters: <bool>, isAdmin: <bool>, config: <SettingsService::getSettings()>}` so the admin Vue app can render itself for both admins and non-admins (read-only view).
- `create()` — admin-only (no `#[NoAdminRequired]`). SHALL take the raw request params, delegate to `SettingsService::updateSettings($data)`, and return `{success: true, config: <updated>}`.
- `load()` — admin-only. SHALL force a fresh re-import of the procest register from `procest_register.json` via `SettingsService::loadConfiguration(force: true)` and return the raw result envelope from that service.

#### Scenario: index reports admin status correctly
- **GIVEN** a logged-in user `alice` who is a member of the `admin` group
- **WHEN** `GET /apps/procest/api/settings` (index) is invoked
- **THEN** the response SHALL contain `"isAdmin": true`
- **AND** SHALL contain `"openRegisters": true` if the `openregister` app is installed

#### Scenario: index works for non-admins
- **GIVEN** a logged-in user `bob` who is NOT in the `admin` group
- **WHEN** `index()` is invoked
- **THEN** the response SHALL contain `"isAdmin": false`
- **AND** SHALL still return the current `config` so the Vue app renders in read-only mode

#### Scenario: load forces a fresh register re-import
- **WHEN** an admin invokes `load()`
- **THEN** the controller SHALL call `SettingsService::loadConfiguration(force: true)`
- **AND** SHALL return the result envelope unchanged (no `success: true` wrapper)

### REQ-ADMIN-017: SettingsService SHALL be the single resolver for OpenRegister wiring and SHALL persist all Procest config as IAppConfig key/value pairs

`OCA\Procest\Service\SettingsService` SHALL provide the central OpenRegister resolver and IAppConfig persistence layer for Procest. The service SHALL expose:
- `isOpenRegisterAvailable(): bool` — returns true iff the `openregister` app is installed AND the `OCA\OpenRegister\Service\ObjectService` class can be resolved from the DI container.
- `getObjectService(): ?object` — returns the resolved `ObjectService`, or `null` (NOT throw) when OpenRegister is unavailable. Per ADR-022, every Procest data-access call SHALL obtain its `ObjectService` through this single resolver.
- `loadConfiguration(bool $force = false): array` — idempotent register import. SHALL read `procest_register.json`, import it via the OpenRegister `ConfigurationService`, auto-configure every schema and register ID returned, and persist them via `setConfigValue()`. When `$force` is true, SHALL re-import unconditionally; otherwise SHALL skip when the persisted version matches the manifest version.
- `getSettings(): array` / `updateSettings(array $data): array` — bulk read/write of the Procest config namespace.
- `getConfigValue(string $key, string $default = ''): string` / `setConfigValue(string $key, string $value): void` — single-key accessors backed by `IAppConfig` under the `procest` app namespace.

#### Scenario: getObjectService returns null when OpenRegister is uninstalled
- **GIVEN** the `openregister` app is not installed
- **WHEN** `getObjectService()` is called
- **THEN** the method SHALL return `null` (NOT throw)
- **AND** `isOpenRegisterAvailable()` SHALL return `false`

#### Scenario: loadConfiguration is idempotent without --force
- **GIVEN** the persisted `procest_register_version` equals the version in `procest_register.json`
- **WHEN** `loadConfiguration()` is called with default `$force = false`
- **THEN** the service SHALL skip the re-import and return the cached configuration envelope

#### Scenario: loadConfiguration(force: true) re-imports unconditionally
- **GIVEN** the persisted version equals the manifest version (no diff)
- **WHEN** `loadConfiguration(force: true)` is called
- **THEN** the service SHALL still call `ConfigurationService::importFromApp(...)`
- **AND** SHALL refresh every persisted schema/register ID from the import result

#### Scenario: config keys survive process restart
- **WHEN** `setConfigValue('register', 'procest')` is called
- **AND** a new request hits the process pool
- **THEN** `getConfigValue('register')` SHALL return `'procest'`

#### Notes
- Both `getObjectService()` and `getConfigurationService()` look up services from the DI container at call time (NOT injection-time) — this is deliberate so Procest can boot even when `openregister` is not yet installed.
- ADR-022 calls out that every register-aware service in Procest MUST go through `SettingsService::getObjectService()` rather than wiring its own ObjectService injection.
