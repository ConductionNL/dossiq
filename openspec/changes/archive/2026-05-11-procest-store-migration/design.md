# Design — procest store migration

## Context

Procest's `src/store/modules/object.js` already wires `createObjectStore('object', { plugins: [...] })` from `@conduction/nextcloud-vue`. The Pinia store ID is `'object'` (chosen at app inception). All `useObjectStore()` calls return the lib-backed store.

What is broken is **callsite drift**: 5 method names referenced by procest code do not exist in the lib. They are leftovers from an earlier hand-rolled store implementation that was replaced module-by-module without sweeping the consumers.

## Decision: Pattern 1 (drop) — direct rewrite of phantom calls

Three patterns were on the table per the task brief:

| Pattern | Description | Verdict |
|---|---|---|
| **drop** | Replace each phantom call with the lib's canonical API at the call site. | **chosen** |
| thin-wrap | Add `getObjects`/`getAll`/etc. as aliases on the store via a plugin or wrapper. | Rejected — perpetuates the old vocabulary, future engineers re-introduce phantoms by copy-paste, and the code diverges further from decidesk/openconnector/zaakafhandelapp. |
| hybrid | Drop in core call sites, alias in sub-stores. | Rejected — sub-stores are the densest cluster (gis.js has 4 phantoms, bezwaar.js has 5); hiding them behind aliases buries the bug class we're trying to eradicate. |

Decidesk's #162 bug landed because their custom store had not been migrated at all — the lesson is "use the lib's vocabulary verbatim". Procest is one step ahead (object.js is already lib-backed), so the smaller, surgical drop pattern is the right move.

## API mapping

```text
LEGACY                                 →  LIB
─────────────────────────────────────────────────────────────────────────
objectStore.getObjects(t, { filters:{f:v}, limit:n })
                                       →  objectStore.fetchCollection(t, { '_filters[f]': v, _limit: n })
objectStore.getAll(t)                  →  objectStore.fetchCollection(t, {})
objectStore.create(t, data)            →  objectStore.saveObject(t, data)
objectStore.update(t, id, data)        →  objectStore.saveObject(t, { id, ...data })
objectStore.delete(t, id)              →  objectStore.deleteObject(t, id)
objectStore.fetchOne(t, id)            →  objectStore.fetchObject(t, id)
objectStore.uploadFile(caseId, file)   →  const fd = new FormData(); fd.append('file', file)
                                          objectStore.uploadFiles('case', caseId, fd)
```

`fetchCollection` returns the **results array** (not a wrapper); legacy callers that did `result?.results || result || []` can be simplified to take the return value directly.

## Consumer files

### Sub-stores (in `src/store/modules/`)

| File | Phantom calls | Migration |
|---|---|---|
| `bezwaar.js` | 5 × `getObjects` | rewrite to `fetchCollection` with `_filters[case]` shape |
| `gis.js` | `getAll`, `create`, `update`, `delete` (one each) | rewrite to `fetchCollection`/`saveObject`/`deleteObject` |
| `inspection.js` | 1 × `uploadFile` | rewrite to `uploadFiles` (filesPlugin), wrap file in FormData |

### Views (in `src/views/`)

| File | Phantom calls | Migration |
|---|---|---|
| `CaseMapView.vue` | 2 × `getAll('case'\|'caseType')` | `fetchCollection(t, {})` |
| `dashboard/CaseMapWidget.vue` | 1 × `getAll('case')` | `fetchCollection('case', {})` |
| `voorstellen/VoorstelDetail.vue` | 1 × `fetchOne('voorstel', id)` | `fetchObject('voorstel', id)` |

### Out of scope (per task brief)

- `settings.js` — procest-specific `/apps/procest/api/settings` endpoint, not OR object CRUD. Stays as plain `defineStore`.
- `zgwMapping.js` — procest-specific `/apps/procest/api/zgw-mappings` endpoint, not OR object CRUD. Stays as plain `defineStore`.
- `bezwaar.js`, `advice.js`, `enforcement.js`, `gis.js`, `inspection.js`, `workflow.js` — workflow/business-logic stores that *use* the lib's object store internally. They are not direct CRUD wrappers and stay as plain `defineStore`s. Only their internal phantom calls are migrated.

### `store.js` orchestrator

The 19 `if (config.register && config.X_schema) { objectStore.registerObjectType(...) }` blocks are verbose but functional and use the lib's `registerObjectType` API correctly. Out of scope for this change — leave as-is to keep the diff focused.

## Custom-fallback list (lib gaps observed)

None. Every legacy call has a direct equivalent in the lib's `useObjectStore` surface plus the bundled `filesPlugin`. The drop migration is lossless.

## Validation strategy

1. `npx eslint src/store/ src/views/CaseMapView.vue src/views/voorstellen/VoorstelDetail.vue src/views/dashboard/CaseMapWidget.vue src/store/modules/inspection.js`
2. `node tests/validate-manifest.js` (manifest unaffected; smoke check)
3. `npx webpack --config webpack.config.js --mode production` (proves the bundle still builds)
4. `grep -rEn '\.(getObjects|getAll|fetchOne|uploadFile)\b' src/` — must return zero hits in the touched files (and only legitimate non-store hits elsewhere).

## Out of scope

- `store.js` orchestrator simplification — separate change.
- Migrating `settings.js` / `zgwMapping.js` to the lib — separate change (would need a generic config-store primitive in the lib first).
- Refactoring sub-stores into pure composables — separate change (Vue 3 migration).
