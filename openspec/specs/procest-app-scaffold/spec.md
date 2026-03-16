# procest-app-scaffold Specification

## Purpose
Define the Nextcloud app scaffolding, build system, translation setup, and admin settings for the Procest case management app. This capability establishes the foundational structure that all other capabilities build upon.

## ADDED Requirements

### Requirement: App MUST be a valid Nextcloud app
The Procest app MUST be installable as a standard Nextcloud app with proper metadata, namespace, and dependency declarations.

#### Scenario: App registration
- GIVEN the Procest app directory exists in apps-extra
- WHEN Nextcloud scans for available apps
- THEN the app MUST appear in the apps list with id `procest`, name "Procest", and namespace `Procest`
- AND it MUST declare compatibility with Nextcloud 28-33
- AND it MUST declare PHP 8.1+ as minimum requirement

#### Scenario: App enable
- GIVEN Nextcloud is running and OpenRegister is installed
- WHEN an admin enables the Procest app
- THEN the app MUST activate without errors
- AND it MUST register a navigation entry in the top bar

### Requirement: App MUST provide a single-page application entry point
The app MUST serve a Vue 2 SPA from a dashboard controller that mounts to the `#content` element.

#### Scenario: Dashboard page load
- GIVEN the app is enabled and a user is logged in
- WHEN the user navigates to `/apps/procest/`
- THEN the server MUST return an HTML page with a `#content` mount point
- AND the page MUST load the `procest-main.js` webpack bundle
- AND the Vue app MUST initialize with Pinia state management

### Requirement: App MUST use webpack build system extending Nextcloud base config
The build system MUST extend `@nextcloud/webpack-vue-config` with two entry points.

#### Scenario: Build produces correct bundles
- GIVEN the source files exist in `src/`
- WHEN `npm run build` is executed
- THEN it MUST produce `js/procest-main.js` for the dashboard SPA
- AND it MUST produce `js/procest-settings.js` for the admin settings page

### Requirement: App MUST support multilingual translations
All user-facing strings MUST be wrapped in translation functions with English as the primary language and Dutch included.

#### Scenario: English translation
- GIVEN a user with English locale
- WHEN viewing the Procest app
- THEN all UI text MUST be displayed in English

#### Scenario: Dutch translation
- GIVEN a user with Dutch locale
- WHEN viewing the Procest app
- THEN all UI text MUST be displayed in Dutch

#### Scenario: Translation function usage
- GIVEN any Vue component with user-facing text
- WHEN the component renders
- THEN all strings MUST use `t('procest', 'key')` in templates
- AND all PHP strings MUST use `$this->l->t('key')`

### Requirement: App MUST provide admin settings page
The app MUST register an admin settings section for register/schema configuration.

#### Scenario: Settings page access
- GIVEN an admin user
- WHEN navigating to `/settings/admin/procest`
- THEN the admin settings page MUST load with the `procest-settings.js` bundle
- AND it MUST display configuration options for register and schema mappings

### Requirement: App MUST have a GitHub repository
The app source code MUST be hosted at `ConductionNL/procest` on GitHub.

#### Scenario: Repository exists
- GIVEN the ConductionNL GitHub organization
- WHEN checking for the procest repository
- THEN `https://github.com/ConductionNL/procest` MUST exist and be public

---

### Current Implementation Status

**Fully implemented.** All scaffold requirements are satisfied.

**Implemented (with file paths):**
- **App registration**: `appinfo/info.xml` -- id `procest`, name "Procest" (en/nl), namespace `Procest`, Nextcloud 28-33 compatibility, PHP 8.1+ requirement. Categories: organization, tools, workflow.
- **App enable**: Registers navigation entry (`procest.dashboard.page`), declares OpenRegister dependency in description.
- **SPA entry point**: `lib/Controller/DashboardController.php` serves the page. `src/main.js` mounts Vue 2 app with Pinia to `#content`.
- **Webpack build**: `webpack.config.js` extends `@nextcloud/webpack-vue-config` with entry points: `procest-main.js` (dashboard SPA), `procest-settings.js` (admin settings), plus widget bundles (`procest-casesOverviewWidget.js`, `procest-overdueCasesWidget.js`, `procest-myTasksWidget.js`).
- **Translation support**: `t('procest', ...)` used in all Vue components. PHP uses `$this->l->t(...)`. English is primary language.
- **Admin settings**: `lib/Settings/AdminSettings.php` registered in `info.xml`. `lib/Sections/SettingsSection.php` provides the admin section. `src/settings.js` loads the `procest-settings.js` bundle. Admin UI implemented in `src/views/settings/Settings.vue`, `AdminRoot.vue`, `CaseTypeAdmin.vue`, `CaseTypeList.vue`, `CaseTypeDetail.vue`.
- **Router**: `src/router/index.js` with history mode, routes for Dashboard, MyWork, Cases, CaseDetail, Tasks, TaskDetail, Settings, CaseTypes.
- **Navigation**: `src/navigation/MainMenu.vue` with Dashboard, My Work, Cases, Tasks, Documentation, Case Types, and Configuration menu items.
- **Repair steps**: `lib/Repair/InitializeSettings.php` (register/schema init) and `lib/Repair/LoadDefaultZgwMappings.php` (ZGW mapping defaults) registered in `info.xml`.
- **GitHub repository**: https://github.com/ConductionNL/procest (referenced in `info.xml`).
- **Pinia state management**: `src/pinia.js` creates the Pinia instance, used by all stores.

**All requirements in this spec are implemented.**

### Standards & References

- **Nextcloud App Development Guidelines**: Standard app structure with `appinfo/info.xml`, `routes.php`, AppFramework controllers, admin settings sections.
- **Vue 2 + Pinia**: Standard frontend stack for Conduction Nextcloud apps.
- **@nextcloud/webpack-vue-config**: Nextcloud's standard webpack configuration.
- **Nextcloud L10N**: `t()` and `n()` translation functions per Nextcloud conventions.
- **EUPL-1.2**: License declared in PHP file headers.

### Specificity Assessment

- **Fully implementable and already implemented.** The spec is specific enough and all scenarios are satisfied.
- **No open questions.** This is a straightforward scaffold spec that matches the actual implementation.
