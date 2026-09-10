## Context

See `proposal.md` for the motivation. Three facts about the current code shape the approach.

**dossiq already has this pattern.** `ImmutabilityListenerRegistrar` registers two pre-persist
listeners on OpenRegister's `ObjectUpdatingEvent` and `ObjectDeletingEvent`, one for
`bewijsstuk` and one for `inspectionChecklistRun`. Its own docblock records why the post-persist
pair is unusable: OpenRegister dispatches `ObjectUpdatedEvent` after the row is written, with no
surrounding transaction, so a listener there cannot stop what it objects to. Both listeners have
unit tests. This change adds a third to a working mechanism rather than inventing one.

**OpenRegister's `readOnly` cannot express this rule.** `ValidateObject` enforces
`readOnly: true` on the update path, unconditionally after create. A beschikking's `rationale`
must be editable while it is a draft and frozen once it is signed. A flag that cannot read
`currentStatus` cannot say that.

**The audit trail already holds the served version.** `AuditTrailMapper` writes per-property
`old`/`new` diffs sealed into a SHA-256 hash chain, and `revertObject(identifier, until)`
reconstructs a point-in-time state. This change is not about recovering evidence, which is
already possible. It is about not needing to.

## Goals / Non-Goals

**Goals:**

- Make `REQ-BES-008` true on every write path, not only on `PATCH /api/beschikkingen/{id}`.
- Make the beschikking lifecycle reachable, so the freeze protects something.
- Write the successor contract down while the reasoning is fresh.

**Non-Goals:**

- Building the correction flow. It needs a product decision, recorded below.
- Adding `supersedes` or `supersededBy` to the schema. A property nothing writes reads like a
  shipped feature from the outside, and it is how a matrix ends up rating a dead field `yes`.
- Touching `BesluitMaterialisationService`. It writes the ZGW projection of a decidiq outcome,
  not the instrument served to the citizen. Re-mirroring a source in place is what a projection
  is for. Its own defect is separate and noted under Risks.
- Changing `case`, which is archival and already refuses user-driven deletes.

## Decisions

**Enforce at the persistence boundary, not in the controller.** Under ADR-022 the frontend
writes objects through OpenRegister's generic API, so a controller guard is a guard on a door
nobody uses. The alternative, forcing every beschikking write through `BeschikkingController`,
would mean removing the object API's reach into one schema, which OpenRegister has no mechanism
for and which would break the case detail view. The pre-persist listener guards the store
itself, so the route no longer matters.

**The stored state decides, never the payload.** The listener reads `currentStatus` from
`getOldObject()`, exactly as `BewijsstukImmutabilityListener` does. A payload that claims
`currentStatus: draft` on a row that is `sent` must be refused, and it is, because the incoming
value is never consulted.

**One `assertMutable()`, two callers.** The immutable field list lives today in
`BeschikkingService::CONTENT_FIELDS`, private, reachable only from `updateFields()`. It moves
to a public `assertMutable(array $stored, array $updates)` that both the PATCH route and the
listener call. Two copies of an authorization list drift, and the drift is silent.

**Wire the four schema keys in the same change as the listener, never before it.** Turning the
subsystem on first would give users a writable signed beschikking for however long the listener
took to land. The ordering is the safety property, so both go in one commit.

**Do not add the successor properties yet.** See Open Questions. The spec fixes the invariants
that are decided: the original is untouched, the successor carries its own number, the chain
reads both ways, and a superseded beschikking cannot be superseded twice.

## Risks / Trade-offs

**A live instance has signed beschikkingen written through the object API** → Not possible.
`beschikking_schema` has never been configured, so no instance holds a beschikking row. There is
nothing to migrate and nothing to grandfather.

**Turning the subsystem on exposes untested lifecycle code** → The routes, the state machine and
the repository all have unit tests; what they lack is a configured schema. Wiring the key is the
smallest step that makes those tests describe something real. The e2e surface stays out of scope.

**The listener refuses a legitimate process event** → The allowed set is the complement of the
content fields, so `dispatch`, `bezwaarTrigger` links and `archive` stay writable. The
"Process events stay allowed after signing" scenario is the mutation check on that.

**`BesluitMaterialisationService` writes properties the `decision` schema does not declare**
→ Out of scope, and worth its own change. It writes `zaakRef`, `result`, `date`, `notes`,
`mandaathouder`, `besluitMethode` and `besluitBron`, none of which appear in the `decision`
schema in `dossiq_register.json`. It also reads a `besluit_schema` key that is not in
`CONFIG_KEYS`, so it always falls back to the slug `decision`.

## Open Questions

These block the successor implementation, not this change. Each needs a product answer.

**How is a correction numbered?** Two shapes are on the table. Frappe derives the successor from
the predecessor, `ORD-0001` becomes `ORD-0001-1` then `-2`, which makes the lineage readable in
the number itself. The alternative is the next number in the case's own sequence,
`Z/2026/04832/B01` followed by `B02`, which matches how a gemeente numbers its outgoing
decisions today. The first is better evidence, the second is what a handler will recognise on
paper. This has to be answered before the successor can be built, and it should be answered by
someone who has seen a real bezwaardossier.

**Must the original be withdrawn before it is corrected?** Frappe refuses an amendment unless
the predecessor is cancelled. Dutch practice does not work that way: a wijzigingsbeschikking
modifies a besluit that stays in force, and an intrekkingsbeschikking withdraws it. So the
Frappe rule does not transfer, but the question of what "in force" means for a corrected
beschikking still needs an answer, because the bezwaartermijn on the correction is a new one.

**What does the case show?** A list of all instruments in issue order is the honest rendering
and the one the spec asks for. Whether the replaced ones collapse behind a "show earlier
versions" control, or stay expanded, is a design call with a real cost either way: collapsing
hides evidence, expanding buries the decision in force.
