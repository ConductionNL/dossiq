# Design: OpenRegister Integration

## Architecture
- **Pattern**: Thin client — Procest owns no database tables
- **Register**: Dedicated `procest` register with schemas for all entity types
- **Repair step**: `InitializeSettings` creates/detects register and schemas on enable
- **Frontend**: Pinia stores query OpenRegister API directly via `createObjectStore`
- **RBAC**: OpenRegister handles access control

## Data Model (OpenRegister Schemas)
| Schema | Entity | Purpose |
|--------|--------|---------|
| `case` | Case | Core case entity |
| `task` | Task | Work items within cases |
| `statusType` | StatusType | Status lifecycle definitions |
| `caseType` | CaseType | Case type configurations |
| `roleType` | RoleType | Role definitions |
| `resultType` | ResultType | Result definitions |
| `decisionType` | DecisionType | Decision type definitions |

## Configuration
- Register/schema IDs stored in IAppConfig via `SettingsService`
- `lib/Settings/procest_register.json` — OpenAPI 3.0.0 format register definition
- Imported via `ConfigurationService::importFromApp()` in repair step
