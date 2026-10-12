# Tasks: unified-search-opens-every-hit-in-dossiq

Tier: V1. Kind: config. Rows: 9.1, 9.12. Delivered half: openregister
`unified-search-index` (openregister#3899). Board: `DqKop`.

## 1. Detail pages

- [x] 1.1 `src/manifest.json` `deepLinks`: one entry per schema in design D1's list of 11, `urlTemplate` the page route with `{uuid}` for `:id`, `displayName` the page title (English source; Dutch in `l10n/`).

## 2. Case-owned records

- [ ] 2.1 (34 of 47 decided from the code on 2026-10-10; 13 wait on a read of a seeded or live object, live pass, decision 139) For every schema with a `case`, `caseId`, `caseRef` or `parentCase` property and no detail page, read one seeded or live object and record whether the field holds a bare case uuid (design D2). Write the result as a table in this file under "Findings".
- [x] 2.2 `src/manifest.json` `deepLinks`: `/apps/dossiq/cases/{<field>}` for the 31 schemas that passed 2.1, `displayName` naming the record kind ("Document on a case", "Term on a case"), English source with Dutch in `l10n/nl.json`. Needs openregister#4555 (the search formatter hands the registry the object's own properties); before it lands, these hits open with a literal `{case}`. Guard: `tests/vitest/searchableSchemas.spec.js` (every `{field}` a property of its schema, every template a manifest route).
- [x] 2.3 `searchable: false` on each schema that failed 2.1, and on the logs in design D3. 2026-10-10: `gezinsplan` (1.2.1), `indicatiestelling` (1.2.1) and `reIntegratieTraject` (1.1.1) in `lib/Settings/register.d/50-sociaal-domein.json`; register 0.20.24, digests recorded.

## 3. Everything else

- [x] 3.1 `searchable: false` on every remaining schema in `lib/Settings/dossiq_register.json` and `lib/Settings/register.d/*.json` (types, configuration, queues, tenant and supplier records, logs). Do not touch `lib/Settings/dossiq_mock_register.json` beyond keeping it in step with `dossiq_register.json`.
- [x] 3.2 Bump `info.version` in each touched fragment and in `dossiq_register.json`, the version in `appinfo/info.xml`, and run `php tools/schema-version-digests.php` (design D4).

## 4. Guard

- [x] 4.1 `tests/vitest/searchableSchemas.spec.js`: replace the three-slug pin with the rule of design D5 (linked or opted out; every `{field}` a property of its schema; every template a manifest route). Keep the assertion that `/tasks/:id` exists.
- [x] 4.2 `tests/vitest/searchableSchemas.spec.js`: a fixture schema with neither fails with its slug in the message.

## 5. Check

- [x] 5.1 `openspec validate unified-search-opens-every-hit-in-dossiq --strict`; `npx vitest run tests/vitest/searchableSchemas.spec.js`.
- [ ] 5.2 (not run: needs a live instance) Live: after `occ upgrade` (appstore off, see the dev-environment notes), search a seeded `caseDocument` title, a `contactmoment` summary and a `decisionType` title in the Nextcloud header. The first two open the case; the third is not a hit.

## Findings

Updated 2026-10-10. The blocker is built: openregister#4555 makes
`ObjectSearchResultFormatter` hand the deep-link registry the object's own
scalar properties (URL-encoded, under `@self` and the ids), and falls back to
OpenRegister's page when a placeholder stays unfilled. Decided from the
property definitions and from the code that writes the field; no instance was
used (decision 139).

| schema | field | evidence | verdict |
| --- | --- | --- | --- |
| adviceRequest, advisoryReport, appealDecision, case-location, caseDocument, caseIncident, caseObject, caseProperty, contactmoment, customerContact, decision, handhavingsactie, hearingSession, inspectieRapport, inspectionResult, objection, obligation, plannedAction, result, role, subsidieAanvraag, inspectionChecklistRun | case | `format: uuid` (most with `$ref: case`); OpenRegister refuses a value that is not a uuid | linked |
| consultation | parentCase | `format: uuid`, `$ref: case` | linked |
| samenwerkverzoek | caseId | `format: uuid`, `$ref: case` | linked |
| supplierContract, supplierTender, wooDocumentAssessment | caseRef | `format: uuid` | linked |
| aanvullingsverzoek | case | `AanvullingsverzoekService` writes its `$caseId`, documented "The case UUID" | linked |
| beschikking | caseId | `BeschikkingGenerationService::generateBeschikking()` writes `$caseId`, "The UUID of the zaak" | linked |
| deadlineInstance | case | `TermijnService::createTermijnInstance()` writes the case id it is armed for | linked |
| zaakinformatieobject | case | `LoadDefaultZgwMappings`: `{{ zaak \| zgw_extract_uuid }}` | linked |
| gezinsplan, indicatiestelling, reIntegratieTraject | caseId | "A reference to the youth act / social support act / participation act case": a social-domain case schema (opted out itself), not a dossiq `case` | `searchable: false` |
| caseBerichtenboxMessage, caseCustody, caseFederatedActivity, caseFederatedShare, caseShare, caseTakeover, dispatch, fieldInspection, mailIntakeEntry, mandateEscalation, milestoneRecord, portaalBericht, toestemming | case / caseId / caseRef | plain string; no writer in dossiq's PHP shows the value | pending a seeded or live read (guard: `PENDING_CASE_LINK`) |
| supplierMessage, caseSupplierInvoice | caseRef | "the case OR CONTRACT" | `searchable: false` (earlier) |
| aiAuditEntry, sociaalDomeinAuditLog, mandateUsage, zaaksysteemMapping, stateMachineLog | caseId | logs (design D3) | `searchable: false` (earlier) |

Live recipe for the 13 pending (task 2.1) and 5.2: on an instance with
openregister#4555, for each pending schema read one object
(`GET /index.php/apps/openregister/api/objects/dossiq/<schema>?_limit=1`) and
check the field against a case uuid; then search a seeded `caseDocument` title,
a `contactmoment` summary and a `decisionType` title in the Nextcloud header.
The first two open `/apps/dossiq/cases/<uuid>`; the third is not a hit.

**Opted out under task 3.1 (99 schemas, the review point of design Risks).**
Types and configuration, queues, tenant and supplier records, logs, and every
record with no detail page and no case field. Among them, for review:
`informatieobject`, `document`, `documentLink`, `besluitinformatieobject`,
`decisionDocument` (documents reach a case through `zaakinformatieobject`, not
a field of their own), `complaint`, `hearing`, `portaalVerzoek`, and the social
domain records `wmoZaak`, `jeugdwetZaak`, `participatiewetZaak`. A hit on one of
these opened OpenRegister's raw object page; now it is not a hit. One that a
handler must find moves to group 1 or 2 by a deep link.
