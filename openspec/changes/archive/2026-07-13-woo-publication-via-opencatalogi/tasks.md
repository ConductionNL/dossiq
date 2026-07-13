# Tasks: woo-publication-via-opencatalogi

## 1. Backend — category mapping and payload building
- [x] 1.1 `lib/Service/WooPublication/WooCategoryMapper.php` — `forDecision(array $decision): array` lookup table, defaults to `infocat014`.
- [x] 1.2 `lib/Service/WooPublicationService.php` — `selectDisclosableDocuments(array $assessments, array $documents): array` (redacted-only enforcement, D4).
- [x] 1.3 `WooPublicationService::buildPayload()` — assembles title/summary/category/decisionDate/caseReference from decision + case + disclosable documents.

## 2. Backend — OpenCatalogi HTTP client
- [x] 2.1 `lib/Service/WooPublication/OpenCatalogiApiClient.php` — `createPublication()`, `updatePublication()`, `withdrawPublication()`, `attachDocument()`, `attachFile()`, `resolveCatalog()` against OpenRegister's confirmed Objects API.
- [x] 2.2 `SettingsService::getWooPublicationConfigValue()` + `WOO_PUBLICATION_DEFAULTS` (mirrors `getKccConfigValue()`).

## 3. Backend — service orchestration
- [x] 3.1 `WooPublicationService::checkAvailability()` (D5: app-absent / OR-absent / no-publishable-documents).
- [x] 3.2 `WooPublicationService::publish(string $caseId, string $decisionId): array` — create-or-update, single `saveObject()` write-back (D6).
- [x] 3.3 `WooPublicationService::withdraw(string $decisionId): array`.

## 4. Backend — controller + routes
- [x] 4.1 `WOOAssessmentController::publishDecision()` / `withdrawPublication()`.
- [x] 4.2 `appinfo/routes.php` — `POST /api/cases/{id}/woo/publish`, `POST /api/cases/{id}/woo/withdraw`.

## 5. Frontend
- [x] 5.1 `src/services/wooPublicationApi.js` — `publishWooDecision(caseId)`, `withdrawWooPublication(caseId)`.
- [x] 5.2 `src/views/cases/components/WooPublicationPanel.vue` — publish action + status/link (mirrors `BesluitPublicatiePanel.vue`).
- [x] 5.3 Wire `WooPublicationPanel` into `DocumentAssessmentPanel.vue`.
- [x] 5.4 i18n keys (en+nl) for all new user-visible strings.

## 6. Tests
- [x] 6.1 `tests/Unit/Service/WooPublication/WooCategoryMapperTest.php`.
- [x] 6.2 `tests/Unit/Service/WooPublicationServiceTest.php` (redacted-only matrix, availability, publish/withdraw single-save).
- [x] 6.3 `tests/Unit/Service/WooPublication/OpenCatalogiApiClientTest.php` (request shape, mocked `IClientService`).
- [x] 6.4 Extend `tests/Unit/Controller/WOOAssessmentControllerTest.php` for the two new endpoints.
- [x] 6.5 Full PHPUnit suite green (CI-equivalent php:8.3-cli container).
- [x] 6.6 `npm run build` exit 0; vitest green.

## 7. Validation
- [x] 7.1 `openspec validate woo-publication-via-opencatalogi --type change --strict`.
- [x] 7.2 Grep for `<<<<<<<` conflict markers before commit.
