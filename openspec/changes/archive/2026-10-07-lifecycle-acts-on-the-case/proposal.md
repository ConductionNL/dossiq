---
kind: code
depends_on: []
---

# Proposal: lifecycle-acts-on-the-case

Round 4 discovery, cluster 24 "Lifecycle acts as separate, permissioned
acts" (`procest/_round4/discovery/build-plan.md` in
ConductionNL/market-intelligence, 2026-09-14). Ten candidates, three of
them `must`, eight passers, all eight driven, proving system Dimpact ZAC.
Owner dossiq, size M, depends on nothing. The register's reason for the
cluster carrying nothing is one line: "no change opened".

## Why

Ending a case is four different acts with four different consequences. A
vergunning that is granted, an aanvraag that is withdrawn, a bezwaar
declared niet-ontvankelijk and a case reopened after a beroep are not one
verb with different text. They differ in what is archived, what the
citizen is told, and who is allowed to do it.

dossiq has the verbs and has no place that shows them together, so a
handler finds out what they may do by trying.

## What is actually there

Read against `development` at `172d364f`. The lane's ratings need
correcting in two places, in opposite directions.

**C-case-core-31 is further along than "dossiq renders no lifecycle
action at all on a case".** `CaseDetail` in `src/manifest.json` carries
Suspend, Resume, Extend term and Reopen as `open-modal` header actions
onto `CaseLifecycleActionDialog`, which reads `/lifecycle` before it
posts, plus Copy case, Start and Plan follow-up. `CaseLifecycleService.php`
answers `canSuspend`, `canResume`, `canExtend` and `canReopen` at lines
114 to 117, and `lib/Lifecycle/CaseActionProvider.php` publishes the
transition set that the `case-stages` widget reads through OpenRegister's
`/available-actions`.

So the gap is not absence. It is that the acts are spread over a header
bar and a stages widget, each gated differently, and that the page's own
note records the rest: `lifecycleActions` was removed because it rendered
nothing, no field can gate Resume because suspension is derived from the
journal rather than a flag, and the transition POST is refused by
OpenRegister today because its provider mode is read-only
(openregister#3679).

**C-case-core-21 is substantially shipped, and the first read of it was
wrong.** A grep for `hiddenInLists` finds it only in
`src/views/settings/components/StatusTypeForm.vue:98`,
`src/utils/statusTypeForm.js:67` and the demo seeds, which reads like a
control nobody honours. It is not. `case.statusHiddenInLists` is a
calculated mirror of it, computed by OpenRegister from the linked status
type (`lib/Settings/dossiq_register.json:1697`), and the Cases index
filters `statusHiddenInLists: false` on its All lens
(`src/manifest.json:1180`), asserted by
`tests/vitest/caseTypeAuthoringManifest.spec.js:154`.

So the flag works, under a second name, on one lens of one page. What is
left is narrow and worth stating exactly: the Overdue and Due this week
chips carry no such filter, and the manifest's own note says so ("It
carries no `statusHiddenInLists` of its own, matching its sibling Overdue
rather than All"); the dashboard tiles, the open counts, the Queue page
and My Work do not filter on it either. A status marked hidden therefore
empties one list and leaves the same cases in the counts beside it.

The first reading of this candidate is recorded because the mistake is
the useful part: the flag's reader carries a different name from the
flag, so a search for the declared name answered about something adjacent
and said dark. That is why REQ-LIFE-02 below traces calculations rather
than matching names.

## The candidates

| candidate | relevance | dossiq | what it asks |
|---|---|---|---|
| C-case-core-21 | must | no | a status is marked not visible by default, so cases in it drop out of the working list |
| C-case-core-31 | must | no | every lifecycle action on the case is reached from one menu |
| C-case-core-40 | must, matrix hole | no | the OTRS action set on one case: hold, follow, merge, split, hand over, bounce, forward, park and log a call |
| C-case-core-29 | should | partial | closing is more than one act: finish, abort, archive and reopen are separate |
| C-case-core-7 | should | no | a case is closed early, before its phases are complete, recording the result |
| C-case-core-35 | should | no | required fields are left empty knowingly, and the case says it is incomplete |
| C-case-core-41 | should | no | the process owns the status, so no handler sets it by hand |
| C-case-core-42 | should | no | the product closes a case itself after an administered period of silence |
| C-intake-2 | should | no | a case is begun, kept private to its author, and promoted in one action |
| C-case-core-5 | could | partial | a case closes in one click with the outcome preset |

Evidence verbatim from the lane
(`_round4/discovery/candidates.json`):

- C-case-core-21, `cross-area.tsv:4`: "valtimo: Case definition Statussen
  (CaseDefinition-Statussen.md)". Its clause: "it is how a closed case
  disappears without anybody writing a filter, and it is one flag".
- C-case-core-31, `case-core.tsv:36`: "xxllnc-zaken: Case page, ZAAKACTIES
  (Case-Zaakacties.md)".
- C-case-core-40, `case-core.tsv:48`: "znuny: Ticket menu, Lock, Watch,
  Merge, Split, Move, Bounce, Forward, Mark as unseen, Pending, Phone in
  and out, view raw (AgentTicketLock.pm, AgentTicketWatcher.pm,
  AgentTicketMerge.pm, AgentSplitSelection.pm, AgentTicketMove.pm,
  AgentTicketBounce.pm, AgentTicketForward.pm, AgentTicketMarkSeenUnseen.pm,
  AgentTicketPending.pm, AgentTicketPhoneInbound.pm,
  AgentTicketPhoneOutbound.pm, AgentTicketPlain.pm)". The lane's note: "A
  bundle rather than a row. Several of its verbs are separate candidates
  here; it is listed so the bundle is not lost. matrix hole".
- C-case-core-29, `case-core.tsv:11`: "dimpact-zac: Case detail
  (docs/user-manual-features.md)". The lane's note: "Both refuse to
  collapse ending a case into one verb".
- C-case-core-7, `case-core.tsv:20`: "xxllnc-zaken: Case page, ZAAKACTIES
  (Case-Zaakacties.md)". Its clause: "an intrekking or a
  niet-ontvankelijkverklaring arrives mid-process".
- C-case-core-35, `cross-area.tsv:8`: "xxllnc-zaken: Case page, phase
  strip (case-detail-anatomy.md)". The lane's note: "One sentence at the
  start of the case, one at the end of a phase. One refusal to lie about
  the data".
- C-case-core-41, `case-core.tsv:34`: "valtimo: Case definition Statussen
  (CaseDefinition-Statussen.md)".
- C-case-core-42, `case-core.tsv:7`: "plane: Project settings
  Automations, project.py:110-111 archive_in and close_in,
  bgtasks/issue_automation_task.py:22 nightly".
- C-intake-2, `intake.tsv:11`: "plane: Workspace sidebar Drafts,
  workspaces/<slug>/draft-issues/, Issue.is_draft at
  db/models/issue.py:161, draft-to-issue/<draft_id>". Its clause: "a
  concept-zaak whose Awb clock has not started is a real object in a
  gemeente".
- C-case-core-5, `case-core.tsv:21`: "otobo: Ticket menu, Quick Close
  (AgentTicketQuickClose.pm)".

**D6 was answered relevance-led**, so all three `must` candidates enter;
two of the three carry a single driven passer. **D17** does not reach this
cluster.

## What changes

- One menu on the case holds every lifecycle act, gated by what the
  handler may do and what the case allows, and each act says why it is
  unavailable rather than being absent.
- Finish, abort, archive and reopen are four acts, each with its own
  permission, its own recorded reason and its own archival consequence.
- A case is closed before its phases are complete, recording the result
  and which phases were skipped.
- A close with a preset outcome is one act, and the reason is still
  recorded.
- `statusType.hiddenInLists`, through its calculated mirror
  `case.statusHiddenInLists`, is honoured everywhere work is counted or
  listed, not only on the Cases All lens: the other chips, the Queue page,
  My Work, the open counts and the dashboard tiles.
- A case type declares whether its status is owned by the process. Where
  it is, no hand-set status is accepted.
- A case type declares an auto-close period of silence, and the product
  closes with a recorded reason after it, never silently.
- Required fields are left empty knowingly: the case records that it is
  incomplete, names which fields, and does not block the intake.
- A case is begun as a draft, private to its author, with no term bound,
  and promoted in one act.
- Hold and park are acts on the case with a reason and a wake date.

## Ownership

dossiq owns the acts, the menu, the incompleteness record, the draft and
the hidden status. The action set of C-case-core-40 is deliberately split,
because most of its verbs are already somebody's:

| verb | where it is built |
|---|---|
| hold, park, pending | here |
| follow, watch | dossiq `case-followers`, open, consuming openregister `object-watchers` |
| unread, mark as unseen | dossiq `unread-state-on-the-case`, wave 1, consuming openregister `object-read-state` |
| merge | dossiq `case-merge`, open, consuming openregister `mdm-merge` |
| hand over, move | dossiq `handing-a-case-over`, this wave |
| bounce, forward | dossiq `inbound-mail-filters` REQ-IMF-03, this wave, shipped as a spec |
| split | openregister, the relation half, register row 2.26 `relation-types-with-inverses` |
| log a call | pipelinq, register row 6.2 `contact-moments-on-pipelinq-schema` |
| view raw | dossiq `inbound-mail-filters` REQ-IMF-08, the intake log holds the original |

What dossiq consumes here:

- **openregister**, the lifecycle engine. `CaseActionProvider` already
  publishes the acts through `/available-actions`. The write half is
  refused: `applyTransition()` has no provider branch and
  `LifecycleActionProviderInterface` declares no `execute()`, so a POST of
  an act the provider itself published comes back "Transition <action> is
  not declared on this schema". **openregister#3679**. Every requirement
  here that moves a case through the engine waits on it.
- **openregister** `lifecycle-declarative-conditions`, named by the
  register in the wave 1 list, for the conditions a gate is declared in.

### Needs a change in openregister

openregister#3679 is an issue and not a change. The provider write half,
an `execute()` on `LifecycleActionProviderInterface` and a provider branch
in `applyTransition()`, has no slug in the register's `changes_by_repo`. A
follow-up lane should open it in openregister; until it does, the stages
widget draws acts it cannot perform.

## ADRs

- Company ADR-050: the error envelope is `{message, error}`. An act
  refused by a guard names the rule in `error`.
- Company ADR-102: config absence fails closed with a status. A case type
  that declares process-owned status and cannot resolve its process
  refuses the hand-set rather than allowing it.
- Company ADR-060: a test that cannot fail is phantom green. The dark
  `hiddenInLists` flag is exactly that shape, and the structural test in
  the tasks is how the next dark flag is caught.
- dossiq `openspec/specs/case-status-machinery/spec.md` is what this
  extends.

## Capabilities

- Modified: `case-status-machinery`: a hidden status leaves the working
  list, the process may own the status, and silence closes a case.
- Modified: `case-management`: one menu holds every act, ending is four
  acts, a case closes early, incompleteness is recorded, a draft is
  private, and hold and park exist.

## Impact

`lib/Lifecycle/CaseActionProvider.php`,
`lib/Service/CaseLifecycleService.php`, `lib/Service/Transitions/`,
`src/manifest.json` (`CaseDetail` header actions and the `case-stages`
widget), `src/components/` (the lifecycle dialog), the `case`,
`caseType` and `statusType` schemas, Dutch and English strings.

## Where this change disagrees with the register

- **C-case-core-31 is rated `no` and dossiq has most of the verbs.** The
  gap is one menu and one gate, not the acts. Sized accordingly.
- **C-case-core-21 is rated `no` and most of it ships.** The flag is
  honoured on the Cases All lens through the calculated
  `case.statusHiddenInLists`. The remaining gap is the other lenses, the
  Queue page, My Work, the counts and the tiles, which is a handful of
  filters rather than a mechanism. Sized accordingly.

## Out of scope

- Merge, split, follow, unread, log a call and hand over. Named above,
  each with its own change.
- The recycle window and the destroying role. dossiq `case-recycle-window`,
  wave 1.
- Bulk acts over many cases. dossiq `bulk-actions-report-progress`, wave 1.
