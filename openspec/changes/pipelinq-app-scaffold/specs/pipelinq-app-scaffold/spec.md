---
status: implemented
---
# pipelinq-app-scaffold Specification

## Purpose
Define the Nextcloud app scaffolding, build system, translation setup, and admin settings for the Pipelinq client and request management app. Mirrors the Procest scaffold with its own app identity, routing, component registration, and OpenRegister integration.

## Context
Pipelinq is a CRM and client management app for Nextcloud, serving as the sister app to Procest (case management). It follows the same architectural patterns: thin client with no own database tables, Vue 2 frontend with Pinia state management, and all data stored in OpenRegister. The app scaffold must provide the foundational structure that all Pipelinq features build upon, including proper Nextcloud integration, build tooling, translation support, and admin settings for register/schema configuration.

## Requirements

### Requirement 1: App MUST be a valid Nextcloud app with proper metadata
The Pipelinq app MUST be installable as a standard Nextcloud app with proper `info.xml` metadata, PHP namespace, and dependency declarations.

#### Scenario 1.1: App registration in Nextcloud app list
- GIVEN the Pipelinq app directory exists in `apps-extra/pipelinq/`
- WHEN Nextcloud scans for available apps
- THEN the app MUST appear in the apps list with id `pipelinq`, name "Pipelinq", and namespace `OCA\Pipelinq`
- AND `info.xml` MUST declare compatibility with Nextcloud versions 28 through 33
- AND `info.xml` MUST declare PHP 8.1+ as minimum requirement

#### Scenario 1.2: App enable with OpenRegister dependency
- GIVEN Nextcloud is running and OpenRegister is installed and enabled
- WHEN an admin enables the Pipelinq app via `php occ app:enable pipelinq`
- THEN the app MUST activate without errors
- AND it MUST register a navigation entry in the top bar with icon and translated name
- AND the repair step MUST run to create/detect the Pipelinq register in OpenRegister

#### Scenario 1.3: App enable without OpenRegister
- GIVEN Nextcloud is running but OpenRegister is NOT installed
- WHEN a user navigates to `/apps/pipelinq/`
- THEN the app MUST display an `NcEmptyContent` component explaining that OpenRegister is required
- AND an "Install OpenRegister" button MUST link to the Nextcloud app store

#### Scenario 1.4: App categories and description
- GIVEN the `info.xml` file
- WHEN the Nextcloud app store reads the metadata
- THEN the app MUST be categorized under "organization" and "social"
- AND the description MUST be provided in both English and Dutch

#### Scenario 1.5: License declaration
- GIVEN the app source code
- WHEN checking license headers
- THEN all PHP files MUST include EUPL-1.2 license headers
- AND `info.xml` MUST declare `agpl` as the license for Nextcloud compatibility

### Requirement 2: App MUST provide a single-page application entry point
The app MUST serve a Vue 2 SPA from a DashboardController that mounts to the `#content` element.

#### Scenario 2.1: Dashboard page load
- GIVEN the app is enabled and a user is logged in
- WHEN the user navigates to `/apps/pipelinq/`
- THEN the DashboardController MUST return a `TemplateResponse` with template name `main`
- AND the page MUST load the `pipelinq-main.js` webpack bundle via `Util::addScript()`
- AND the Vue app MUST initialize with `PiniaVuePlugin` and mount to `#content`

#### Scenario 2.2: Vue app initialization sequence
- GIVEN the `src/main.js` entry point
- WHEN the script executes
- THEN it MUST register `PiniaVuePlugin` with Vue before creating the app instance
- AND it MUST create the Vue instance with Pinia and Vue Router
- AND it MUST call `$mount('#content')` before `initializeStores()`
- AND `initializeStores()` MUST fetch settings and register all object types

#### Scenario 2.3: App shell with NcContent
- GIVEN the root `App.vue` component
- WHEN it renders
- THEN it MUST use `NcContent` with `app-name="pipelinq"`
- AND it MUST show a loading state while stores initialize (`NcLoadingIcon`)
- AND it MUST check `hasOpenRegisters` before rendering the main content
- AND it MUST include the `MainMenu` navigation component and `router-view` for page content

#### Scenario 2.4: Shared library CSS import
- GIVEN the main entry point
- WHEN the app bundles are built
- THEN `main.js` MUST explicitly import `@conduction/nextcloud-vue/css/index.css`
- AND this import MUST appear before any component imports to ensure correct CSS cascade

#### Scenario 2.5: Global translation mixin
- GIVEN any Vue component in the app
- WHEN the component needs to display translated text
- THEN `main.js` MUST register a global mixin providing `t()` and `n()` methods
- AND these MUST use `@nextcloud/l10n` with app id `pipelinq`

### Requirement 3: Vue Router MUST define all application routes
The app MUST use Vue Router in history mode with routes for all primary views.

#### Scenario 3.1: Route definitions
- GIVEN the router at `src/router/index.js`
- WHEN the app initializes
- THEN the router MUST use history mode with base URL `generateUrl('/apps/pipelinq')`
- AND it MUST define routes for: Dashboard (`/`), Clients (`/clients`), ClientDetail (`/clients/:id`), Requests (`/requests`), RequestDetail (`/requests/:id`), Settings (`/settings`)
- AND it MUST include a catch-all route (`*`) that redirects to `/`

#### Scenario 3.2: Route props for detail views
- GIVEN a detail view route like `/clients/:id`
- WHEN the route is matched
- THEN the route MUST pass the `id` param as a prop to the component (e.g., `props: route => ({ clientId: route.params.id })`)

#### Scenario 3.3: Navigation guard for settings
- GIVEN a non-admin user
- WHEN they attempt to navigate to `/settings`
- THEN the settings view MUST check `settingsStore.getIsAdmin` and display an access denied message if false

### Requirement 4: App MUST use webpack build system extending Nextcloud base config
The build system MUST extend `@nextcloud/webpack-vue-config` with entry points for the main SPA and admin settings.

#### Scenario 4.1: Build produces correct bundles
- GIVEN the source files exist in `src/`
- WHEN `npm run build` is executed
- THEN it MUST produce `js/pipelinq-main.js` for the dashboard SPA
- AND it MUST produce `js/pipelinq-settings.js` for the admin settings page
- AND both bundles MUST be minified for production builds

#### Scenario 4.2: Webpack alias for shared library deduplication
- GIVEN `@conduction/nextcloud-vue` is a dependency
- WHEN webpack resolves imports
- THEN `webpack.config.js` MUST configure resolve aliases to deduplicate Vue, Pinia, and `@nextcloud/vue` between the app and the shared library

#### Scenario 4.3: Development mode with hot reload
- GIVEN the developer runs `npm run dev`
- WHEN source files are modified
- THEN webpack MUST rebuild the affected bundles
- AND the `--watch` flag MUST be available for continuous rebuilds

#### Scenario 4.4: Source map generation
- GIVEN a development build
- WHEN `npm run dev` is executed
- THEN source maps MUST be generated for debugging
- AND production builds MUST NOT include source maps

### Requirement 5: App MUST support multilingual translations (EN/NL minimum)
All user-facing strings MUST be wrapped in translation functions with English as the primary language and Dutch as a required secondary language.

#### Scenario 5.1: English translation rendering
- GIVEN a user with English (`en`) locale
- WHEN viewing the Pipelinq app
- THEN all UI text MUST be displayed in English
- AND navigation items, form labels, button text, error messages, and empty states MUST all be translated

#### Scenario 5.2: Dutch translation rendering
- GIVEN a user with Dutch (`nl`) locale
- WHEN viewing the Pipelinq app
- THEN all UI text MUST be displayed in Dutch
- AND the navigation MUST show "Klanten" instead of "Clients", "Verzoeken" instead of "Requests"

#### Scenario 5.3: Translation function usage in Vue templates
- GIVEN any Vue component with user-facing text
- WHEN the component renders
- THEN all strings MUST use `t('pipelinq', 'key')` in templates or `this.t('pipelinq', 'key')` in script
- AND plural strings MUST use `n('pipelinq', 'singular', 'plural', count)`

#### Scenario 5.4: Translation function usage in PHP
- GIVEN any PHP controller or service that returns user-facing messages
- WHEN the code constructs a response message
- THEN it MUST use `$this->l->t('key')` with the IL10N service injected via constructor

#### Scenario 5.5: Translation file structure
- GIVEN the `translationfiles/` directory
- WHEN translations are generated
- THEN `translationfiles/en/` MUST contain the source language strings
- AND `translationfiles/nl/` MUST contain Dutch translations
- AND the translation extraction tool (`translationtool.phar`) MUST be runnable without errors

### Requirement 6: App MUST provide admin settings page
The app MUST register an admin settings section for register/schema configuration and app preferences.

#### Scenario 6.1: Admin settings section registration
- GIVEN the `info.xml` file
- WHEN Nextcloud loads admin settings
- THEN `Sections\SettingsSection` MUST be registered as an admin settings section
- AND `Settings\AdminSettings` MUST be registered as the admin settings page
- AND the section MUST appear under "Administration" in the settings sidebar

#### Scenario 6.2: Settings page access and rendering
- GIVEN an admin user
- WHEN navigating to `/settings/admin/pipelinq`
- THEN the admin settings page MUST load with the `pipelinq-settings.js` bundle
- AND it MUST display configuration for register/schema mappings (register ID, client schema, request schema, contact schema)
- AND it MUST include a "Reload configuration" button to re-import from `pipelinq_register.json`

#### Scenario 6.3: Settings page restricted to admins
- GIVEN a regular (non-admin) user
- WHEN attempting to access `/settings/admin/pipelinq`
- THEN Nextcloud MUST deny access based on the `AdminSettings` class's admin-only priority

#### Scenario 6.4: In-app settings route
- GIVEN a user navigates to `/apps/pipelinq/settings` within the SPA
- WHEN the settings component renders
- THEN it MUST display the same configuration options as the admin settings page
- AND it MUST call the `/api/settings` endpoint for reading and writing configuration
- AND it MUST only be accessible to admin users (checked via `settingsStore.getIsAdmin`)

### Requirement 7: App MUST have a repair step for register initialization
The app MUST automatically create or detect the Pipelinq register and schemas in OpenRegister during installation or upgrade.

#### Scenario 7.1: Repair step execution on install
- GIVEN the app is being enabled for the first time
- WHEN the `InitializeSettings` repair step runs
- THEN it MUST call `SettingsService::loadConfiguration()` to import the register from `pipelinq_register.json`
- AND the import MUST create the register and all defined schemas in OpenRegister
- AND it MUST store the resulting register and schema IDs in `IAppConfig`

#### Scenario 7.2: Repair step on upgrade with version check
- GIVEN the app is being upgraded from version 1.0.0 to 1.1.0
- WHEN the repair step runs
- THEN `ConfigurationService::importFromApp()` MUST compare the version in `pipelinq_register.json` to the previously imported version
- AND if the version is newer, schemas MUST be updated without losing existing data
- AND if the version is the same, the import MUST be skipped (unless forced)

#### Scenario 7.3: Register configuration JSON structure
- GIVEN the file `lib/Settings/pipelinq_register.json`
- WHEN the register is imported
- THEN the JSON MUST follow OpenAPI 3.0.0 format with `info.title`, `info.version`, and schema definitions under `components.schemas`
- AND each schema MUST include Schema.org type annotations in `x-schema-org-type`

### Requirement 8: App MUST have a GitHub repository
The app source code MUST be hosted at `ConductionNL/pipelinq` on GitHub with proper CI/CD.

#### Scenario 8.1: Repository exists and is public
- GIVEN the ConductionNL GitHub organization
- WHEN checking for the pipelinq repository
- THEN `https://github.com/ConductionNL/pipelinq` MUST exist and be public
- AND the repository MUST have a `main` branch as default

#### Scenario 8.2: Repository contains required files
- GIVEN the repository root
- WHEN listing the contents
- THEN it MUST contain: `appinfo/info.xml`, `appinfo/routes.php`, `lib/`, `src/`, `webpack.config.js`, `package.json`, `composer.json`

#### Scenario 8.3: CI workflow runs linting
- GIVEN a pull request is opened
- WHEN the CI workflow runs
- THEN it MUST execute `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan)
- AND it MUST execute `npm run lint` for ESLint

### Requirement 9: Navigation menu MUST show primary entity sections
The app navigation MUST include menu items for all primary views using `NcAppNavigation` components.

#### Scenario 9.1: Navigation rendering with icons
- GIVEN the user opens the Pipelinq app
- WHEN the `MainMenu.vue` component renders
- THEN the menu MUST include items for: Dashboard (with dashboard icon), Clients (with contacts icon), Requests (with inbox icon), and Documentation (external link)
- AND the footer MUST include a settings/configuration item

#### Scenario 9.2: Active route highlighting
- GIVEN the user is on the Clients list page
- WHEN the navigation renders
- THEN the "Clients" menu item MUST be highlighted as active
- AND all other items MUST be in their default state

#### Scenario 9.3: Navigation item count badges
- GIVEN there are unprocessed requests requiring attention
- WHEN the navigation renders
- THEN the "Requests" menu item MAY display a count badge (enterprise feature)

### Requirement 10: App MUST integrate with Nextcloud Dashboard widgets
The app SHALL provide dashboard widgets for the Nextcloud Dashboard, showing key metrics.

#### Scenario 10.1: Widget registration in info.xml
- GIVEN the `info.xml` configuration
- WHEN the app registers its features
- THEN dashboard widgets MUST be declared as separate webpack entry points
- AND each widget MUST have a corresponding PHP class implementing `IWidget`

#### Scenario 10.2: Widget renders independently
- GIVEN the Nextcloud Dashboard page
- WHEN the user adds a Pipelinq widget
- THEN the widget MUST load its own JavaScript bundle (not the full SPA)
- AND it MUST fetch data independently using the object store pattern

#### Scenario 10.3: Widget displays client/request summary
- GIVEN the widget is rendered on the Nextcloud Dashboard
- WHEN it loads
- THEN it MUST display a summary of recent clients or open requests
- AND each item MUST link to the corresponding detail view in the Pipelinq app

---

## Current Implementation Status

**Fully implemented.** The Pipelinq app scaffold is complete and functional.

**Implemented (with file paths -- in the `pipelinq/` submodule):**
- **App registration**: `pipelinq/appinfo/info.xml` -- id `pipelinq`, namespace `Pipelinq`, Nextcloud 28-33 compatibility, PHP 8.1+ requirement.
- **Navigation entry**: Registered in `info.xml` as a top-bar navigation item.
- **SPA entry point**: `pipelinq/src/main.js` -- Vue 2 app with Pinia, mounts to `#content`.
- **Webpack build**: `pipelinq/webpack.config.js` -- extends `@nextcloud/webpack-vue-config` with entry points for `pipelinq-main.js` and `pipelinq-settings.js`.
- **Admin settings**: `pipelinq/lib/Settings/AdminSettings.php` with section registration and settings Vue component.
- **Repair step**: `pipelinq/lib/Repair/InitializeSettings.php` for register/schema initialization.
- **Register config**: `pipelinq/lib/Settings/pipelinq_register.json` -- defines the Pipelinq register and schemas.
- **Settings store**: `pipelinq/src/store/modules/settings.js` for config fetching.
- **Object store**: `pipelinq/src/store/modules/object.js` -- uses `createObjectStore('object')` from shared library.
- **Translation support**: `t('pipelinq', ...)` used throughout Vue components.
- **GitHub repository**: https://github.com/ConductionNL/pipelinq exists.

**All core scaffold requirements are implemented.**

## Standards & References

- **Nextcloud App Development Guidelines**: App structure follows Nextcloud conventions (info.xml, routes.php, AppFramework controllers, admin settings sections).
- **Vue 2 + Pinia**: Standard frontend stack for Conduction apps, with `PiniaVuePlugin` for Vue 2 compatibility.
- **@nextcloud/webpack-vue-config**: Nextcloud's standard webpack configuration extended with custom entry points.
- **@conduction/nextcloud-vue**: Shared component library providing `createObjectStore`, `CnIndexPage`, `CnDetailPage`, etc.
- **Nextcloud L10N**: Translation functions `t()` and `n()` used per Nextcloud conventions.
- **EUPL-1.2**: License declared in PHP file headers.
- **OpenAPI 3.0.0**: Register configuration format for `pipelinq_register.json`.

## Specificity Assessment

This spec is fully implementable and already implemented. All 10 requirements have comprehensive scenarios covering app registration, SPA entry, routing, build system, translations, admin settings, repair steps, repository, navigation, and widgets. The spec accurately reflects the Conduction app architecture pattern shared across Procest, Pipelinq, Softwarecatalog, and other apps.
