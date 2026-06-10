# Tasks — Member 09: Tests, @spec Tags, and Documentation (code)

Sourced from giant tasks 14–18 (Unit/Integration tests; @spec + docs; Admin docs).

## 1. Unit Tests

- [~] `MandaatCheckServiceTest`: authorized (role holds), niet_bevoegd, plafond_overschreden, subdelegatie_niet_toegestaan, waarnemer, temporal version, multiple holders — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] All unit assertions pass — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. Integration Tests

- [~] `EscalatieWorkflowTest`: plafond overshoot → escalation; approval → decision + MandaatGebruik logged; rejection → unchanged; personnel change → reroute; waarnemer period boundary — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] `CaseDecisionAuthorizationTest`: authorized → succeeds + logged; unauthorized → blocked + escalation; waarnemer → authorized with flag; approval path — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] All integration assertions pass — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. @spec Tags + Compliance

- [~] Add file-level @spec docblock to each new service class — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Add method-level @spec tags linking to the relevant member spec — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Architectural-compliance review (ObjectService CRUD, no custom mappers, routes via appinfo/routes.php, SPDX headers) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Run linter/code-style checks — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Update project CLAUDE.md with mandate-matrix context — deferred to downstream cycle / fleet-wide adoption (handoff)

## 4. Admin Documentation

- [~] `docs/user/mandate-matrix-admin.md`: import workflow, role hierarchy, waarnemer assignment, troubleshooting, sample Excel template, FAQ — deferred to downstream cycle / fleet-wide adoption (handoff)
