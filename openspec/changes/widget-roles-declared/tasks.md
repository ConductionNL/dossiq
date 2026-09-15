# Tasks: widget-roles-declared

Tier: V1. Kind: code. Size S. Round 4 discovery cluster 12 and gap
register row 10.1, the dossiq consumer half of launchpad
`dashboards-and-who-may-see-them` (ConductionNL/launchpad#637). Candidate
C-reporting-22. Decision D6 admits it on relevance. launchpad owns the
dashboard and the plane; dossiq declares and enforces on its own reads.

- [ ] 1.1 Add a roles declaration to every widget in `src/manifest.json`
  (D-1).
  - `@spec openspec/changes/widget-roles-declared/specs/dashboard/spec.md`
- [ ] 1.2 `tests/unit/Architecture/WidgetDeclaresRolesTest.php`: fail on a
  widget with no roles and no reason-bearing allowlist entry, and on an
  allowlisted widget that gained one (D-4).
- [ ] 2.1 Check the declared roles on every widget data read, answering
  nothing to a reader who holds none, per ADR-004 (D-2).
  - `tests/unit/Controller/WidgetDataRoleCheckTest.php`
- [ ] 2.2 Lay the page out without a widget the reader may not see, with
  no placeholder (D-2).
  - `tests/vitest/widgetRoleLayout.spec.js`
- [ ] 3.1 Do not render a widget whose declared role cannot be resolved,
  and report it to an administrator, per ADR-102 (D-3).
  - `tests/unit/Service/WidgetRoleResolutionTest.php`
- [ ] 3.2 Dutch and English strings.
- [ ] 3.3 `tests/e2e/widget-roles-declared.spec.ts`: a financial tile
  absent for a case handler with none of its data in the payload, the rest
  of the page intact, no placeholder, and a renamed role hiding the widget
  for everyone; `openspec validate widget-roles-declared --strict`.
