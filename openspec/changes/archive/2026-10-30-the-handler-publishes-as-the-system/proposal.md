---
kind: code
depends_on: []
---

# Proposal: the-handler-publishes-as-the-system

## Why

Rehearsal R2 (15 Oct): "Publiceren (Woo)" answered 503. Publishing writes a publication into OpenCatalogi's `publication` schema, and the handler holds no create right there, so OpenCatalogi refused and dossiq reported "OpenCatalogi did not accept the publication".

## Decision (Ruben, 16 Oct)

dossiq publishes as the system, after checking that the caller may act on this case: they are its handler (have claimed it), and the case is at the publication step. The same scoped elevation as `DocumentProjectionService::asSystem` (#3965): OpenRegister's `runAsSystem`, entered only after the caller is checked.

## What Changes

- `HandlerActsAsSystem` (generic, named by what it does): refuses a caller who is not the case's handler with 403 `not-the-handler`, refuses a closed case or one whose step field is not at an allowed value with 422 `not-at-this-step`, and otherwise runs the act through `runAsSystem`.
- Publish (step: `wooPublicationStatus` is `ready` or `published`) and withdraw (step: `published`) go through it. Both write the same OpenCatalogi schema.
- The refusals answer `{message, error}` in the reader's language; a non-handler no longer gets the framework's English exception.

## Impact

- `lib/Service/Cases/HandlerActsAsSystem.php` (new), `lib/Controller/WOOAssessmentController.php`, l10n.
- System context through `runAsSystem`; no `_rbac` flags.
