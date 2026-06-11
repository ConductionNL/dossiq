# Tasks — Member 09: Tests, @spec Tags, and Documentation (code)

> **Build status (hydra audit).** Mostly greenfield. Dev has only lib/Service/MandaatValidationService.php::validate() (191 lines, single-method) + lib/Controller/MandaatController.php (124 lines). The MandateringsBesluit/Mandaat/OrganisatieRol/MedewerkerRolToewijzing schemas, full authorization+escalation engines, Decidesk import, case+decision integration, temporal+conflict resolver, and admin/user UI are not on dev. Tasks stay [ ] as genuine forward work; the existing slim MandaatValidationService is the foundation to grow on.

Sourced from giant tasks 14–18 (Unit/Integration tests; @spec + docs; Admin docs).

## 1. Unit Tests

- [ ] `MandaatCheckServiceTest`: authorized (role holds), niet_bevoegd, plafond_overschreden, subdelegatie_niet_toegestaan, waarnemer, temporal version, multiple holders
- [ ] All unit assertions pass

## 2. Integration Tests

- [ ] `EscalatieWorkflowTest`: plafond overshoot → escalation; approval → decision + MandaatGebruik logged; rejection → unchanged; personnel change → reroute; waarnemer period boundary
- [ ] `CaseDecisionAuthorizationTest`: authorized → succeeds + logged; unauthorized → blocked + escalation; waarnemer → authorized with flag; approval path
- [ ] All integration assertions pass

## 3. @spec Tags + Compliance

- [ ] Add file-level @spec docblock to each new service class
- [ ] Add method-level @spec tags linking to the relevant member spec
- [ ] Architectural-compliance review (ObjectService CRUD, no custom mappers, routes via appinfo/routes.php, SPDX headers)
- [ ] Run linter/code-style checks
- [ ] Update project CLAUDE.md with mandate-matrix context

## 4. Admin Documentation

- [x] `docs/user/mandate-matrix-admin.md`: import workflow, role hierarchy, waarnemer assignment, troubleshooting, sample Excel template, FAQ
