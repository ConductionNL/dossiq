# Design: Procest IA Topology

## Goal

Lock in the left-nav topology that the implemented specs are expected to map
into, then identify the single drift (`task-management`) that needs relocation.

## Target left-nav (post-change)

```
Procest
+-- Dashboard                       (TOP_MENU; route /)
|     widgets:
|       count-open-cases, count-overdue, count-completed,
|       count-my-tasks, count-sla,
|       cases-by-status, cases-by-type, my-work, case-map,
|       deadline-alerts, task-due-reminders, stalled-cases
|
+-- Mijn werk                       (TOP_MENU; route /my-work)
|     children:
|       +- Taken                    (SUB_PAGE; route /tasks)   <-- moved here
|
+-- Werkvoorraad                    (TOP_MENU; route /werkvoorraad)
|
+-- Zaken                           (TOP_MENU group; route /cases is "Alle zaken")
|     children:
|       +- Alle zaken               (SUB_PAGE; route /cases)
|       +- Bezwaren                 (SUB_PAGE; route /bezwaren)
|       +- Beslissingen op bezwaar  (SUB_PAGE; route /bezwaar-decisions)
|       +- Beroepen                 (SUB_PAGE; route /beroepen)
|
+-- Kaart                           (TOP_MENU; route /map)
+-- Voorstellen                     (TOP_MENU; route /voorstellen)
+-- Advice                          (TOP_MENU; route /advice)
+-- BAC-adviezen                    (TOP_MENU; route /bezwaar-advice-requests)
+-- Transfers                       (TOP_MENU; route /transfers)
|
+-- (settings drawer; "Configuratie")
      +-- Documentation              (href)
      +-- Zaaktypes                  (CaseTypesMenu, /case-types)
      +-- Legesverordeningen
      +-- Legesberekeningen
      +-- Partner organisations
      +-- Tenants                    (admin)
      +-- Parafeerroutes
      +-- Kaartlagen                 (admin)
      +-- Workflow definitions
      +-- Automatische acties
      +-- Status history             (admin)
      +-- Handhavingsstrategie       (admin)
      +-- LHS Recommendations
      +-- Case locations             (admin)
      +-- Bezwaaradviescommissies
      +-- Settings                   (AdminRootView)
      +-- Features & roadmap
```

Note: the proposed IA only requires the `task-management` move. The Zaken-group
nesting and other tidy-ups (Bezwaren/Beroepen folded under a Zaken group) are
out of scope for this change — none of the 14 audited specs requires it. They
will be addressed when those specs (`bezwaar-lifecycle`, `beroep-escalation`,
etc.) come up for IA review.

## Rationale for the one move

Per the IA heuristics: **IA says SUB_PAGE under parent X, but spec lives as a
sibling route to X → DRIFT.** `task-management` IA places it under "Mijn werk
› Taken" but it's currently at top-level `Tasks` (manifest menu order 50),
sibling to `MyWork` (order 20). Moving it makes the only journey that lands on
the global task list ("what's on my plate") consistent with the My Work
framing.

## Constraints

- The procest manifest's `menu[]` is flat (each entry has `id`, `label`,
  `route`, optional `section`, `permission`, `order`). It does not natively
  support nested children. Two implementation options:
  1. **Remove** the `Tasks` menu entry; surface the global task list from
     inside `MyWork.vue` as a tab / explicit link to `/tasks`. The route stays
     for deep-link compatibility.
  2. **Group visually** via a manifest-level convention (sub-menu on hover,
     prefixed labels). Not currently supported by `@conduction/nextcloud-vue`.
- This change adopts option (1): demote in `menu[]`, keep the page entry.

## Out of scope

- Bezwaren / Beroepen / BezwaarDecisions consolidation under a Zaken group.
- Settings drawer reorganisation (the IA shows `Configuratie › Admin ›
  Observability/Storage` sub-grouping that the current flat drawer doesn't
  express).
- Backend / API / schema work.
