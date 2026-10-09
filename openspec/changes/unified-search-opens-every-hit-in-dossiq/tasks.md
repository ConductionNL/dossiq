# Tasks: unified-search-opens-every-hit-in-dossiq

Tier: V1. Kind: config. Rows: 9.1, 9.12. Delivered half: openregister
`unified-search-index` (openregister#3899). Board: `DqKop`.

## 1. Detail pages

- [x] 1.1 `src/manifest.json` `deepLinks`: one entry per schema in design D1's list of 11, `urlTemplate` the page route with `{uuid}` for `:id`, `displayName` the page title (English source; Dutch in `l10n/`).

## 2. Case-owned records

- [ ] 2.1 (partly: definitions read, no seeded object read; see Findings) For every schema with a `case`, `caseId`, `caseRef` or `parentCase` property and no detail page, read one seeded or live object and record whether the field holds a bare case uuid (design D2). Write the result as a table in this file under "Findings".
- [ ] 2.2 (blocked: openregister's search formatter fills no object property, see Findings) `src/manifest.json` `deepLinks`: `/apps/dossiq/cases/{<field>}` for each schema that passed 2.1, `displayName` naming the record kind ("Document on a case", "Contact moment").
- [x] 2.3 `searchable: false` on each schema that failed 2.1, and on the logs in design D3.

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

Read on 2026-10-09 from the property definitions (type, format, `$ref`,
description), not from a seeded or live object: no instance was available to
this build. Tasks 2.1 and 2.2 stay open for that reason and for the one below.

**The blocker.** OpenRegister's unified search builds a hit's link in
`ObjectSearchResultFormatter::format()` from `@self` plus uuid, register and
schema. `DeepLinkRegistration::resolveUrl()` would replace any top-level key,
but it is never handed the object's own properties, so
`/apps/dossiq/cases/{case}` would open literally. Design D1's claim that no
openregister change is needed does not hold. Asked of openregister in
`for-ruben/dossiq-sibling-asks.md`. Until it lands, the 47 schemas below stay
searchable and open OpenRegister's own page, as before; the guard lists them as
`PENDING_CASE_LINK`.

| schema | field | definition | verdict |
| --- | --- | --- | --- |
| adviceRequest, advisoryReport, appealDecision, case-location, caseDocument, caseIncident, caseObject, caseProperty, contactmoment, customerContact, decision, handhavingsactie, hearingSession, inspectieRapport, inspectionResult, objection, obligation, plannedAction, result, role, subsidieAanvraag | case | uuid, `$ref: case` | bare uuid: pending the openregister ask |
| inspectionChecklistRun | case | uuid | bare uuid: pending |
| consultation | parentCase | uuid, `$ref: case` | bare uuid: pending |
| samenwerkverzoek | caseId | uuid, `$ref: case` | bare uuid: pending |
| supplierContract, supplierTender | caseRef | uuid, `$ref: case` | bare uuid: pending |
| wooDocumentAssessment | caseRef | uuid | bare uuid: pending |
| caseFederatedActivity, caseFederatedShare, caseShare | caseId | "UUID of the case" | bare uuid: pending |
| deadlineInstance, portaalBericht | case / caseId | "case id" | bare uuid: pending |
| zaakinformatieobject | case | string; `PortalCaseDocuments` compares it to the case uuid | bare uuid: pending |
| aanvullingsverzoek, beschikking, caseBerichtenboxMessage, caseCustody, caseTakeover, dispatch, fieldInspection, gezinsplan, indicatiestelling, mailIntakeEntry, mandateEscalation, milestoneRecord, reIntegratieTraject, toestemming | case / caseId / caseRef | string, "a reference to the case" | not established without a seeded object: pending |
| supplierMessage, caseSupplierInvoice | caseRef | "the case OR CONTRACT" | not always a case: `searchable: false` |
| aiAuditEntry, sociaalDomeinAuditLog, mandateUsage, zaaksysteemMapping, stateMachineLog | caseId | logs (design D3) | `searchable: false` |

**Opted out under task 3.1 (99 schemas, the review point of design Risks).**
Types and configuration, queues, tenant and supplier records, logs, and every
record with no detail page and no case field. Among them, for review:
`informatieobject`, `document`, `documentLink`, `besluitinformatieobject`,
`decisionDocument` (documents reach a case through `zaakinformatieobject`, not
a field of their own), `complaint`, `hearing`, `portaalVerzoek`, and the social
domain records `wmoZaak`, `jeugdwetZaak`, `participatiewetZaak`. A hit on one of
these opened OpenRegister's raw object page; now it is not a hit. One that a
handler must find moves to group 1 or 2 by a deep link.
