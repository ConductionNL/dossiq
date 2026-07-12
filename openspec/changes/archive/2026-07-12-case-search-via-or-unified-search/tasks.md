## 1. Register definition

- [x] 1.1 Add `"searchable": true` at schema level (sibling of `title`/`properties`, matching the `ori_register.json` convention) to `case`, `task`, `bezwaar`, `voorstel`, `beroep` in `lib/Settings/procest_register.json`. Do NOT flag any other schema.
- [x] 1.2 Bump `info.version` in `lib/Settings/procest_register.json` (0.11.0 → 0.12.0).
- [x] 1.3 Bump `<version>` in `appinfo/info.xml` by a patch level.
- [x] 1.4 Re-validate `lib/Settings/procest_register.json` parses as JSON after editing (`python3 -m json.tool` or equivalent).

## 2. Deep links

- [x] 2.1 Extend `deepLinks` in `src/manifest.json` with entries for `bezwaar` (`/apps/procest/bezwaren/{uuid}`, displayName "Bezwaar"), `voorstel` (`/apps/procest/voorstellen/{uuid}`, displayName "Voorstel"), `beroep` (`/apps/procest/beroepen/{uuid}`, displayName "Beroep"). Keep the existing `case`/`task` entries unchanged.

## 3. Tests

- [x] 3.1 Add a vitest unit test (e.g. `src/tests/unit/searchable-schemas.spec.js`, following the existing vitest layout) asserting: (a) exactly `case`, `task`, `bezwaar`, `voorstel`, `beroep` are flagged `searchable: true` in `lib/Settings/procest_register.json`; (b) every searchable schema slug has a matching `deepLinks` entry in `src/manifest.json`; (c) each deepLink `urlTemplate` corresponds to a manifest page route (`/cases/:id`, `/tasks/:id`, `/bezwaren/:id`, `/voorstellen/:id`, `/beroepen/:id`).
- [x] 3.2 Run the new test plus the existing vitest suite: `USE_LOCAL_LIB=false npx vitest run src/tests/unit/searchable-schemas.spec.js` (and confirm no existing tests regress via the project's standard `npm test` if feasible).

## 4. Verify

- [x] 4.1 `openspec validate case-search-via-or-unified-search` passes.
- [x] 4.2 Manifest still validates (run the project's manifest validation if a script exists, e.g. `npm run validate:manifest` or the schema-validation step in the build).

## Acceptance Criteria

- Exactly five schemas (`case`, `task`, `bezwaar`, `voorstel`, `beroep`) are `searchable: true`; all other schemas unflagged.
- `deepLinks` covers all five searchable schemas with correct detail-route templates.
- Register `info.version` and app `<version>` both advanced.
- New vitest consistency test passes; existing suites unaffected.

## Quality Checklist

- Config-only; no PHP or Vue component changes.
- No new dependencies.
- i18n: displayName values are proper nouns (Dutch legal terms), no translatable UI strings added.
- Follows ADR-022: no procest-side IProvider; OR leaf consumed declaratively.
