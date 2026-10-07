---
kind: code
depends_on: []
---

# Proposal: setup-wizard-stays-closed-on-server

## Summary

An administrator who closes the setup wizard does not see it again in another
browser. dossiq adopts `@conduction/nextcloud-vue` 2.71.0, declares
`setup.dismissAction`, and records the close on the server.

## Why

Until now the close lived only in `localStorage`. A new browser profile, a new
device or every Playwright context found the demo-data step open and opened
the wizard over the first page again. Ruben decided on 7 October 2026 that the
server-side close is for every app.

nextcloud-vue 2.71.0 (manifest schema 2.53.0) posts
`POST /apps/{appId}/api/setup/action/{dismissAction}` with `{ finished }` once
when the wizard is closed or finished. It then reads `dismissed` from
`GET /api/setup/status`: `true`, or the setup version the wizard was closed at.

## What changes

- `@conduction/nextcloud-vue` goes to `^2.71.0`, and the vendored manifest
  schema in `tests/schemas/` is refreshed from the package.
- `src/manifest.json` declares `setup.dismissAction: "dismiss-setup"`.
- `SetupController::runAction('dismiss-setup')` stores the setup version under
  `setup_dismissed_version`. It writes nothing else: the demo-data choice and the
  dwangsom secret stay exactly as the administrator left them.
- `SetupController::status()` reports `dismissed: <version>` once that key is
  stored. A later `setup.version` bump opens the wizard again.
- The `dwangsom-secret` config-fields step gets an intro (`body`), which 2.71.0
  now draws above the fields.

## Choice between the two contracts

The library offers two answers: answer the open optional steps, or report
`dismissed`. dossiq reports `dismissed`. Answering the demo-data step would
mean writing a choice the administrator never made, and the brief forbids
overwriting a real choice. Reporting the close keeps the steps truthful and
lets a version bump reopen the wizard.
