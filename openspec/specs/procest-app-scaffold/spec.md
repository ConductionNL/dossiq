---
status: implemented
---
# procest-app-scaffold Specification

## Purpose
Define the Nextcloud app scaffolding, build system, translation setup, and admin settings for the Procest case management app. This capability establishes the foundational structure that all other capabilities build upon, including the Application class, DashboardController, Vue SPA entry, routing, navigation, repair steps, and settings infrastructure.

## Context
Procest is a case management (zaakgericht werken) app for Nextcloud built by ConductionNL. It follows the thin-client architecture: no own database tables, all data stored in OpenRegister, Vue 2 frontend with Pinia stores querying OpenRegister directly. The scaffold defines the app shell, build pipeline, and configuration mechanisms that every feature spec depends on. It also integrates ZGW API middleware for Dutch government interoperability and registers deep links for cross-app navigation from OpenRegister.

## Requirements

### Requirement 1: App MUST be a valid Nextcloud app with proper metadata
The Procest app MUST be installable as a standard Nextcloud app with proper `info.xml` metadata, PHP namespace, and dependency declarations.

#### Scenario 1.1: App registration in Nextcloud app list
- GIVEN the Procest app directory exists in `apps-extra/procest/`
- WHEN Nextcloud scans for available apps
- THEN the app MUST appear in the apps list with id `procest`, name "Procest" (with translations for en/nl), and namespace `OCA\Procest`
- AND `info.xml` MUST declare compatibility with Nextcloud versions 28 through 33
- AND `info.xml` MUST declare PHP 8.1+ as minimum requirement

#### Scenario 1.2: App enable with OpenRegister dependency
- GIVEN Nextcloud is running and OpenRegister is installed and enabled
- WHEN an admin enables the Procest app via `php occ app:enable procest`
- THEN the app MUST activate without errors
- AND it MUST register a navigation entry in the top bar pointing to `procest.dashboard.page`
- AND the `InitializeSettings` repair step MUST run to create/detect the Procest register
- AND the `LoadDefaultZgwMappings` repair step MUST run to seed ZGW field mappings

#### Scenario 1.3: App enable without OpenRegister shows guidance
- GIVEN Nextcloud is running but OpenRegister is NOT installed
- WHEN a user navigates to `/apps/procest/`
- THEN `App.vue` MUST display an `NcEmptyContent` component with the message "OpenRegister is required"
- AND an "Install OpenRegister" `NcButton` MUST link to `generateUrl('/settings/apps/integration/openregister')`
- AND the button MUST only be visible to admin users (checked via `settingsStore.getIsAdmin`)

#### Scenario 1.4: App categories and license
- GIVEN the `info.xml` file
- WHEN the Nextcloud app store reads the metadata
- THEN the app MUST be categorized under "organization", "tools", and "workflow"
- AND the license MUST be declared as `agpl` for Nextcloud Store compatibility
- AND PHP file headers MUST declare EUPL-1.2

#### Scenario 1.5: Application class bootstrap
- GIVEN the `Application` class at `lib/AppInfo/Application.php`
- WHEN the app boots
- THEN `register()` MUST register the `DeepLinkRegistrationListener` for `DeepLinkRegistrationEvent`
- AND `register()` MUST register `ZgwAuthMiddleware` as middleware
- AND `APP_ID` MUST be the constant `'procest'`

### Requirement 2: App MUST provide a single-page application entry point
The app MUST serve a Vue 2 SPA from a DashboardController that mounts to the `#content` element.

#### Scenario 2.1: DashboardController serves HTML
- GIVEN the app is enabled and a user is logged in
- WHEN the user navigates to `/apps/procest/`
- THEN `DashboardController::page()` MUST return a `TemplateResponse` with the `main` template
- AND it MUST call `Util::addScript('procest', 'procest-main')` to load the SPA bundle
- AND it MUST call `Util::addStyle('procest', 'procest-main')` if a separate CSS bundle exists

#### Scenario 2.2: Vue app initialization sequence in main.js
- GIVEN the `src/main.js` entry point
- WHEN the script executes
- THEN it MUST import and register `PiniaVuePlugin` before creating the Vue instance
- AND it MUST import `@conduction/nextcloud-vue/css/index.css` for shared library styles
- AND it MUST import `./assets/app.css` for global app styles
- AND it MUST create the Vue instance with `pinia` and `router` options
- AND it MUST call `app.$mount('#content')` immediately (before store init)
- AND it MUST call `initializeStores()` after mounting to trigger settings fetch and type registration

#### Scenario 2.3: App shell component with three states
- GIVEN the root `App.vue` component
- WHEN it renders
- THEN it MUST show `NcLoadingIcon` while `storesReady === false`
- AND it MUST show `NcEmptyContent` (OpenRegister missing) when `storesReady && !hasOpenRegisters`
- AND it MUST show `MainMenu` + `NcAppContent` + `router-view` when `storesReady && hasOpenRegisters`
- AND it MUST provide `sidebarState` via Vue's `provide/inject` for child components

#### Scenario 2.4: CnIndexSidebar integration
- GIVEN the App.vue component renders the main content state
- WHEN `sidebarState.active` is true (set by a list view)
- THEN the `CnIndexSidebar` component from `@conduction/nextcloud-vue` MUST render alongside the main content
- AND it MUST receive `schema`, `visibleColumns`, `searchValue`, `activeFilters`, and `facetData` from the reactive sidebarState
- AND sidebar events (`@search`, `@columns-change`, `@filter-change`) MUST propagate to the list view via callback functions on sidebarState

### Requirement 3: Vue Router MUST define all application routes
The app MUST use Vue Router in history mode with routes for all primary views, detail views, and settings.

#### Scenario 3.1: Route definitions
- GIVEN the router at `src/router/index.js`
- WHEN the app initializes
- THEN the router MUST use history mode with base URL `generateUrl('/apps/procest')`
- AND it MUST define these routes: Dashboard (`/`, name: `Dashboard`), MyWork (`/my-work`, name: `MyWork`), Cases (`/cases`, name: `Cases`), CaseDetail (`/cases/:id`, name: `CaseDetail`), Tasks (`/tasks`, name: `Tasks`), TaskNew (`/tasks/new`, name: `TaskNew`), TaskDetail (`/tasks/:id`, name: `TaskDetail`), Settings (`/settings`, name: `Settings`), CaseTypes (`/case-types`, name: `CaseTypes`)
- AND it MUST include a catch-all route (`*`) that redirects to `/`

#### Scenario 3.2: Route props for detail views
- GIVEN the CaseDetail route at `/cases/:id`
- WHEN the route is matched
- THEN it MUST pass `caseId: route.params.id` as a prop to the CaseDetail component
- AND the TaskDetail route MUST pass `taskId: route.params.id`
- AND the TaskNew route MUST pass `taskId: 'new'` and `caseIdProp: route.query.caseId || null`

#### Scenario 3.3: Route-based code splitting (future)
- GIVEN the router configuration
- WHEN the app grows in size
- THEN routes MAY use dynamic imports (`() => import(...)`) for code splitting
- AND the Dashboard and CaseList routes SHOULD remain in the main bundle for fast initial load

### Requirement 4: App MUST use webpack build system extending Nextcloud base config
The build system MUST extend `@nextcloud/webpack-vue-config` with multiple entry points for the SPA, settings, and dashboard widgets.

#### Scenario 4.1: Build produces all required bundles
- GIVEN the source files exist in `src/`
- WHEN `npm run build` is executed
- THEN it MUST produce `js/procest-main.js` for the dashboard SPA
- AND it MUST produce `js/procest-settings.js` for the admin settings page
- AND it MUST produce `js/procest-casesOverviewWidget.js`, `js/procest-overdueCasesWidget.js`, and `js/procest-myTasksWidget.js` for Nextcloud Dashboard widgets

#### Scenario 4.2: Webpack alias configuration for deduplication
- GIVEN `@conduction/nextcloud-vue` imports `vue`, `pinia`, and `@nextcloud/vue`
- WHEN webpack resolves these imports
- THEN `webpack.config.js` MUST configure resolve aliases to ensure a single copy of each shared dependency
- AND this MUST prevent "multiple Vue instances" errors at runtime

#### Scenario 4.3: Development build with watch mode
- GIVEN the developer runs `npm run dev`
- WHEN source files are modified
- THEN webpack MUST rebuild the affected bundles automatically
- AND source maps MUST be generated for debugging

#### Scenario 4.4: CSS extraction
- GIVEN Vue components with `<style scoped>` blocks
- WHEN the production build runs
- THEN scoped CSS MUST be extracted and bundled correctly
- AND global styles from `app.css` MUST be included in the main bundle

### Requirement 5: App MUST support multilingual translations (EN/NL minimum)
All user-facing strings MUST be wrapped in translation functions with English as the primary language and Dutch as a required secondary language.

#### Scenario 5.1: English translation rendering
- GIVEN a user with English (`en`) locale
- WHEN viewing the Procest app
- THEN all UI text MUST be displayed in English including navigation ("Dashboard", "Cases", "Tasks", "My Work"), form labels ("Title", "Description", "Status"), buttons ("Save", "Delete", "New case"), and error messages

#### Scenario 5.2: Dutch translation rendering
- GIVEN a user with Dutch (`nl`) locale
- WHEN viewing the Procest app
- THEN all UI text MUST be displayed in Dutch
- AND navigation MUST show "Dashboard", "Zaken", "Taken", "Mijn werk"
- AND form labels MUST show "Titel", "Beschrijving", "Status"

#### Scenario 5.3: Translation function usage in Vue templates
- GIVEN any Vue component with user-facing text
- WHEN the component renders
- THEN all strings MUST use `t('procest', 'key')` for simple strings
- AND parameterized strings MUST use `t('procest', 'Updated: {fields}', { fields: changes.join(', ') })`
- AND plural strings MUST use `n('procest', '{count} task', '{count} tasks', count)`

#### Scenario 5.4: Translation function usage in PHP
- GIVEN any PHP class that returns user-facing messages
- WHEN the code constructs a response
- THEN it MUST inject `IL10N $l` via constructor and use `$this->l->t('key')` for translated strings

#### Scenario 5.5: Global mixin registration
- GIVEN `src/main.js`
- WHEN the Vue app is configured
- THEN `Vue.mixin({ methods: { t, n } })` MUST be called with `t` and `n` imported from `@nextcloud/l10n`
- AND this MUST make `t()` and `n()` available in all components without explicit import

### Requirement 6: App MUST provide admin settings page
The app MUST register an admin settings section for register/schema configuration, case type administration, and ZGW mapping management.

#### Scenario 6.1: Admin settings section registration in info.xml
- GIVEN the `info.xml` file
- WHEN Nextcloud loads admin settings
- THEN `<admin>OCA\Procest\Settings\AdminSettings</admin>` MUST be registered
- AND `<admin-section>OCA\Procest\Sections\SettingsSection</admin-section>` MUST be registered
- AND the section MUST appear under "Administration" in the settings sidebar with the Procest icon

#### Scenario 6.2: Admin settings page rendering
- GIVEN an admin user navigating to `/settings/admin/procest`
- WHEN the settings page loads
- THEN it MUST load the `procest-settings.js` bundle
- AND it MUST display the current register ID and schema IDs for all configured schemas
- AND it MUST include a "Reload configuration" button to re-import from `procest_register.json`

#### Scenario 6.3: In-app settings views
- GIVEN the Settings route at `/apps/procest/settings`
- WHEN the `AdminRoot.vue` component renders
- THEN it MUST provide tabs or sub-routes for: General settings (`GeneralTab.vue`), Statuses configuration (`StatusesTab.vue`), Case type administration (`CaseTypeAdmin.vue`), ZGW mapping settings (`ZgwMappingSettings.vue`)

#### Scenario 6.4: Case type administration
- GIVEN the CaseTypes route at `/apps/procest/case-types`
- WHEN the `CaseTypeAdmin.vue` component renders
- THEN it MUST display a list of configured case types (`CaseTypeList.vue`)
- AND clicking a case type MUST navigate to `CaseTypeDetail.vue` for editing
- AND each case type MUST be an OpenRegister object under the `caseType` schema

### Requirement 7: Backend MUST provide settings API endpoint
The backend MUST provide a RESTful settings endpoint for reading and writing app configuration.

#### Scenario 7.1: GET /api/settings returns full configuration
- GIVEN the app is configured with register and schema IDs
- WHEN a GET request is made to `/apps/procest/api/settings`
- THEN `SettingsController::index()` MUST return a JSON response with keys: `config` (all config keys from `SettingsService::getSettings()`), `openRegisters` (boolean from `isOpenRegisterAvailable()`), `isAdmin` (boolean)
- AND each config key MUST be one of the keys defined in `SettingsService::CONFIG_KEYS` (register, case_schema, task_schema, status_schema, etc.)

#### Scenario 7.2: POST /api/settings saves configuration
- GIVEN an admin user
- WHEN a POST request is made to `/apps/procest/api/settings` with JSON body containing config keys
- THEN `SettingsController::create()` MUST call `SettingsService::updateSettings()` to persist the values
- AND it MUST return the updated configuration as confirmation
- AND the response status MUST be 200

#### Scenario 7.3: Settings key completeness
- GIVEN the `SettingsService::CONFIG_KEYS` array
- WHEN all keys are listed
- THEN it MUST include at minimum: `register`, `case_schema`, `task_schema`, `status_schema`, `role_schema`, `result_schema`, `decision_schema`, `case_type_schema`, `status_type_schema`, `result_type_schema`, `role_type_schema`, `property_definition_schema`, `document_type_schema`, `decision_type_schema`, `case_property_schema`, `case_document_schema`, `document_schema`, `customer_contact_schema`, `default_case_type`

#### Scenario 7.4: SLUG_TO_CONFIG_KEY mapping
- GIVEN the `SettingsService::SLUG_TO_CONFIG_KEY` constant
- WHEN the register is imported and `autoConfigureAfterImport()` runs
- THEN each schema slug from `procest_register.json` MUST have a corresponding entry in this mapping
- AND the mapping MUST correctly convert slugs like `caseType` to config keys like `case_type_schema`

### Requirement 8: App MUST have repair steps for initialization
The app MUST include repair steps that run on install and upgrade to configure the data layer.

#### Scenario 8.1: InitializeSettings repair step
- GIVEN the app is being installed or upgraded
- WHEN the `InitializeSettings` repair step runs
- THEN it MUST call `SettingsService::loadConfiguration()` to import `procest_register.json`
- AND if OpenRegister is not available, it MUST log a warning and skip gracefully
- AND if the import succeeds, it MUST auto-configure all schema IDs via `autoConfigureAfterImport()`

#### Scenario 8.2: LoadDefaultZgwMappings repair step
- GIVEN the app is being installed for the first time
- WHEN the `LoadDefaultZgwMappings` repair step runs
- THEN it MUST seed default ZGW field mappings (e.g., case.title -> zaak.omschrijving)
- AND these mappings MUST be usable by `ZgwMappingService` for API translation

#### Scenario 8.3: Register configuration JSON structure
- GIVEN the file `lib/Settings/procest_register.json`
- WHEN the register is imported
- THEN the JSON MUST follow OpenAPI 3.0.0 format with `info.title: "Procest"`, `info.version`, and schema definitions
- AND each schema MUST include `x-schema-org-type` annotations (e.g., case -> `schema:Project`, task -> `schema:Action`)

#### Scenario 8.4: Version-based import skipping
- GIVEN the register was previously imported at version 1.0.0
- WHEN the repair step runs again with the same version
- THEN `ConfigurationService::importFromApp()` MUST skip the import (no work done)
- AND the log MUST indicate the import was skipped due to matching version

### Requirement 9: App MUST have a GitHub repository with CI/CD
The app source code MUST be hosted at `ConductionNL/procest` on GitHub.

#### Scenario 9.1: Repository exists and is public
- GIVEN the ConductionNL GitHub organization
- WHEN checking for the procest repository
- THEN `https://github.com/ConductionNL/procest` MUST exist and be public
- AND the repository MUST have a `main` branch as default
- AND the repository URL MUST be referenced in `info.xml`

#### Scenario 9.2: Repository structure
- GIVEN the repository root
- WHEN listing the contents
- THEN it MUST contain: `appinfo/info.xml`, `appinfo/routes.php`, `lib/AppInfo/Application.php`, `lib/Controller/DashboardController.php`, `lib/Controller/SettingsController.php`, `lib/Service/SettingsService.php`, `lib/Repair/InitializeSettings.php`, `src/main.js`, `src/App.vue`, `src/router/index.js`, `webpack.config.js`, `package.json`, `composer.json`

#### Scenario 9.3: CI workflow runs quality checks
- GIVEN a pull request is opened
- WHEN the CI workflow runs
- THEN it MUST execute `composer check:strict` covering PHPCS, PHPMD, Psalm level 4, and PHPStan level 5
- AND it MUST execute `npm run lint` for ESLint with Nextcloud config

### Requirement 10: Navigation menu MUST show all primary sections
The app navigation MUST include menu items for all primary views and settings using Nextcloud navigation components.

#### Scenario 10.1: Main navigation rendering
- GIVEN the user opens the Procest app
- WHEN the `MainMenu.vue` component renders within `NcAppNavigation`
- THEN the main list MUST include items for: Dashboard (ViewDashboard icon), My Work (AccountCheck icon), Cases (FolderOpen icon), Tasks (ClipboardCheckOutline icon), Documentation (BookOpenVariantOutline icon, external link to https://procest.app)
- AND each item MUST use `NcAppNavigationItem` with `:to` binding for internal routes

#### Scenario 10.2: Footer navigation with settings
- GIVEN the navigation component
- WHEN the footer section renders within `NcAppNavigationSettings`
- THEN it MUST include: Case Types (ShapeOutline icon, route `CaseTypes`) and Configuration (Cog icon, route `Settings`)

#### Scenario 10.3: Active route highlighting
- GIVEN the user is on the Cases list page
- WHEN the navigation renders
- THEN the "Cases" menu item MUST be highlighted as active by Vue Router
- AND the Dashboard item MUST use `:exact="true"` to only highlight on the exact `/` path

#### Scenario 10.4: Documentation link opens externally
- GIVEN the Documentation menu item
- WHEN the user clicks it
- THEN it MUST call `window.open('https://procest.app', '_blank')` to open in a new tab
- AND it MUST NOT use Vue Router navigation

### Requirement 11: App MUST integrate Nextcloud Dashboard widgets
The app MUST provide dashboard widgets for the Nextcloud Dashboard, each as a separate entry point.

#### Scenario 11.1: Widget PHP classes
- GIVEN the `lib/Dashboard/` directory
- WHEN the widgets are registered
- THEN `CasesOverviewWidget.php` MUST implement `IWidget` showing case statistics
- AND `OverdueCasesWidget.php` MUST implement `IWidget` showing overdue cases
- AND `MyTasksWidget.php` MUST implement `IWidget` showing the current user's tasks

#### Scenario 11.2: Widget JavaScript entry points
- GIVEN the `src/` directory
- WHEN webpack builds the app
- THEN `casesOverviewWidget.js` MUST create and mount `CasesOverviewWidget.vue`
- AND `overdueCasesWidget.js` MUST create and mount `OverdueCasesWidget.vue`
- AND `myTasksWidget.js` MUST create and mount `MyTasksWidget.vue`

#### Scenario 11.3: Widgets render independently
- GIVEN the Nextcloud Dashboard page
- WHEN a Procest widget is added
- THEN it MUST load its own JavaScript bundle (not the full SPA bundle)
- AND it MUST fetch its own data via the object store pattern
- AND clicking items in the widget MUST navigate to the Procest app

### Requirement 12: ZGW middleware and deep link registration
The app MUST register ZGW authentication middleware and deep links for cross-app integration.

#### Scenario 12.1: ZGW auth middleware registration
- GIVEN the `Application::register()` method
- WHEN the app boots
- THEN `ZgwAuthMiddleware` MUST be registered via `$context->registerMiddleware()`
- AND it MUST intercept requests to ZGW API controllers (ZrcController, ZtcController, BrcController, DrcController) for token-based authentication

#### Scenario 12.2: Deep link registration listener
- GIVEN OpenRegister dispatches a `DeepLinkRegistrationEvent`
- WHEN the event is handled
- THEN `DeepLinkRegistrationListener` MUST register deep links so OpenRegister can link directly to Procest case and task detail views
- AND the deep links MUST map object types (case, task) to Procest routes

#### Scenario 12.3: ZGW exception handling
- GIVEN a ZGW API request with invalid authentication
- WHEN `ZgwAuthMiddleware` intercepts the request
- THEN it MUST throw a `ZgwAuthException` with appropriate HTTP status code (401 or 403)
- AND the exception MUST be serialized as a JSON error response

---

## Current Implementation Status

**Fully implemented.** All scaffold requirements are satisfied.

**Implemented (with file paths):**
- **App registration**: `appinfo/info.xml` -- id `procest`, name "Procest" (en/nl), namespace `Procest`, Nextcloud 28-33 compatibility, PHP 8.1+ requirement. Categories: organization, tools, workflow.
- **Application class**: `lib/AppInfo/Application.php` -- registers `DeepLinkRegistrationListener` and `ZgwAuthMiddleware`.
- **SPA entry point**: `lib/Controller/DashboardController.php` serves the page. `src/main.js` mounts Vue 2 app with Pinia to `#content`.
- **App.vue**: Three-state rendering (loading, OpenRegister missing, main content). Provides `sidebarState` via `provide/inject`.
- **Webpack build**: `webpack.config.js` extends `@nextcloud/webpack-vue-config` with 5 entry points: main, settings, and 3 widget bundles.
- **Translation support**: `t('procest', ...)` used in all Vue components. Global mixin in `main.js`.
- **Admin settings**: `lib/Settings/AdminSettings.php`, `lib/Sections/SettingsSection.php`, `src/settings.js`, with in-app views in `src/views/settings/`.
- **Router**: `src/router/index.js` with history mode, 9 routes including catch-all.
- **Navigation**: `src/navigation/MainMenu.vue` with 5 main items and 2 footer items.
- **Repair steps**: `lib/Repair/InitializeSettings.php` and `lib/Repair/LoadDefaultZgwMappings.php`.
- **Dashboard widgets**: 3 widget PHP classes and 3 widget Vue components with separate entry points.
- **Settings API**: `lib/Controller/SettingsController.php` with `lib/Service/SettingsService.php` managing 28 config keys.
- **ZGW middleware**: `lib/Middleware/ZgwAuthMiddleware.php` with `ZgwAuthException`.
- **Deep links**: `lib/Listener/DeepLinkRegistrationListener.php`.
- **GitHub repository**: https://github.com/ConductionNL/procest.

**All requirements in this spec are implemented.**

## Standards & References

- **Nextcloud App Development Guidelines**: Standard app structure with `appinfo/info.xml`, `routes.php`, AppFramework controllers, admin settings sections.
- **Vue 2 + Pinia**: Standard frontend stack for Conduction Nextcloud apps, with `PiniaVuePlugin` for Vue 2 compatibility.
- **@nextcloud/webpack-vue-config**: Nextcloud's standard webpack configuration.
- **@conduction/nextcloud-vue**: Shared component library providing `createObjectStore`, `CnIndexPage`, `CnDetailPage`, `CnIndexSidebar`, etc.
- **Nextcloud L10N**: `t()` and `n()` translation functions per Nextcloud conventions.
- **EUPL-1.2**: License declared in PHP file headers.
- **ZGW APIs (VNG Realisatie)**: Authentication middleware for Dutch government API interoperability.
- **OpenAPI 3.0.0**: Register configuration format for `procest_register.json`.

## Specificity Assessment

This spec is fully implementable and already implemented. All 12 requirements cover the complete app scaffold including Application class, SPA entry, routing, build system, translations, admin settings, repair steps, settings API, repository structure, navigation, dashboard widgets, and ZGW middleware. The spec accurately reflects the actual codebase structure.
