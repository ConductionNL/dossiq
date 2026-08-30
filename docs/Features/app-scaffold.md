# App Scaffold

Dossiq application foundation: Nextcloud PHP app boilerplate, Webpack/Vue 2 build system, OpenRegister integration wiring, and initial register configuration.

## Overview

The app scaffold establishes the technical foundation for the Dossiq Nextcloud app:

- **PHP app skeleton**: AppInfo, Application class, DI container wiring
- **Vue 2 frontend**: Webpack build, Pinia state management, Vue Router
- **OpenRegister wiring**: `ConfigurationService::importFromApp()` repair step, `dossiq_register.json` with 12 initial schemas
- **Object store pattern**: Pinia stores per entity type, OpenRegister API calls from frontend
- **Nextcloud integration**: Admin panel settings, navigation, sidebar

## Archive Changes

This feature consolidates:
- `create-dossiq-app`: initial app registration and boilerplate
- `dossiq-app-scaffold`: build system, Webpack/Vue setup
- `dossiq-object-store`: Pinia store foundation per entity
- `dossiq-case-management`: core case CRUD scaffolding

See [administration.md](administration.md) and [case-management.md](case-management.md) for functional details.
