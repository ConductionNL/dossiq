# Resume plan — page-topology-cleanup

> Written 2026-08-22 so the remaining work survives a context compaction.
> **23 of 44 tasks done.** Blocks A, B, C1 and E1 are complete and in review;
> C2 and D1–D4 were deferred on the flow-engine consolidation, which has now
> landed, so they are unblocked.

## Where things stand

| Item | State |
|---|---|
| **A** — three dashboards converted | done · in dossiq#1323 / #1328 |
| **B** — administration surface | done · in dossiq#1323 |
| **C1** — verwerkingen retired | done · in dossiq#1323 |
| **E1** — AI oversight → hermiq | done · hermiq#514 + #517 **merged**; procest half in dossiq#1328 |
| **C2** — automatic actions → OR flows | **NOW UNBLOCKED** |
| **D1–D4** — besluitvorming/committees/parafeerroutes → decidiq | **NOW UNBLOCKED**, but D1 still waits on `consume-decidesk-besluitvorming-leaf` |

### Open PRs

- `ConductionNL/dossiq#1323` — blok A/B/C1, base `development`
- `ConductionNL/dossiq#1328` — E1 + all gate fixes, base `chore/page-topology-cleanup-specs`

The stack is deliberate: **#1328 merges into #1323's branch, then #1323 into
`development`.** #1323 alone still fails gate-16 and gate-60 — those fixes live
on #1328. Do not merge #1323 first.

### Merged already

`hermiq#514` (advisory Approval + event + `/ai-oversight`), `hermiq#517`
(description key), `hydra#600` (PLATFORM-POLICY GitHub-first),
`.github#546` + `#549` (gate 94 + its acceptance fixture).

---

## C2 — automatic actions become OpenRegister flows

### What is actually there

procest's `automaticAction` schema: `slug, type, tenantId, title, description,
config, version, isPublished, active`. Six handlers in
`lib/Service/Actions/`: CallWebhook, CreateDocument, MergeTemplate, NotifyRole,
ScheduleReminder, SendEmail. Two pages: `/settings/automatic-actions` and
`/:id`.

OR's consolidated engine is in `lib/Service/Flow/`: `FlowEngine`,
`FlowNodeRegistry`, ~20 node types (TriggerObject, TriggerSchedule, Router,
Map, SetFields, ObjectRead, AwaitSignal, Iterate, Explode, Merge, End, …), and
routes `/api/flows` (CRUD + run), `/api/flow/node-catalog`,
`/api/flow/event-catalog`, `/api/flow/validate`.

`FlowNodeRegistry`'s own docblock says the point is that *"apps present nodes
through OpenRegister"* — so the shape is **procest contributes nodes**, not
procest keeps an engine.

### 🔴 The dependency that makes this bigger than two pages

`automaticAction` is **not a standalone object**. `caseType.workflowSteps` embeds
references to them in three places:

- `automaticActions: ActionRef[]` on each step
- `config.autoActions: ActionRef[]` fired before transition-level actions
- `config.escalationRule` (notifyRole / escalateToRole / openIncident)

Retiring the pages therefore does **not** retire the concept — every case type
in the field references these by id. Any migration has to rewrite those
references or keep them resolvable. **Establish this before writing the spec.**

### Steps

1. **Inventory** every `automaticAction` in the seed data and in
   `caseType.workflowSteps`, and map each of the six handler types onto an OR
   node (or name the gap). `NotifyRole` and `ScheduleReminder` are the two most
   likely to need a new node.
2. **Land the gaps in OpenRegister** as contributed nodes — a procest-owned node
   registered through `FlowNodeRegistry`, not a second engine. Merge first.
3. **Migrate** `automaticAction` objects to flow definitions, and rewrite the
   `workflowSteps` references. Repair step, idempotent, non-fatal.
4. **Retire** in procest: both pages, the menu entry, `lib/Service/Actions/`,
   and the `automaticAction` schema once nothing references it.
5. Deeplink to OR's flows surface. ⚠️ **OpenRegister is hash-routed** —
   `/apps/openregister/#/flows`, not `/apps/openregister/flows`. C1 hit this
   exact trap and landed on OR's dashboard until corrected.

---

## D1–D4 — decision-making to decidiq

**D1 is still gated on `consume-decidesk-besluitvorming-leaf`** (an active change
in this repo). That change retires the Besluitvorming *nav group* and surfaces
decidesk's decisions as a case-detail leaf, but deliberately keeps
`/besluitvorming/agenda` and `/besluitvorming/vergaderingen/:id` routable. D1
removes those two outright, so it must land after — not in parallel.

- **D1** — retire `/besluitvorming/agenda`, `/besluitvorming/vergaderingen/:id`,
  `AgendaCompilerView.vue`, `VergaderingDetailView.vue`,
  `src/manifest.d/50-besluitvorming.json`. decidiq's `/agenda-items` and
  `/meetings` are the owner.
- **D2** — `bezwaaradviescommissie` → decidiq's `governance-body`.
- **D3** — `parafeerroute` → decidiq's routed-document/approval model
  (`routedDocumentsJoin.js`).
- **D4** — assert the case leaf is **render-and-read only** (ADR-066): no verb,
  no command. Anything procest needs decidiq to *do* travels as a typed event
  (ADR-041) — the shape `ContractDecisionDelegationService` and E1's
  `AiOversightDelegationService` both use.

Each of D1–D3 is two PRs: land in decidiq, merge, *then* retire here.

---

## Lessons this programme paid for — apply them to C2/D

1. **`git add -A` re-adds files a previous commit deleted** if they are still on
   disk. Four component deletions silently came back that way and only gate-26
   noticed. Stage deletions explicitly, and verify with
   `git cat-file -e <branch>:<path>`.
2. **A default-valued read turns missing data into confident wrong behaviour.**
   The E1 repair step read `$batch['results']` where the API returns `entries`;
   `?? []` made it report "nothing to migrate" on a full instance. phpstan caught
   it. Read required keys **without** a fallback.
3. **A manifest key that validates can still render nothing.** `config.subtitle`
   passed Ajv and was never read — `CnDashboardPage`'s prop is `description`.
   Verify a declaration renders, in a browser, before believing it.
4. **The icon gate knows better than the ADR table.** Two rows look applicable;
   run `check_icon_vocabulary.py` rather than reading ADR-077 and guessing. An
   unregistered icon renders as *nothing*, not a fallback.
5. **Cross-app writes go through a typed event**, never into the other app's
   register. decidesk's `DecisionRequestedEvent` and hermiq's
   `AiOversightRecordedEvent` are the two working precedents.
6. **The e2e suite refuses `localhost:8080` on purpose** — it seeds *and deletes*
   OpenRegister objects and that is the shared dev container. CI provisions its
   own instance. Verify pages individually in the browser instead.
7. **A cancelled duplicate CI run reports as a failure** in the PR rollup. Check
   `gh run list --json headSha,conclusion` before believing a red check.

## Local tooling notes

- procest's `vendor/` is root-owned; run phpcs/phpmd/phpstan/phpunit **inside the
  container**: `docker exec -w /var/www/html/custom_apps/procest nextcloud sh -lc '...'`.
- procest's vendor lacks `conduction/hydra-gates/quality-config`; it was copied
  in from hermiq's vendor inside the container to make the tools runnable.
- The real gate checkers are cloned at `~/dotgithub-gates/hydra-gates/scripts/lib/`
  — run them directly against a repo path, e.g.
  `python3 ~/dotgithub-gates/hydra-gates/scripts/lib/check_spec_coverage.py .`
- Build needs headroom: `NODE_OPTIONS="--max-old-space-size=6144"` (the default
  heap OOM-kills webpack on this box).
