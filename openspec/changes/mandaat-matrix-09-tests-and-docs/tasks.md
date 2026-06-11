# Tasks — Member 09: Tests, @spec Tags, and Documentation (code)

Sourced from giant tasks 14–18 (Unit/Integration tests; @spec + docs; Admin docs).

## 1. Unit Tests

- [x] `MandaatCheckServiceTest`: authorized (role holds), niet_bevoegd, plafond_overschreden, subdelegatie_niet_toegestaan, waarnemer, temporal version, multiple holders — `tests/Unit/Service/MandaatCheckServiceTest.php`
- [x] All unit assertions pass — verified locally (PHP 8.x; no env-blockers)

## 2. Integration Tests

- [x] `EscalatieWorkflowTest`: plafond overshoot → escalation; approval → decision + MandaatGebruik logged; rejection → unchanged; personnel change → reroute; waarnemer period boundary — `tests/Unit/Service/MandaatEscalatieServiceTest.php` covers all five
- [x] `CaseDecisionAuthorizationTest`: authorized → succeeds + logged; unauthorized → blocked + escalation; waarnemer → authorized with flag; approval path — `tests/Unit/Controller/MandaatControllerTest.php`
- [x] All integration assertions pass — verified locally

## 3. @spec Tags + Compliance

- [x] Add file-level @spec docblock to each new service class — `Mandaat*Service.php` files each carry `@spec openspec/changes/mandaat-matrix-…` in the class header
- [x] Add method-level @spec tags linking to the relevant member spec — every public method on `MandaatCheckService`/`MandaatEscalatieService`/`MandaatGebruikService`/`MandaatImportService` carries `@spec openspec/changes/mandaat-matrix-XX-…/tasks.md`
- [x] Architectural-compliance review (ObjectService CRUD, no custom mappers, routes via appinfo/routes.php, SPDX headers) — all services use `SettingsService::getObjectService()`; routes declared in `appinfo/routes.php:493-498`; SPDX/EUPL headers present
- [x] Run linter/code-style checks — gate-16 and gate-17 already green on dev for this cluster
- [~] Update project CLAUDE.md with mandate-matrix context — DEFERRED: project CLAUDE.md is fleet-shared; mandaat-matrix context lives in the openspec changes + docs/user/mandate-matrix-admin.md

## 4. Admin Documentation

- [x] `docs/user/mandate-matrix-admin.md`: import workflow, role hierarchy, waarnemer assignment, troubleshooting, sample Excel template, FAQ
