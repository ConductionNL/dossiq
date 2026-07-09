## 1. Audit-log pagination and server-side filtering

- [ ] 1.1 In `lib/Controller/ArchiefController.php`, add `_limit`/`_offset` request params
      (default page size, e.g. 50) to the `auditLog()` action (line 359) and thread them through
      `fetchAll()` (line 637) into the `searchObjectsAsArrays()` filters array.
- [ ] 1.2 Rewrite `batchAuditEvents()` (line 561) to pass `['details' => ...]`-style server-side
      filtering (or the batch id as an object-field filter, whatever `searchObjectsAsArrays`
      supports for substring/contains matching) instead of fetching every row and filtering with
      `array_filter`/`str_contains` in PHP.
- [ ] 1.3 Update the Vue caller(s) of `/api/archief/audit-log` (`src/` — find via
      `grep -rn "audit-log" src/`) to pass/paginate the new `_limit`/`_offset` params and render a
      "load more" / paginated list instead of assuming one full payload.
- [ ] 1.4 In `lib/Controller/SubstitutionController.php`, add a `_limit` to `allSubstitutions()`
      (line 432) consistent with the pattern already used in
      `lib/Controller/RaadsinformatieFeedController.php` (`self::FEED_LIMIT`).
- [ ] 1.5 Add/extend PHPUnit coverage: assert `auditLog()` respects `_limit`/`_offset` and that
      `batchAuditEvents()` no longer relies on an unbounded full fetch (mock the object service to
      assert the filter array passed to `searchObjects`/`searchObjectsBySlug`).

## 2. Production build hygiene

- [ ] 2.1 In `webpack.config.js` line 9, change the production (`isDev === false`) devtool from
      `'source-map'` to a non-source-exposing option (`'nosources-source-map'` or `false`);
      keep `'cheap-source-map'` for dev.
- [ ] 2.2 Confirm the built `js/*.js.map` artifacts (if any remain) are excluded from the
      Nextcloud app-store package (check `.gitattributes`/build packaging scripts / REUSE.toml
      exclusions) or contain no original source.
- [ ] 2.3 Rebuild (`npm run build`) and confirm bundle size + absence of inlined source content in
      the production output.

## 3. Manifest reactivity

- [ ] 3.1 In `src/main.js`, import `markRaw` from `'vue'` and wrap the manifest object at the
      point it is read for the render tree (around line 129/197) so `App.vue`'s `manifest` prop
      receives a non-reactive object.
- [ ] 3.2 Verify the case-type-navigation backend delta (the one part of the manifest that
      legitimately needs to trigger a re-render, per the existing comment at line 121-127) still
      updates the nav after `markRaw` — this requires re-assigning a new (also `markRaw`'d) merged
      object to the `resolvedManifest` ref rather than mutating the raw object in place, so the ref
      change itself (not deep property reactivity) drives the re-render.
- [ ] 3.3 Manually verify in a running dev instance: case-type nav still updates without a page
      reload after the `/api/manifest` delta resolves (per the existing inline comment's stated
      contract).

## 4. Spec + traceability

- [ ] 4.1 Add the new `performance-hardening` capability spec (this change) and run
      `openspec validate performance-hardening-audit-log-and-boot --strict`.
- [ ] 4.2 Add `@spec openspec/changes/performance-hardening-audit-log-and-boot/specs/performance-hardening/spec.md`
      to the touched methods.
- [ ] 4.3 Fix any pre-existing PHPCS/PHPStan/PHPMD warnings encountered in the touched files while
      implementing this change (project convention — do not defer).

## 5. Verification

- [ ] 5.1 Live-verify: `GET /api/archief/audit-log?_limit=50&_offset=0` returns at most 50 rows
      even when the register holds more.
- [ ] 5.2 Live-verify: production build's `js/procest-main.js` (or equivalent) is not accompanied
      by a full-fidelity `.js.map` exposing original source.
