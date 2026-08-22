# Resume plan — page-topology-cleanup

> Written 2026-08-22 so the remaining work survives a context compaction.
> **23 of 44 tasks done.** Blocks A, B, C1 and E1 are complete and in review;
> C2 and D1–D4 were deferred on the flow-engine consolidation, which has now
> landed, so they are unblocked.

## Where things stand

| Item | State |
|---|---|
| **A** — three dashboards converted | done · on `chore/page-topology-cleanup-specs` |
| **B** — administration surface | done · same branch |
| **C1** — verwerkingen retired | done · same branch |
| **E1** — AI oversight → hermiq | done · hermiq#514 + #517 **merged**; dossiq#1328 **merged** into the branch |
| **C2** — automatic actions → OR flows | **NOW UNBLOCKED** |
| **D1–D4** — besluitvorming/committees/parafeerroutes → decidiq | **NOW UNBLOCKED**, but D1 still waits on `consume-decidesk-besluitvorming-leaf` |

### Open PR

- `ConductionNL/dossiq#1323` — blok A/B/C1/E1 together, base `development`.
  #1328 has been merged into its branch, so the gate-16/gate-60 fixes are in.
  **This is the only thing between the finished work and `development`.**

### Merged already

`hermiq#514` (advisory Approval + event + `/ai-oversight`), `hermiq#517`
(description key), `hydra#600` (PLATFORM-POLICY GitHub-first),
`.github#546` + `#549` (gate 94 + its acceptance fixture).

---

## C2 — automatic actions become OpenRegister flow nodes

### Measured, not assumed

procest ships **six** action handlers in `lib/Service/Actions/`, each declaring
its own `type()` slug:

| Handler | type() |
|---|---|
| `CallWebhookHandler` | `callWebhook` |
| `CreateDocumentHandler` | `createDocument` |
| `MergeTemplateHandler` | `mergeTemplate` |
| `NotifyRoleHandler` | `notifyRole` |
| `ScheduleReminderHandler` | `scheduleReminder` |
| `SendEmailHandler` | `sendEmail` |

OpenRegister's consolidated engine registers **nineteen** node ids:
`await-signal, batch, end, explode, filter, flow-state, iterate, map, merge,
object-read, object-write, route, set-fields, sub-flow, switch, trigger-manual,
trigger-object, trigger-schedule, wait`.

🔑 **Every one of those is control-flow or data. Not one of them does anything
outward-facing** — no email, no webhook, no document, no notification. So C2 is
NOT "map six handlers onto existing nodes". All six are side-effecting actions
that OR deliberately does not own, and mapping them would mean inventing
behaviour OR does not have.

### The seam already exists — no OpenRegister change needed

`FlowNodeRegistry`'s docblock says the point is that *"apps present nodes through
OpenRegister"*, and hermiq already does it:
`lib/Flow/HermiqFlowNodeListener.php` listens for
`OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent` and calls
`$event->registerNode(...)` for `HermiqAgentNode`, `HermiqWorkloadNode` and
`HermiqWorkloadCollectNode`.

So C2 is **procest contributing six nodes the same way** — which means, like C1,
it needs no OpenRegister-side work and the two-PR rule does not apply. The
handlers keep their logic; they gain a node wrapper and lose their private
registry.

### 🔴 The dependency that makes this bigger than two pages

`automaticAction` is **not standalone**. `caseType.workflowSteps` embeds
references in three places — `automaticActions: ActionRef[]`,
`config.autoActions: ActionRef[]`, and `config.escalationRule` — and there are
**9 such references in the shipped seed/templates alone**, before any customer
data. Retiring the pages does not retire the concept: every case type in the
field points at these by id.

### Steps

1. **Wrap each handler as an `IFlowNode`** (`lib/Flow/Procest*Node.php`), keeping
   the handler classes as the implementation. Register them through a
   `ProcestFlowNodeListener` on `RegisterFlowNodesEvent`, copying hermiq's shape.
2. **Decide the reference story for `workflowSteps`** — this is the real design
   question, and it should be settled before any code:
   - either `ActionRef` starts resolving to a flow node id (rewrite on migration), or
   - the step executor calls OR's flow runner with the node inline.
   Whichever wins, existing case types must keep working across the upgrade.
3. **Migrate** the `automaticAction` objects to flow definitions + rewrite the
   `workflowSteps` references. Repair step: idempotent, non-fatal, and it must
   read required keys **without** a `??` fallback (see lesson 2).
4. **Retire** `/settings/automatic-actions` + `/:id`, the menu entry, and
   `ActionRegistry`/`ActionHandlerLocator` once the nodes are the only path.
   Keep the six handler classes.
5. **Deeplink** to `/apps/openregister/#/flows` — hash-routed. C1 hit this trap.

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
