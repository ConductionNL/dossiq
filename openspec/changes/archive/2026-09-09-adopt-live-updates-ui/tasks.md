# Tasks — adopt-live-updates-ui

## 1. Dependency

- [x] 1.1 Bump `@conduction/nextcloud-vue` to `^1.0.0-beta.212` (liveUpdatesPlugin default-on
      in `createObjectStore`; first-subscription transport fix).

## 2. View wiring

- [x] 2.1 `WorkflowBoard.vue` — `case` collection subscription (pending marker + epoch
      counter guards; debounced non-blanking `fetchData({ background: true })` on hint,
      skipped mid-drag / mid-bulk-transition; release in `beforeDestroy`).
- [x] 2.2 `DeelzaakDetail.vue` — `or-object-{uuid}` subscription for the viewed sub-case;
      re-scoped on route id change, debounced `reload({ background: true })` on hint,
      release in `beforeDestroy`.

## 3. Verification

- [x] 3.1 `npm run lint` clean on touched files.
- [x] 3.2 `npm test` / unit suite green.
- [x] 3.3 `npm run build` green against the PUBLISHED beta.212 package
      (`USE_LOCAL_LIB=false`; the sibling-source alias path is untouched).
