# Tasks

- [x] 1.1 Views on Cases: my-work, team-queue, unclaimed-due-this-week, data-subject-requests
- [x] 1.2 Complaints view per instance (case-type-views.json, ManifestController)
- [x] 1.3 Urgency and Newest on the Cases sort select; my-work names `sortOption: urgency`
- [x] 1.4 Redirects for /my-work, /queue and /privacy/requests; pages, menu entries and unused components removed
- [x] 1.5 Specs updated; ManifestControllerTest covers the complaints view
- [ ] 1.6 A view selects a sort option (nextcloud-vue): until then Assigned to me opens on the Cases default order
- [ ] 1.7 Per-view slots (nextcloud-vue): WorkloadSummaryBar belonged on the team queue and Assigned to me only
- [x] 1.8 e2e specs for /my-work and /queue (pages.spec.ts, my-work.spec.ts, configurable-queue-urgency.spec.ts, handler-vervanging-waarneming.spec.ts) to the views; tests that read restored overrides are fixme behind `VIEW_OVERRIDES_APPLIED`
- [x] 1.9 Repair step PinnedPagesAsViews: pins of Queue and MyWork become their views in `cn_saved_view_menu`, once per user
- [x] 1.10 restore.md: per view, the config the overrides must restore
- [ ] 1.11 Apply restore.md once nextcloud-vue view `overrides` land; flip `VIEW_OVERRIDES_APPLIED`

## What a user loses

- Team queue: the three counts above the list, the chip per case type, the Team today and per-type side cards, the waited column and the count badge in the menu.
- Assigned to me: the card layout with the urgency pill and the workload strip; the urgency order until views can pick a sort option.
- Data subject requests: the AVG-verzoeken / Datalekken tabs, the notice, the breadcrumb, the Kind and Term columns and the Inzage / Correctie / Verwijdering chips.
- The default pinned menu entries Queue and Assigned to me (a user's own pins of them now become views, PinnedPagesAsViews).
- Assigned to me: the substituted work group (Work you are standing in for).
