# Tasks: privacy-and-social-domain-groups

- [x] 1.1 Provision `dossiq-privacy` and `dossiq-sociaal-domein`
  - Files: lib/Repair/ProvisionAssignedGroups.php
  - Test: tests/Unit/Service/Access/RolePermissionsTest.php, tests/Unit/Settings/AdminDataAccessDeclaredTest.php
- [x] 1.2 Schema blocks for avgIncident and the three social-domain schemas, register 0.20.56
  - Files: lib/Settings/register.d/92-admin-data-access.json, lib/Settings/dossiq_register.json, tests/schemas/schema-version-digests.json
  - Test: tests/Unit/Settings/AdminDataAccessDeclaredTest.php
- [x] 1.3 Role permissions from the groups, provided as initial state and read by the menu, page tabs and route guard
  - Files: lib/Service/Access/RolePermissions.php, lib/Listener/RolePermissionsInitialStateListener.php, lib/AppInfo/Registrar/OnboardingListenerRegistrar.php, src/utils/permissions.js
  - Test: tests/Unit/Service/Access/RolePermissionsTest.php, tests/vitest/rolePermissions.spec.js
- [x] 1.4 Gate the data breach register, its Privacy tab and the three social-domain pages on the roles
  - Files: src/manifest.json, src/manifest.d/70-privacy-and-procurement.json
  - Test: tests/vitest/rolePermissions.spec.js
- [x] 1.5 Gate the case page's Indication and Case meeting tabs on `sociaal-domein` (nextcloud-vue detail-tab-permission: a tab names its `permission`)
  - Files: src/menu-layout.simple.json
  - Test: tests/vitest/socialTabsPermission.spec.js
