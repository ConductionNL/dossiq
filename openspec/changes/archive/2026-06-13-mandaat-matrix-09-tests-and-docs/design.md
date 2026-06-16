# Design — Member 09: Tests, @spec Tags, and Documentation (code)

## Scope

Verification + annotation + documentation over the completed chain. No new feature code; this
member is "pure code, no declarative surface" (ADR-032) — test files, docblocks, and markdown docs.

## Tests (ADR-008)

- `tests/Unit/Service/MandaatCheckServiceTest.php` — isAuthorized with: role holds mandate
  (authorized), role lacks mandate (niet_bevoegd), plafond exceeded (plafond_overschreden),
  subdelegatie blocked (subdelegatie_niet_toegestaan), waarnemer authority, temporal version
  selection, multiple role holders.
- `tests/Integration/EscalatieWorkflowTest.php` — plafond overshoot → escalation created → approved
  → decision executes + MandaatGebruik logged; rejection → not executed, status unchanged;
  personnel change → reroute; waarnemer period boundary behaviour.
- `tests/Integration/CaseDecisionAuthorizationTest.php` — decision with mandate requirement:
  authorized user proceeds + logs, unauthorized blocked + escalates, waarnemer authorized with flag,
  approval path.

## @spec tags + compliance (ADR-003, ADR-009)

- File-level `@spec` docblock on each new service class; method-level `@spec` tags linking to the
  relevant member's spec (e.g. `@spec openspec/changes/mandaat-matrix-02-authorization-engine/specs/mandaat-matrix/spec.md`).
- Architectural-compliance review: no custom mappers, ObjectService for CRUD (ADR-001), routes only
  via appinfo/routes.php (ADR-016), SPDX headers (EUPL-1.2 in the docblock).
- Update project CLAUDE.md with mandate-matrix context (architecture, decision flow, escalation).

## Documentation (ADR-009)

`docs/user/mandate-matrix-admin.md` — decidesk import walkthrough, role-hierarchy setup, waarnemer
assignment for absence coverage, troubleshooting (missing role, validation errors), sample Excel
template, FAQ. nl + en where the docs convention requires.

## Security (ADR-005)

Tests assert the security-relevant behaviours: denied decisions do not execute, the unauthorized
approver is rejected, MandaatGebruik is immutable (update → 403). These tests are the regression
guard for the authorization contract established across members 02–06.
