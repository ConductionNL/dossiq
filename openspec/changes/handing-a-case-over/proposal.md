---
kind: code
depends_on: []
---

# Proposal: handing-a-case-over

Round 4 discovery, cluster 36 "Handing a case to another team or handler"
(`procest/_round4/discovery/build-plan.md` in
ConductionNL/market-intelligence, 2026-09-14). Four candidates, three of
them `must`, two of them matrix holes, seven passers, six driven, proving
system OTOBO. Owner dossiq, size M, depends on roles, grants and their
provenance. Statutory: Awb 2:3, the doorzendplicht.

The register is blunt about why it carried nothing
(`procest/_gaps/gap-register.json`, `discovery.counts.uncarried_reason`,
cluster 36): "no change opened; the plan's own decision line reads `none`,
dossiq's wave 1 opens nine changes and this is not among them, and neither
of the later waves the umbrella names reaches it".

## Why

A case moves between teams all day. Vergunningen sends it to Toezicht, the
KCC sends it to Burgerzaken, a bezwaar reaches Juridische Zaken. Today
that is a change of `assignee`, which loses two things the law and the
work both need: who is answerable for the case, and the note saying why it
moved.

## What is actually there

Read against `development` at `172d364f`. The lane's `no` on
C-case-core-44 needs correcting, and the correction makes the gap
sharper, not softer.

- `lib/Service/CaseTransferService.php` is a complete transfer mechanism:
  `initiateTransfer()`, `acceptTransfer()`, `rejectTransfer()`, an
  idempotency key, a federation share and a custody audit trail. The
  `casetransfer` schema carries `caseId`, `sourceOrganization`,
  `targetOrganization`, `reason`, `status`, `rejectionReason`,
  `completedAt` and `custodyAuditTrail`.
- Every one of those fields is about another **organisation**. The
  mechanism is federated: `remoteCloudId`, `federationShareId`,
  `resolveFederatedTransferShare()`. There is no internal counterpart.
- `case` carries one `assignee` and one `assignedGroup`. There is no second
  named seat. `roleType` has a `coordinator` value and no case field binds
  it.
- A grep for a transfer of ownership when a person leaves returns nothing,
  as the lane found.

So dossiq can hand a case to another gemeente and cannot hand it to the
team next door, and the build plan names exactly that fix: "extend dossiq
`lib/Service/CaseTransferService.php` to internal teams".

## The candidates

| candidate | relevance | dossiq | what it asks |
|---|---|---|---|
| C-case-core-44 | must, matrix hole | partial | the whole case is handed to another team, keeping its identity and its history, as a first-class act with its own note |
| C-case-core-46 | must, matrix hole | partial | two named people sit on the case at once, the one doing it and the one answerable for it |
| C-case-core-34 | must | partial | one central case application holds both the generic cases and the cases handled inside task-specific applications |
| C-access-and-privacy-56 | should | no | everything a person owns is moved to somebody else in one act when they leave |

Evidence verbatim from the lane
(`_round4/discovery/candidates.json`):

- C-case-core-44, `case-core.tsv:12`: "otobo: Ticket menu, Move
  (AgentTicketMove.pm)". Its clause: "Awb 2:3 doorzendplicht is a transfer
  with an obligation to tell the sender". The lane's note: "Queue transfer
  under two names. Four systems between them. matrix hole". Three driven
  passers: FreeScout, OTOBO and Request Tracker.
- C-case-core-46, `case-core.tsv:10`: "otobo: Ticket menu, Owner beside
  Responsible (AgentTicketOwner.pm, AgentTicketResponsible.pm,
  ticket.user_id and ticket.responsible_user_id)". Its clause: "behandelaar
  and casemanager are different people and the Awb answer is signed by the
  second". The lane's note: "A coordinator beside the handler and an owner
  beside the responsible are the same second seat. matrix hole".
- C-case-core-34, `case-core.tsv:29`: "pinkroccade-izaaksuite: documented,
  /proces-services/zaakgericht-werken/". Its clause: "a gemeente runs
  burgerzaken, sociaal domein and belastingen in separate products, and
  'the zaak lives centrally while the work happens elsewhere' is a whole
  architecture our matrix has no row for".
- C-access-and-privacy-56, `access-and-privacy.tsv:72`: "nextcloud-deck:
  board#transferOwner /boards/{id}/transferOwner, occ
  deck:transfer-ownership". Its clause: "uitdiensttreding is a Tuesday
  afternoon and today it is a database query". The lane marks it
  `dossiq-only 13.44`.

**D6 was answered relevance-led**, so all three `must` candidates enter.
C-case-core-34 carries documented passers only and enters on that rule.
**D17** does not reach this cluster.

## What changes

- A case is handed to another team inside the organisation as a
  first-class act: the case keeps its number, its history and its terms,
  the handover carries a reason, and the receiving team can refuse it back
  with a reason.
- The handover is the same record shape as the federated transfer, so one
  question answers both: where has this case been.
- Where the handover is a doorzending under Awb 2:3, the applicant is told
  the case moved and to whom.
- A case carries two named seats: the handler doing the work and the
  coordinator answerable for it. Both are assignable, both are searchable,
  and the coordinator seat is what a case type requires before a besluit
  is signed where it says so.
- A case declares that it is homed in another application, with the
  identifier there and the link to it, so one list answers for the
  generic cases and the specialist ones together.
- Everything one person holds, cases as handler, cases as coordinator,
  open tasks and drafts, is handed to somebody else in one recorded act
  when they leave.

## Ownership

dossiq owns the internal handover, the second seat, the externally homed
declaration and the leaver handover. They are dossiq's schemas and
dossiq's service.

What dossiq consumes:

| half | app | artefact |
|---|---|---|
| the grants a handover moves, and where they came from | openregister | `permission-provenance-and-deny` and `rbac-inherits-to-children`, both existing; dossiq's consumer half is `case-grants-name-their-source`, wave 1, open |
| the department and role matrix the receiving team is named from | openregister | `rbac-department-role-matrix`, the register's artefact for rows 13.6 and 13.16 |
| the connector set that makes a case externally homed | integriq | `zgw-connectors-for-dossiq`, the register's artefact for row 6.14 |
| the facade a specialist application reads the case through | openregister | `objecten-api-facade`, the register's artefact for row 12.3 |
| the signal that a person has left | humaniq | none yet, see below |

### Needs a change in humaniq

The register names humaniq `leave-management` for row 13.17, consumed by
dossiq's open `substituted-work-reaches-my-work`. Leave is not
uitdiensttreding: a person on leave comes back and their work is covered,
a person who has left does not and their work is handed over. No humaniq
slug in the register's `changes_by_repo` carries an offboarding signal. A
follow-up lane should open one. Until it exists, the leaver handover is
performed by an administrator naming the person, and it listens for the
signal the day humaniq emits one.

## ADRs

- Company ADR-050: the error envelope is `{message, error}`. A refused
  handover names the rule in `error`.
- Company ADR-102: config absence fails closed with a status. A handover to
  a team that cannot be resolved refuses rather than leaving the case
  unowned.
- Company ADR-011: search OpenRegister before implementing a utility. The
  internal handover extends `CaseTransferService` rather than adding a
  second transfer mechanism beside it.

## Capabilities

- Modified: `case-management`: a case is handed to another team as a
  recorded act, and a case may be homed in another application.
- Modified: `people-on-the-case`: a case carries a handler and a
  coordinator, and everything a leaver holds moves in one act.

## Impact

`lib/Service/CaseTransferService.php`, `lib/Service/Transfer/`, the
`case` and `casetransfer` schemas, `reassignment-bulk-action`, the case
page's people panel, Dutch and English strings.

## Where this change disagrees with the register

- **C-case-core-44 is rated `partial` on the wrong grounds.** The lane's
  notes read "no, nearest `lib/Controller/RoutingController.php` routes at
  intake only" and "partial, reassign changes the assignee, not the owning
  team". Both miss `CaseTransferService`, which does the whole act with a
  reason, an acceptance, a rejection and a custody trail, for another
  organisation. The gap is that it stops at the organisation boundary, so
  this is an extension of a working mechanism rather than a new one.

## Out of scope

- Federated transfer between organisations. Shipped, and this change
  reuses its record shape.
- Bulk reassignment over many cases. dossiq `reassignment-bulk-action`,
  and the progress half is `bulk-actions-report-progress`, wave 1.
- Who may do what, and where a grant came from. dossiq
  `case-grants-name-their-source`, wave 1.
