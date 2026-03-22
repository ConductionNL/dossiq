# Design: Case Types

## Architecture
- **Data model**: CaseType entity as CMMN CaseDefinition, with linked status types, role types, result types, property definitions, document types
- **Storage**: OpenRegister objects in procest register
- **Behavioral controls**: Deadline derivation, confidentiality defaults, status constraints, required fields per status
- **Lifecycle**: Draft -> Published -> Expired, with versioning support

## Components
| Component | Path | Purpose |
|-----------|------|---------|
| `CaseTypeAdmin.vue` | `src/views/settings/CaseTypeAdmin.vue` | Case type administration |
| `CaseTypeList.vue` | `src/views/settings/CaseTypeList.vue` | Case type listing |
| `CaseTypeDetail.vue` | `src/views/settings/CaseTypeDetail.vue` | Case type detail/edit |
| `GeneralTab.vue` | `src/views/settings/tabs/GeneralTab.vue` | General case type settings |
| `StatusesTab.vue` | `src/views/settings/tabs/StatusesTab.vue` | Status type management |

## Validation
- `src/utils/caseTypeValidation.js` — case type validation rules
