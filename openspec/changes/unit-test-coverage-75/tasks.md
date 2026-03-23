## 1. Test Infrastructure Setup

- [ ] 1.1 Ensure tests/bootstrap.php supports OC::$server mocking for unit tests that need container access

## 2. Service Unit Tests - Simple Services

- [ ] 2.1 Create ZgwMappingServiceTest (getMapping, saveMapping, listMappings, deleteMapping)
- [ ] 2.2 Create ZgwPaginationHelperTest (wrapResults with various page/count combinations)
- [ ] 2.3 Create ZgwDocumentServiceTest (storeBase64, storeRaw, getContent, fileExists, deleteFiles)

## 3. Service Unit Tests - Business Rules

- [ ] 3.1 Create ZgwBrcRulesServiceTest (BRC rules: besluit create validation, uniqueness, immutability)
- [ ] 3.2 Create ZgwDrcRulesServiceTest (DRC rules: document create validation, lock checks)
- [ ] 3.3 Create ZgwZtcRulesServiceTest (ZTC rules: concept protection, afleidingswijze validation)
- [ ] 3.4 Create ZgwBusinessRulesServiceTest (dispatcher: delegates to correct register rules service)

## 4. Service Unit Tests - Complex Services

- [ ] 4.1 Create NotificatieServiceTest (publish, deliver failure logging)
- [ ] 4.2 Create ZgwServiceTest (RESOURCE_MAP structure, constructor dependencies)

## 5. Controller Unit Tests - Simple Controllers

- [ ] 5.1 Create DashboardControllerTest (page returns TemplateResponse)
- [ ] 5.2 Create HealthControllerTest (health check returns JSONResponse with status)
- [ ] 5.3 Create MetricsControllerTest (index returns TextPlainResponse with Prometheus header)
- [ ] 5.4 Create SettingsControllerTest (getSettings, updateSettings delegation)
- [ ] 5.5 Create ZgwMappingControllerTest (index, show, update mapping operations)

## 6. Controller Unit Tests - ZGW Register Controllers

- [ ] 6.1 Create ZrcControllerTest (index, create, show, update, destroy delegation to ZgwService)
- [ ] 6.2 Create ZtcControllerTest (index, create, show, update, destroy plus publish action)
- [ ] 6.3 Create DrcControllerTest (index, create, show, update, destroy plus download action)
- [ ] 6.4 Create BrcControllerTest (index, create, show, update, destroy delegation)
- [ ] 6.5 Create NrcControllerTest (index, create, show delegation)
- [ ] 6.6 Create AcControllerTest (index, create, show, update, destroy delegation)

## 7. Newman API Tests

- [ ] 7.1 Create tests/newman/zgw-workflow.postman_collection.json with ZGW CRUD flow
- [ ] 7.2 Create tests/newman/procest-environment.json with local dev variables

## 8. Verification

- [ ] 8.1 Run full PHPUnit suite and verify all tests pass
- [ ] 8.2 Verify file coverage count is 33+ of 42 (75%+)
