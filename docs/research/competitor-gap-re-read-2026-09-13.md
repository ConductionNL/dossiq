# Competitor gap re-read, 2026-09-13

The gap register in `ConductionNL/market-intelligence` (`procest/_gaps/`, written
2026-09-13) rates dossiq from the matrix read on 2026-09-07 at `add765a06`.
Seventeen round 2 changes were archived after that reading. This file re-reads
every row the register marks as a re-rate against dossiq `development` at
`828da9a69` (2026-09-13), with the matrix's own evidence convention: a
`#PageId/widgetId` in `src/manifest.json`, a schema slug in
`lib/Settings/dossiq_register.json` or `lib/Settings/register.d/`, a `lib/` path
with a line, or a stated empty grep. Upstream artefacts were checked by
`gh api repos/ConductionNL/<repo>/contents/<path>?ref=development` on the same day.

The register's README counts 34 re-rate rows. The register's data carries the
word re-rate on 36 rows: 30 whose covering artefact shipped after the reading,
and 6 whose note says re-rate when a named upstream change lands. All 36 are
read here. The register's decision table is updated by a later lane from this
file.

## Counts

| consequence | rows |
|---|---|
| gap closed | 12 |
| gap stays | 22 |
| gap stays, narrowed | 4 |
| new gap | 1 |

The 12 closed: 2.7, 2.12, 2.15, 3.3, 3.10, 5.1, 9.10, 11.21, 11.22, 12.15, 12.17,
13.11. The 4 narrowed: 2.5, 3.5, Q10.12, and 2.3 (read on the way; see the
addendum). The 1 new: 2.20, which the register marks none needed and which the
tree shows unwired.

## The table

| id | old rating | new rating | evidence | consequence |
|---|---|---|---|---|
| 1.8 | partial | partial | `#CaseDetail/headerActions.plan-follow-up`; `lib/Service/Flow/PlannedFollowUpDocument.php:8-10` says the cron pins the planned date rather than a recurrence | gap stays: `planned-case-series` |
| 2.4 | partial | partial | `#CaseDetail/headerActions` = add-party, link-object, log-contact, generate-document, case-suspend, case-resume, case-extend, case-reopen, copy-case, start-flow, plan-follow-up; `grep -n -i claim src/manifest.json` hits only notes | gap stays: `case-claim-action` |
| 2.5 | partial | partial (narrowed) | `dossiq_register.json#case.assignedGroup` (line 1765); Team column on `#Cases` (manifest line 1151); `assigneeGroup` on `#Tasks` (line 2547); `#Cases/quickFilters` = All, Mine, Unclaimed, Closed, Overdue, Due this week, no Team chip; parties-on-the-case task 3.4 is blocked on a nextcloud-vue `quickFilters` value that resolves to the signed-in user's `organisatieRol` ids | gap stays, narrowed to the chip. Deliberate no for a dossiq change: the property shipped, the chip is a nextcloud-vue value. `case-team-assignment` is not opened |
| 2.7 | partial | yes | `dossiq_register.json#statusType.colour` (line 409) and `#statusType.hiddenInLists` (line 430); `case.statusHiddenInLists`; `tests/e2e/case-type-status-authoring.spec.ts` | gap closed |
| 2.12 | partial | yes | `#CaseDetail/headerActions.case-reopen` (manifest line 2095) over `lib/Controller/CaseLifecycleController.php` | gap closed |
| 2.15 | partial | yes | `#CaseDetail/headerActions.link-object`; Objects section of `case-related-panel` over `caseObject`; `#CaseObjects` index (route `/case-objects`) with `folderSidebar` on `objectType`; `tests/e2e/case-objects.spec.ts` | gap closed |
| 2.20 | no | no | `#CaseDetail/case-flow-runs` carries `pollSeconds: 15`; `liveUpdatesPlugin` is referenced only in `src/views/workflow-board/WorkflowBoard.vue:176` and `src/views/cases/DeelzaakDetail.vue:159`, never on `CaseDetail` | new gap: the spec `realtime-updates-ui` is not wired on the case page. Opened as `live-updates-on-the-case-page` (S) |
| 3.3 | partial | yes | `dossiq_register.json#statusType.checklist` (line 437); `lib/Service/Transitions/CreateTaskHandler.php:17` reads the status checklist | gap closed |
| 3.5 | partial | partial (narrowed) | `#Tasks/quickFilters` = All, Mine, Unclaimed, Closed, Overdue, Due this week over `entitySource: tasks`; `remove-casetask` has 38 of 41 tasks done and kept the chips; no Team chip, same nextcloud-vue blocker as 2.5 | gap stays, narrowed to the chip. `task-lenses-on-engine-store` is not opened |
| 3.10 | partial | yes | `#CaseDetail/headerActions.start-flow` over `caseType.startableFlows` and `case.hasStartableFlows`; case-actions-menu task 2.3 waits on an openregister `userStartable` flag as a refinement | gap closed |
| 3.18 | partial | partial | `case-tasks` is a `case-task-pane` inside `case-work-panel`, a tab of `case-panels` (manifest line 1668); the pane shows the first open task with lifecycle buttons, inside the tab strip and not beside it | gap stays. Deliberate no for a dossiq change: a pane beside the tabs is a page layout per case type, row 11.6, owner buildiq |
| 4.16 | partial | partial | `#CaseDetail/case-files` is the `files` integration leaf; the register cites `documents-live-on-the-case`, which the tree names `openspec/changes/documents-on-the-case` (2 of 13 tasks done) | gap stays (owner nextcloud; dossiq's half is that change) |
| 4.20 | partial | partial | `lib/Service/Beschikking/OpenRegisterArchivalAdapter.php:87-121` computes retention and destruction date for the beschikking only; nothing runs a transfer on closed cases | gap stays (openregister) |
| 5.1 | partial | yes | `case-people-panel` section Parties: widget `case-roles`, an object-list over `role` filtered `case: @objectId`; `#CaseDetail/headerActions.add-party`; `tests/e2e/case-parties.spec.ts` | gap closed |
| 6.7 | partial | partial | `register.d/50-zaakportaal.json#portaalBericht` still declared (lines 4, 280, 307); move-portals-to-portaliq T7 is deferred on portaliq#16 | gap stays (portaliq) |
| 7.7 | partial | partial | `dossiq_register.json#resultType.archivalPeriod` (line 503) and `.archivalAction` (line 508) are declared; `OpenRegisterArchivalAdapter.php:87` reads `bewaartermijn` from TMLO metadata, not from the result type; `grep -rn archivalPeriod lib/Service` hits only the adapter's default | gap stays (openregister; the wiring is dossiq's and is listed in the umbrella) |
| 8.7 | partial | partial | as 7.7; `computeDestructionDate` (adapter line 112) exists for the beschikking only; `#CaseDetail/case-terms` includes statutoryTerm, legalBasis, paymentIndication, lastPaymentDate and no destruction date | gap stays (openregister) |
| 9.1 | partial | partial | openregister `openspec/changes/unified-search-index` is still an open change | gap stays |
| 9.10 | no | yes | `#CaseObjects` index page with `folderSidebar` (`source: field`) on `objectType`, menu entry `CaseObjectsMenu`; `tests/e2e/case-objects.spec.ts` | gap closed |
| 9.12 | partial | partial | as 9.1 | gap stays |
| 10.1 | partial | partial | nextcloud-vue `openspec/changes/dashboard-layout-per-user` is still an open change | gap stays |
| 11.13 | no | partial | openregister `openspec/specs/register-i18n/spec.md` is `status: done` (a `translatable` flag per property, Accept-Language negotiation); it manages register content, not UI strings, and C05 keeps UI strings in l10n | gap stays as a deliberate no (C05); no dossiq change |
| 11.14 | partial | partial | thematiq `openspec/specs/per-app-theming/spec.md` is `status: done` and is an exclusion list, not branding; dossiq keeps `src/utils/statusColour.js` and `src/views/settings/tabs/TenantOnboardingTab.vue` | gap stays (thematiq) |
| 11.21 | partial | yes | `dossiq_register.json#caseType.parentCaseType` (line 206); `lib/Service/CaseTypeResolver.php`; `tests/e2e/case-type-parent-chain.spec.ts` | gap closed |
| 11.22 | partial | yes | `caseType.processesPersonalData` (line 261), `.personalDataCategories` (line 268), `.legalBasis`, `.verwerkingsactiviteit` (line 303); widget `case-type-privacy`; menu `AvgRegisterLink` into `/apps/openregister/#/avg` | gap closed |
| 11.23 | partial | partial | `#CaseTypes/folderSidebar` on `category` (manifest line 1049); `propertyDefinition` has no category property | gap stays: `attribute-catalogue-folders` |
| 12.15 | no | yes | openregister `openspec/specs/search-index/spec.md` is `status: done` (Solr and Elasticsearch behind `SearchBackendInterface`); dossiq's `case-search-via-or-unified-search` spec consumes it | gap closed by abstraction; the openregister lane confirms the dossiq register is indexed |
| 12.17 | partial | yes | `#Integrations` index page (route `/settings/integrations`, manifest line 4633) and menu entry; integriq `openspec/specs/connector-catalog/spec.md` exists | gap closed |
| 13.10 | partial | partial | as 8.7 | gap stays (openregister) |
| 13.11 | partial | yes | as 11.22 | gap closed |
| Q10.12 | no | partial (narrowed) | `#CaseDetail/case-kpis-hours` places humaniq's `humaniq-hours` leaf (manifest line 1307); `hours-onto-humaniq-leaf` has 8 of 8 tasks done; humaniq `openspec/specs/hours-leaf/spec.md` is `status: done` and answers for any object | gap stays, narrowed: effort on something other than a case is booked in humaniq itself. No dossiq change |
| Q12.25 | no | no | `grep -rn easter_date lib/` hits only the header comment of `lib/Service/WorkingDayCalculator.php:13-17`; `composer.json` requires `php ^8.3` and `ext-zip`; `appinfo/info.xml` declares php 8.3 and Nextcloud 32; no prerequisites surface | gap stays, the register's stale note corrected: `declared-prerequisites` |
| Q4.25 | no | partial | openregister `openspec/specs/text-extraction/spec.md` is `status: done`; nothing in dossiq enables content search on its register | gap stays; the dossiq half is one configuration task, listed in the umbrella |
| Q2.27 | partial | partial | openregister `openspec/changes/generated-identifier` is still an open change | gap stays |
| Q2.30 | no | no | as Q2.27 | gap stays |
| Q8.19 | partial | partial | `lib/Service/TermijnService.php:109` runs `modify('+N days')` on a date string with no zone; `grep -rn -i "timezone\|DateTimeZone\|Europe/Amsterdam" lib/Service/Termijn*.php lib/Service/WorkingDayCalculator.php` returns nothing | gap stays; the zone statement is a requirement of `working-calendar-on-every-term` |

## Addendum: rows read on the way

These rows are not marked re-rate in the register, but the same archived
changes moved them.

| id | old rating | new rating | evidence | consequence |
|---|---|---|---|---|
| 2.3 | partial | partial (narrowed) | `caseType.version`, `.versionDate`, `.previousVersion`, `.supersededBy`, `.validFrom`, `.validUntil`, `.isDraft` are declared; `lib/Controller/CaseDefinitionController.php:259` `newVersion()`; `openspec/specs/zaaktype-versioning/spec.md` REQ-ZV-02; `#CaseTypeDetail/headerActions` = case-type-export, case-type-import, case-type-duplicate, case-type-publish, no New version | gap stays, narrowed: `case-type-version-chain` shrinks from M to S |
| 3.8 | partial | partial | `lib/Service/AssigneeResolver.php:76-109` resolves the authored assignee, then the declared fallback, and answers `''` when neither names anyone; the case handler is not a default | gap stays: `task-defaults-to-case-handler` |
| Q10.14 | partial | partial | 47 `catch (\Throwable)` sites in 37 files under `lib/Service/` return `null` or `[]` within three lines (the register counted 24 of 160; the tree now holds 276 catches); 93 of 106 files under `tests/Unit/Controller/` mention `getStatus()` | gap stays: `refusals-carry-a-status`, with the new counts |
| Q11.31 | no | no | 153 schemas are declared across `dossiq_register.json` and `register.d/`; a word-bounded grep finds 72 names nowhere under `src/` and 27 of those nowhere under `lib/` outside `lib/Settings/` either (`adviceResponse`, `advisoryBody`, `avgIncident`, `callbackRequest`, `complaintCategory`, `complaintDisposition`, `indicatiestelling`, `jeugdwetZaak`, `kccAgent`, `kccQuickAction`, `mandateArrangement`, `mandateEscalation`, `mandateUsage`, `mdoOverleg`, `medewerkerRolToewijzing`, `milestoneRecord`, `participatiewetZaak`, `portaalNotificatieVoorkeur`, `reIntegratieTraject`, `specialistBeschikbaarheid`, `subsidieVaststelling`, `supplierKpi`, `supplierUser`, `syncQueue`, `termijnGebeurtenis`, `wmoZaak`, `zaaktypeInformatieobjecttype`). A grep by name misses slug constants and Dutch aliases, so the count is an upper bound for the structural test to replace | gap stays: `no-schema-without-a-surface`, larger than the eight cells the register cites |

## Deliberate no's recorded in this lane

- **2.5 `case-team-assignment` and 3.5 `task-lenses-on-engine-store`.** The
  property, the column and the lenses shipped. What is left is one chip whose
  value nextcloud-vue cannot resolve yet (parties-on-the-case 3.4). A dossiq
  change would carry no requirement of its own.
- **3.18 task pane beside the tabs.** The pane exists inside the Work tab.
  Moving it beside the tab strip is a page layout decision per case type,
  which is row 11.6 and buildiq's `page-layout-per-case-type`.
- **11.13 translation management in the UI.** Register content is
  translatable through openregister `register-i18n`. UI strings stay in l10n by
  decision C05. Nothing for dossiq to build.
- **Q10.12 effort against something other than a case.** humaniq's timesheet
  books it. dossiq places the leaf on a case and nowhere else, because a case
  is the only host object dossiq owns.
- **9.11 `task-search-fields (inside remove-casetask)`.** `remove-casetask` is
  another lane's open change with 3 tasks left. This lane opens
  `task-search-fields` as its own change that depends on it rather than editing
  it.
