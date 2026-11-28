# Tasks: stuf-provider-through-integriq

- [x] `StufProviderQueryListener`, registered by name for the four integriq events.
- [x] `StufIntegriqBridge::forwardProviderRequest()` and the four forwarding routes.
- [x] Provider classes and their tests deleted; register contract test drives the forwarder.
- [x] Decision for Ruben: should dossiq accept zakLk01 and edcLk01? Decision 206: yes, through integriq, see `stuf-writes-through-integriq`.
- [x] Next release: remove the `/api/stuf/cases|persons|zaken|personen` forwarders.
  - Removed after beta v0.4.53 (15 Oct 2026) shipped them: routes, `StufController::cases()`,
    `persons()`, their legacy aliases, `forwardProviderRequest()` on controller and bridge, and their
    tests. `tests/Unit/Architecture/StufWireBelongsToIntegriqTest.php` keeps them gone.
