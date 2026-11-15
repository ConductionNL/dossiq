# Tasks: widget-roles-declared

Tier: V1. Kind: code. Size S. Round 4 discovery cluster 12 and gap
register row 10.1, the dossiq consumer half of launchpad
`dashboards-and-who-may-see-them` (ConductionNL/launchpad#637). Candidate
C-reporting-22. Decision D6 admits it on relevance. launchpad owns the
dashboard and the plane; dossiq declares and enforces on its own reads.

- [x] 1.1 The managerial dashboard widgets declare `roles` in
  `src/manifest.json` (D-1): every widget on Doorlooptijd, the process
  mining dashboard and the termijn dashboard, plus the SLA compliance tile
  on the main dashboard. Each carries a `_rolesNote` saying why, so the
  audience can be argued with rather than only obeyed. The key rides on the
  widget DEFINITION and never on a placement: a widget placed twice must
  not be able to carry two audiences that disagree.
- [x] 1.2 `tests/Unit/Architecture/WidgetDeclaresRolesTest.php`: a dashboard
  widget declares roles or is allowlisted WITH a reason, an allowlisted
  widget that has since declared roles fails so the list shrinks, an entry
  naming a widget that is gone fails so the list cannot rot, and a declared
  widget with no `_rolesNote` fails (D-4).
- [x] 2.1 `lib/Service/Dashboard/WidgetRoles.php` and
  `DashboardWidgetScope.php`, called from `KpiController::index()`: the
  fields a reader's hidden widgets would show are removed from the payload
  before it leaves, on the computed path AND the cached one. Removed rather
  than zeroed: a zero is a figure (D-2, ADR-004).
  - `tests/Unit/Controller/WidgetDataRoleCheckTest.php`
  - `tests/Unit/Service/Dashboard/WidgetRoleResolutionTest.php`
- [x] 2.2 The page lays out without the widget because the widget has
  nothing to render, not because the browser hid it. Asserted on the
  manifest side (`tests/vitest/widgetRoleLayout.spec.js`) and on the
  payload side in the e2e spec; the renderer that drops a tile whose data
  is absent is nextcloud-vue's, and no placeholder is declared anywhere
  here.
- [x] 3.1 A role no group answers to hides its widget from EVERYONE and is
  reported to the log with the widget id (D-3, ADR-102). Hiding it only
  from the people who happen not to hold the group would turn a rename into
  a disclosure.
- [x] 3.2 No new user-facing strings: the declaration is configuration and
  the enforcement removes a tile rather than explaining itself on screen,
  which is the whole point of having no placeholder.
- [x] 3.3 `tests/e2e/widget-roles-declared.spec.ts`: the SLA figure absent
  from the PAYLOAD for a case handler, the rest of the page intact with no
  placeholder, a managerial dashboard answering nothing to the same reader,
  and a renamed role hiding the widget from everyone;
  `openspec validate widget-roles-declared --strict`.

## The scope, argued rather than assumed

**Dashboard pages only.** A widget on a DETAIL page renders the record the
reader has already been granted, and its access question is that record's
own grants (`case-grants-name-their-source`), not a role on a tile.
Declaring roles on seventy detail panels would put seventy entries on an
allowlist to say one thing.

**Undeclared is still public, and the test is what keeps that temporary.**
Failing an undeclared widget closed at runtime would empty every dashboard
on the fleet the hour this shipped. The structural test is the mechanism:
the next widget cannot be added without the question being asked.

**The role vocabulary is Nextcloud groups**, one spelling
(`dossiq-teamleider`), asserted as a SET in the vitest so a second spelling
has to be a deliberate edit. A typo in a role name is not a smaller version
of the right rule: it hides the widget from everyone and reads as a widget
somebody deleted.
