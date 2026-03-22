# Design: Procest App Scaffold

## Architecture
- **Backend**: `Application.php` (IBootstrap), `DashboardController.php` (SPA entry), repair steps
- **Frontend**: Vue 2 SPA with Pinia stores, webpack build, `@nextcloud/vue` components
- **Routing**: Vue Router history mode with SPA catch-all in PHP routes
- **Navigation**: `MainMenu.vue` with Dashboard, My Work, Cases, Tasks, Settings links
- **Middleware**: `ZgwAuthMiddleware` for JWT auth on ZGW endpoints

## Key Files
| File | Purpose |
|------|---------|
| `appinfo/info.xml` | App metadata, NC 28-33 compat |
| `appinfo/routes.php` | PHP route definitions |
| `lib/AppInfo/Application.php` | App bootstrap, event listeners, middleware |
| `lib/Controller/DashboardController.php` | SPA entry point |
| `src/main.js` | Vue app entry |
| `src/App.vue` | Root Vue component |
| `src/router/index.js` | Vue Router configuration |
| `src/navigation/MainMenu.vue` | App navigation sidebar |
| `templates/index.php` | PHP template loading Vue SPA |
| `webpack.config.js` | Build configuration |
