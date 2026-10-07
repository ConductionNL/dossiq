# Tasks: unified-search-opens-every-hit-in-dossiq

Tier: V1. Kind: config. Rows: 9.1, 9.12. Delivered half: openregister
`unified-search-index` (openregister#3899). Board: `DqKop`.

## 1. Detail pages

- [ ] 1.1 `src/manifest.json` `deepLinks`: one entry per schema in design D1's list of 11, `urlTemplate` the page route with `{uuid}` for `:id`, `displayName` the page title (English source; Dutch in `l10n/`).

## 2. Case-owned records

- [ ] 2.1 For every schema with a `case`, `caseId`, `caseRef` or `parentCase` property and no detail page, read one seeded or live object and record whether the field holds a bare case uuid (design D2). Write the result as a table in this file under "Findings".
- [ ] 2.2 `src/manifest.json` `deepLinks`: `/apps/dossiq/cases/{<field>}` for each schema that passed 2.1, `displayName` naming the record kind ("Document on a case", "Contact moment").
- [ ] 2.3 `searchable: false` on each schema that failed 2.1, and on the logs in design D3.

## 3. Everything else

- [ ] 3.1 `searchable: false` on every remaining schema in `lib/Settings/dossiq_register.json` and `lib/Settings/register.d/*.json` (types, configuration, queues, tenant and supplier records, logs). Do not touch `lib/Settings/dossiq_mock_register.json` beyond keeping it in step with `dossiq_register.json`.
- [ ] 3.2 Bump `info.version` in each touched fragment and in `dossiq_register.json`, the version in `appinfo/info.xml`, and run `php tools/schema-version-digests.php` (design D4).

## 4. Guard

- [ ] 4.1 `tests/vitest/searchableSchemas.spec.js`: replace the three-slug pin with the rule of design D5 (linked or opted out; every `{field}` a property of its schema; every template a manifest route). Keep the assertion that `/tasks/:id` exists.
- [ ] 4.2 `tests/vitest/searchableSchemas.spec.js`: a fixture schema with neither fails with its slug in the message.

## 5. Check

- [ ] 5.1 `openspec validate unified-search-opens-every-hit-in-dossiq --strict`; `npx vitest run tests/vitest/searchableSchemas.spec.js`.
- [ ] 5.2 Live: after `occ upgrade` (appstore off, see the dev-environment notes), search a seeded `caseDocument` title, a `contactmoment` summary and a `decisionType` title in the Nextcloud header. The first two open the case; the third is not a hit.

## Findings

(filled in by task 2.1)
