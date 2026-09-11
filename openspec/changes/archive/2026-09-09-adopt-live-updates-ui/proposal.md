---
kind: code
---

## Why

`@conduction/nextcloud-vue` 1.0.0-beta.212 turns the `liveUpdatesPlugin` on by default for
every `createObjectStore`-based store (lazy — fully inert until the first `subscribe()`
call) and fixes the first-subscription-stranded transport bug. OpenRegister already pushes
`or-object-{uuid}` and `or-collection-{register-slug}-{schema-slug}` events for all
OpenRegister-backed objects, so Dossiq's store gains a working `subscribe(type, id?)` API
from the dependency bump alone. Without view-side adoption, the multi-user surfaces (the
workflow board above all) keep rendering stale data until a manual refresh.

## What Changes

- Bump `@conduction/nextcloud-vue` to `^1.0.0-beta.212`.
- `WorkflowBoard.vue`: subscribe to the `case` collection scope; events are debounced
  refetch hints that re-run the existing `fetchData()` path in non-blanking background mode
  (the template swaps the board for a spinner on `loading`). Refetch is skipped mid-drag /
  mid-bulk-transition — the post-save server event re-hints anyway. Released on destroy.
- `DeelzaakDetail.vue`: per-object `or-object-{uuid}` subscription for the viewed sub-case;
  re-scoped when the route id changes, released on destroy, `reload({ background: true })`
  on hint.

## What Is Deliberately NOT Wired (library gaps, not app gaps)

- `MyWorkCards.vue` and every declarative manifest page (CaseList / CaseDetail etc.): these
  render through `CnIndexPage` self-fetch / `CnPageRenderer`, which use the library's
  default `conduction-objects` store. That store is not `createObjectStore`-based, has no
  `liveUpdatesPlugin`, and `CnIndexPage` exposes no `objectStore` prop — live updates for
  those surfaces must ship in `@conduction/nextcloud-vue` itself.

## Impact

- Affected specs: `realtime-updates-ui` (new)
- Affected code: `package.json`, `src/views/workflow-board/WorkflowBoard.vue`,
  `src/views/cases/DeelzaakDetail.vue`
