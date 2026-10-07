# Tasks: admin-inspect-entry

Tier: V1. Kind: config. Row 2.22.

- [x] 1.1 `src/manifest.json` `#CaseDetail`: two header actions,
  `case-inspect-raw` and `case-inspect-runs`, both gated on the availability
  endpoint (D-1, D-3). NOT one action with `children`, which a detail page
  never renders.
  - `tests/vitest/caseActionsMenu.spec.js`: both present, both gated, the
    modal target is a registry entry of kind `modal`
  - `@spec openspec/changes/admin-inspect-entry/specs/case-management/spec.md`
- [x] 1.2 `lib/Controller/InspectController.php` +
  `appinfo/routes.php`: `GET /api/inspect/availability` answers
  `{isAdmin: bool}` about the reader (D-3).
  - `tests/Unit/Controller/InspectControllerTest.php`: admin, handler,
    signed-out, and the auth attribute
- [x] 1.3 `src/dialogs/CaseRawDataDialog.vue` + `src/registry.js`: the case as
  Open Register stored it (D-2).
  - `tests/vitest/caseActionsMenu.spec.js`: reads from the route, fetches the
    stored object, prints it, and says so when it cannot
- [x] 2.1 `tests/e2e/admin-inspect.spec.ts`; `openspec validate
  admin-inspect-entry --strict`.
