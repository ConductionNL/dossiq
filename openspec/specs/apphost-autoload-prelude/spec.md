# apphost-autoload-prelude Specification

## Purpose

Dossiq reaches into a sibling app — OpenRegister — from its own composition
root. Nextcloud does not guarantee that the sibling's autoloader is in place at
that moment, and the failure when it is not is completely silent. This spec owns
that one invariant: OpenRegister's PSR-4 prefix is on the autoloader before any
`OCA\OpenRegister\…` name is resolved during `Application::register()`.

It exists as its own capability rather than a clause inside
`apphost-adoption` because it is not about what the AppHost engine does; it is
about whether the engine gets wired at all.

## Requirements

### Requirement: OpenRegister's autoloader is registered before AppHost is referenced

`AppInfo\OpenRegisterAutoloader::register()` SHALL put OpenRegister's PSR-4
prefix on the composer autoloader — via
`OC_App::registerAutoloading('openregister', …)` — before the composition root
resolves any `OCA\OpenRegister\AppHost\…` name, including the `class_exists()`
guard in `AppInfo\Registrar\AppHostRegistrar`.

Nextcloud registers apps in sorted order: `OC_App::getEnabledApps()` does
`sort($apps)` and `Coordinator::registerApps()` walks that list calling
`OC_App::registerAutoloading($appId, $path)` and then `$app->register()` for one
app at a time. Every app's `register()` therefore runs before the PSR-4 prefix of
every alphabetically-later app exists.

`dossiq` sorts after `openregister`, so today the prefix happens to be present
by the time `AppHostRegistrar::register()` runs. That is the alphabet, not a
design property, and the guard cannot tell the two apart: `class_exists()`
answers `false` both when OpenRegister is genuinely absent and when its prefix
has merely not been registered yet. Under the second, dossiq silently skips the
entire AppHost engine — health, metrics, preferences, deep links, the SPA
page/catch-all, the seven dashboard widgets and the MCP provider — on a
perfectly healthy instance, with nothing in the UI to say so.

`OC_App::registerAutoloading()` is idempotent and touches only the autoloader,
so on the current ordering this call costs nothing.
`IAppManager::loadApp('openregister')` MUST NOT be used instead: it marks
OpenRegister loaded and calls `Coordinator::bootApp()`, booting OpenRegister
before its own `register()` has run.

The prelude MUST NOT throw under any instance state. It runs inside
`Application::register()`, which Nextcloud executes on every request, so an
exception escaping it would abort the whole composition root —
`Coordinator::registerApps()` would catch it, log an `emergency` and continue,
leaving dossiq enabled and serving with every later registration missing.

This requirement carries no scenarios by design. Both of its behaviours live in
the app-registration phase, which completes before the first request is
dispatched, so neither is reachable from a browser or an HTTP client, and the
absent-OpenRegister path cannot be set up on an instance that must have
OpenRegister to serve this app at all. They are asserted directly in
`tests/Unit/AppInfo/OpenRegisterAutoloaderTest.php` and enforced mechanically by
hydra gate-64 (`apphost-autoload-prelude`), which fails any app that references
`OCA\OpenRegister\AppHost\…` from `lib/AppInfo/` without the prelude.

**Notes:** ADR-040. The load order was measured on the sibling app `openbuild`,
which sorts before `openregister` and logged `OpenRegister AppHost\Bootstrap is
not autoloadable` on every `occ` call in CI while OpenRegister was installed and
enabled the whole time.
