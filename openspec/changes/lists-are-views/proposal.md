# Lists that were pages are views of the case list

## Why

Ruben, 19 October 2026: dossiq's case lists are one list with views, not a
page per list. Woo requests went first; Assigned to me, the team queue, the
data subject requests and the complaints follow.

## What changes

- The Cases page ships the views `my-work` (Assigned to me), `team-queue`,
  `unclaimed-due-this-week` (the team queue's this-week window; its late window
  is `unclaimed-overdue`) and `data-subject-requests`.
- Complaints is a view per instance: the complaint case type has no fixed id,
  so `lib/Settings/case-type-views.json` names it by identifier and the
  `/api/manifest` delta adds the view where the instance has that case type.
- The Cases sort select offers Urgency (the server's ranking from
  GET /api/work-queue, nextcloud-vue index-sort-source) and Newest; `my-work`
  names `sortOption: urgency`.
- /my-work, /queue and /privacy/requests redirect to
  `/cases?savedView=default:<slug>`. The MyWork, Queue and PrivacyRequests
  pages and their menu entries go; MyWorkCards, MyWorkCaseCard,
  WorkloadSummaryBar and workQueueHelpers go with them.

## Depends on

- nextcloud-vue #1792 (views), batch 17 (`sortSelectOptions[].source`).
- A view selecting a sort option (`sortOption`): a view's `sort` is field keys
  today and cannot pick an endpoint-sourced option.
- The delta merging `defaultSavedViews` by slug (case-type-pins-are-views).

## Users lose

See tasks.md, section "What a user loses".
