# Design: Admin Settings

## Architecture
- **Backend**: `SettingsController` (PHP) for reading/writing IAppConfig keys; `SettingsService` for schema configuration
- **Frontend**: `AdminRoot.vue` (Vue 2) renders tabbed settings with `CaseTypeAdmin.vue`, `CaseTypeList.vue`, `CaseTypeDetail.vue`
- **Data**: All case type definitions stored as OpenRegister objects; settings stored in Nextcloud IAppConfig
- **Routing**: `/settings` and `/case-types` Vue router paths mapped to `AdminRoot` component

## Components
| Component | Path | Purpose |
|-----------|------|---------|
| `AdminRoot.vue` | `src/views/settings/AdminRoot.vue` | Settings page shell with tab routing |
| `CaseTypeAdmin.vue` | `src/views/settings/CaseTypeAdmin.vue` | Case type administration container |
| `CaseTypeList.vue` | `src/views/settings/CaseTypeList.vue` | Case type list view |
| `CaseTypeDetail.vue` | `src/views/settings/CaseTypeDetail.vue` | Case type detail/edit form |
| `GeneralTab.vue` | `src/views/settings/tabs/GeneralTab.vue` | General settings tab |
| `StatusesTab.vue` | `src/views/settings/tabs/StatusesTab.vue` | Status type management tab |
| `ZgwMappingSettings.vue` | `src/views/settings/ZgwMappingSettings.vue` | ZGW field mapping configuration |
| `SettingsController.php` | `lib/Controller/SettingsController.php` | API for settings CRUD |
| `SettingsService.php` | `lib/Service/SettingsService.php` | Business logic for settings |

## Data Flow
1. Admin navigates to `/settings` -> Vue router loads `AdminRoot`
2. `AdminRoot` fetches settings via `GET /api/settings`
3. Case type list fetched from OpenRegister via object store
4. Case type detail loads sub-entities (statuses, roles, results) from OpenRegister
5. Save persists to IAppConfig (settings) or OpenRegister (case type objects)
