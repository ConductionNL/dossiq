# Tasks

- [x] 1.1 Views on Cases: my-work, team-queue, unclaimed-due-this-week, data-subject-requests
- [x] 1.2 Complaints view per instance (case-type-views.json, ManifestController)
- [x] 1.3 Urgency and Newest on the Cases sort select; my-work names `sortOption: urgency`
- [x] 1.4 Redirects for /my-work, /queue and /privacy/requests; pages, menu entries and unused components removed
- [x] 1.5 Specs updated; ManifestControllerTest covers the complaints view
- [x] 1.6 A view selects a sort option (nextcloud-vue saved-view-overrides, library batch 18): `my-work` overrides `sortOption: urgency`
- [x] 1.7 Per-view slots: WorkloadSummaryBar and SubstitutedWork are the `my-work` view's slots, registered components
- [x] 1.8 e2e specs for /my-work and /queue (pages.spec.ts, my-work.spec.ts, configurable-queue-urgency.spec.ts, handler-vervanging-waarneming.spec.ts) to the views; tests that read restored overrides are fixme behind `VIEW_OVERRIDES_APPLIED`
- [x] 1.9 Pins: the `Queue` and `MyWork` menu entries keep their ids and open their views, so pins and the default pins keep working (the PinnedPagesAsViews repair this replaced is gone)
- [x] 1.10 restore.md: per view, the config the overrides must restore
- [x] 1.11 restore.md applied as view `overrides`; the team queue keeps its `Queue` menu entry with the badge; `VIEW_OVERRIDES_APPLIED` is true

## What a user loses (before restore.md was applied)

- Team queue: the three counts above the list, the chip per case type, the Team today and per-type side cards, the waited column and the count badge in the menu.
- Assigned to me: the card layout with the urgency pill and the workload strip; the urgency order until views can pick a sort option.
- Data subject requests: the AVG-verzoeken / Datalekken tabs, the notice, the breadcrumb, the Kind and Term columns and the Inzage / Correctie / Verwijdering chips.
- The default pinned menu entries Queue and Assigned to me (both back, opening their views).
- Assigned to me: the substituted work group (Work you are standing in for).
- [x] 1.12 Base filters as `overrides.lockedFilters`; Assigned to me card description, chips and overdue tone (schema 2.132.0)
- [x] 1.13 Subsidies: the page and its menu entry go; default view `subsidies`, /subsidies redirects (Ruben, 20 October 2026)
- [x] 1.14 Own-record entries are settings-only: BezwaarCommitteesMenu declares `section: settings`; the full profile lifts CaseObjectsMenu into settings as the simple one does
