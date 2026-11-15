# Proposal: the-advice-checks-fail-closed-on-every-path

## Why

Two gaps were left after the advice request guard work.

- `PanelIndependenceChecker` answered "independent" when there was no object store. Today every caller stops earlier when OpenRegister is missing, but the checker itself should never clear a panel it could not look up.
- `transitionAdviceStatus()` marked its own save under the id from the URL. `DeliberationStartGuard` reads the marker under the stored request's id. A slug or another id form the store resolves therefore did not match, and the legitimate transition was refused.

## What changes

- Without an object store the panel check answers unverifiable, and logs why.
- The advice request is keyed on its stored id after it is loaded: the top-level `id`, else `@self.id`. The marker, the save and the trail entries all use that id.

## What does not change

- The access rule of the advice request endpoint.
- Which statuses the guard checks.
