# Tasks: Procest App Scaffold

## Task 1: App registration and metadata [MVP] [DONE]
- **spec_ref**: procest-app-scaffold/spec.md
- **files**: `appinfo/info.xml`, `lib/AppInfo/Application.php`
- **acceptance**: App installable with proper NC compatibility

## Task 2: Vue SPA entry and routing [MVP] [DONE]
- **spec_ref**: procest-app-scaffold/spec.md
- **files**: `src/main.js`, `src/App.vue`, `src/router/index.js`, `templates/index.php`
- **acceptance**: SPA loads with client-side routing

## Task 3: Navigation sidebar [MVP] [DONE]
- **spec_ref**: procest-app-scaffold/spec.md
- **files**: `src/navigation/MainMenu.vue`
- **acceptance**: Dashboard, My Work, Cases, Tasks, Settings navigation

## Task 4: ZGW auth middleware [MVP] [DONE]
- **spec_ref**: procest-app-scaffold/spec.md
- **files**: `lib/Middleware/ZgwAuthMiddleware.php`
- **acceptance**: JWT auth on ZGW endpoints

## Task 5: Build system [MVP] [DONE]
- **spec_ref**: procest-app-scaffold/spec.md
- **files**: `webpack.config.js`, `package.json`, `composer.json`
- **acceptance**: npm build produces working JS bundles

## Task 6: Unit tests (ADR-009) [DONE]
- **spec_ref**: ADR-009
- **files**: `tests/Unit/Service/SettingsServiceTest.php`
- **acceptance**: Settings service tests pass

## Task 7: Documentation (ADR-010) [DONE]
- **spec_ref**: ADR-010
- **files**: `docs/`, `DEVELOPMENT.md`
- **acceptance**: Development docs available

## Task 8: i18n support (ADR-005) [DONE]
- **spec_ref**: ADR-005
- **files**: `l10n/en.json`, `l10n/nl.json`
- **acceptance**: All UI strings in English and Dutch
