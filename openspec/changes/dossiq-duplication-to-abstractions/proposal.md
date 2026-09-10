# Dossiq's duplicated concepts move onto OpenRegister's abstractions

## Why

Dossiq declares 81 schemas. **Thirty-three of them, carrying 282 properties,
belong to eight clusters that OpenRegister already ships an entity or a leaf
for.**

This is not a suspicion. Every counterpart below was checked by opening the
file in the `openregister` checkout on 2026-09-10 and **comparing its columns
to the dossiq schema's properties**, not by matching a name:

| Dossiq cluster | Schemas | OpenRegister counterpart | Verified |
|---|---|---|---|
| task | 1 | `Task` | `lib/Db/Task.php`, 936 lines |
| tenancy | 7 | `Organisation` | `lib/Db/Organisation.php`, 1128 lines |
| documents | 7 | `File` + `files` leaf | `lib/Db/File.php` |
| inspection checklists | 7 | task forms + `field-inspection` leaf | `lib/Service/Task/TaskForm*.php` |
| advice requests | 6 | `Task` + task forms | `lib/Db/Task.php` |
| workflow / case plan | 2 | `Flow` + `CaseItem` | `lib/Db/CaseItem.php`, 638 lines |
| federation | 2 | `FederatedShare` | `lib/Db/FederatedShare.php`, 307 lines |
| case location | 1 | `MapLink` | `lib/Db/MapLink.php`, 181 lines |

## Six schemas that a name match would have caught and a field match rejects

The first pass of this audit claimed eleven clusters and 42 schemas. Comparing
columns rather than concepts removed six of them, and the reason is worth
keeping, because it is the mistake this programme is most likely to repeat.

| Schema | Looked like | Actually is | Verdict |
|---|---|---|---|
| `abonnement` | `NotificationSubscription` | A **ZGW Notificaties API** subscription: `callbackUrl`, `auth`, `kanalen`. OpenRegister's entity is `userId`+`registerId`+`schemaId`, an in-app subscription. | Stays |
| `notificationChannel` | `NotificationSubscription` | The ZGW *kanaal* register that pairs with it. | Stays |
| `usageRights` | `DataAccessProfile` | ZGW `gebruiksrechten`: a document's usage conditions and dates. Not access control at all. | Moves, but into the **documents** cluster |
| `aiAuditEntry` | `AuditTrail` | An **AI interaction** log: `prompt`, `model`, `confidence`, `suggestion`, `userAction`. It records suggestions a user REJECTED, which changed no object, so `AuditTrail` has nowhere to put them. | Stays |
| `mapLayer` | `MapLink` | Basemap configuration: `url`, `layers`, `srs`, `opacity`, `minZoom`. `MapLink` is a pin on a map (`lat`, `lng`, `objectUuid`). | Stays |
| `wmsLayer` | `MapLink` | Same, for WMS/WFS. | Stays |
| `caseShare` | `FederatedShare` | A password-protected public link with `failedAttempts` and `lockedUntil`. Federation is `caseFederatedShare`. | Stays |

`case-location` survives the maps row: it carries a real location and is a
genuine `MapLink`. The two layer schemas are configuration and are not.

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
checklists (7 schemas). Thirteen schemas retired against one abstraction, and
the largest single win in the programme.

**Wave 3, the independent clusters.** Tenancy, documents, workflow,
federation, case location. Each stands alone and can be worked in parallel or
dropped without affecting the others.

Five of these already have a dossiq change that stalled. This proposal does not
replace them; it sequences them and states the common shape they all share.

## The blocked-on-nextcloud-vue problem

Four of the six existing changes carry tasks marked `[blocked: nextcloud-vue …]`.
That is the same pattern as the clusters themselves: a gap below every app,
worked around locally N times. Those library gaps are collected in
`design.md` and lifted as one nextcloud-vue change rather than four local
workarounds.

## Success is measured, not asserted

The programme is done when `lib/Settings/dossiq_register.json` declares **48
schemas rather than 81**, and every removed slug returns zero hits from a
case-insensitive `git grep` across the whole repo. Any other number is
progress, not completion, and the tasks file counts it per cluster.

48, not 41: the first pass of this audit over-claimed by six schemas, and the
corrected target is stated here rather than quietly adjusted later.
