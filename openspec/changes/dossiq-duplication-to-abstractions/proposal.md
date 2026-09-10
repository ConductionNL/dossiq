# Dossiq's duplicated concepts move onto OpenRegister's abstractions

## Why

Dossiq declares 81 schemas. **Forty of them, carrying 349 properties, belong to
eleven clusters that OpenRegister already ships an entity or a leaf for.**

This is not a suspicion. Every counterpart below was checked by opening the
file in the `openregister` checkout on 2026-09-10:

| Dossiq cluster | Schemas | OpenRegister counterpart | Verified |
|---|---|---|---|
| task | 1 | `Task` | `lib/Db/Task.php`, 936 lines |
| tenancy | 7 | `Organisation` | `lib/Db/Organisation.php`, 1128 lines |
| sharing / federation | 4 | `FederatedShare` | `lib/Db/FederatedShare.php`, 307 lines |
| inspection checklists | 7 | task forms + `field-inspection` leaf | `lib/Service/Task/TaskForm*.php` |
| advice requests | 6 | `Task` + task forms | `lib/Db/Task.php` |
| documents | 6 | `File` + `files` leaf | `lib/Db/File.php` |
| maps | 3 | `MapLink` + `maps` leaf | `lib/Db/MapLink.php`, 181 lines |
| workflow / case plan | 2 | `Flow` + `CaseItem` | `lib/Db/CaseItem.php`, 638 lines |
| audit | 1 | `AuditTrail` | `lib/Db/AuditTrail.php`, 648 lines |
| notifications | 2 | `NotificationSubscription` | `lib/Db/NotificationSubscription.php`, 101 lines |
| rights | 1 | `DataAccessProfile` | `lib/Db/DataAccessProfile.php`, 161 lines |

OpenRegister's own inventory already names dossiq as the offender in the
largest of these. From `openregister/openspec/changes/flow-task-entity/proposal.md:40`:

> Meanwhile the fleet has built the same task 23 times. The inventory ran
> 2026-08-22: 23 task shapes across procest, pipelinq, planix, decidesk...

and at line 61 it cites `procest/lib/Service/Transitions/CreateTaskHandler.php:76`
by name, for writing a task status that is out of its own enum.

## The cost is not tidiness

Three failures, each already observed in this codebase or its siblings.

**A parallel store drifts, and the drift is invisible.** Tenancy is the worst
case and has its own change: a scoping regression there returns another
tenant's rows, formatted correctly, with HTTP 200. Nothing throws.

**A duplicated concept duplicates its bugs and fixes neither.** The fleet
memory calls this "a defect below every app produces local workarounds and no
global fix". Dossiq's `caseTask` has no form, so `DossiqAskPersonNode` can ask
a person a question and record only *that* they answered, never *what* they
said. OpenRegister's `Task` has carried `responses` and `templateSnapshot` for
weeks.

**Dossiq also duplicates itself.** The clusters are not only app-versus-platform:

- `inspectieChecklist` and `inspectionChecklistTemplate` share four of five
  fields. One is Dutch, one is English, both are checklist templates, and there
  is a third (`inspectionChecklist`).
- `adviceRequest`, `adviesAanvraag` and `bacAdviceRequest` are three spellings
  of "ask a body for advice". With `adviceResponse`, `advisoryBody` and
  `advisoryReport` that is six schemas and 58 properties for one concept that
  is a task with a form and a due date.

Collapsing a cluster onto the shared abstraction collapses the internal
duplication for free. That is most of the win in the advice and inspection
rows, and it is why those two are sequenced early despite having no plan today.

## What is NOT in scope

**A schema that encodes Dutch administrative law stays in dossiq.** `bezwaar`,
`beroep`, `handhavingsactie`, `objectionProceeding`, `hearingSession`,
`complaint` and their kin are absent from the table above and stay absent. The
test applied throughout was narrow and mechanical: *does OpenRegister already
ship an entity for this exact concept, and can the file be pointed at.*

**Nothing is deleted before its replacement carries live data.** Every cluster
below lands as read-from-new / write-to-both, then write-to-new, then removal,
in separate PRs. A cluster that cannot complete step one does not proceed.

## How this is sequenced

Eleven clusters, three waves. The ordering is by *what unblocks what*, not by
size.

**Wave 1, the task spine.** `caseTask` onto `Task`. Everything in wave 2
depends on it, because advice requests and inspection runs both become tasks
with forms. Six OpenRegister changes build the target and five of them are
finished; `flow-task-forms` is 17/20 with three UI tasks open, and those three
are in `nextcloud-vue`, not here.

**Wave 2, the clusters that become tasks.** Advice (6 schemas) and inspection
checklists (7 schemas). Thirteen schemas retired against one abstraction.

**Wave 3, the independent clusters.** Tenancy, documents, sharing, maps, audit,
notifications, rights, workflow. Each stands alone and can be worked in
parallel or dropped without affecting the others.

Six of these already have a dossiq change that stalled. This proposal does not
replace them; it sequences them and states the common shape they all share.

## The blocked-on-nextcloud-vue problem

Four of the six existing changes carry tasks marked `[blocked: nextcloud-vue …]`.
That is the same pattern as the clusters themselves: a gap below every app,
worked around locally N times. Those library gaps are collected in
`design.md` and lifted as one nextcloud-vue change rather than four local
workarounds.

## Success is measured, not asserted

The programme is done when `lib/Settings/dossiq_register.json` declares 41
schemas rather than 81, and every removed slug returns zero hits from
`git grep` across `lib/ src/ tests/`. Any other number is progress, not
completion, and the tasks file counts it per cluster.
