---
kind: code
depends_on: [woo-request-takes-over-from-opencatalogi]
---

# Proposal: woo-request-scoped-access

Woo capability programme, round 1, wave 2. Row 12.28, dossiq's half.

| row | text | our rating today |
| --- | --- | --- |
| 12.28 | A reviewer edits only their own redactions and comments, and can be barred from seeing another reviewer's | no |

## Summary

Only the people assigned to a Woo request read its document assessments, a reviewer edits only the assessments and redaction proposals they made, and an organisation can hide one reviewer's verdicts from another; dossiq sets this with OpenRegister's private scope and per-object grants.

- Rows: 12.28 (dossiq's half; OpenRegister's half is the redaction decision on entity relations).
- Wave 2.
- Dependencies: `openregister/reviewer-owns-their-decisions` (https://github.com/ConductionNL/openregister/issues/4394) for the redaction half only; `dossiq/woo-request-takes-over-from-opencatalogi` (https://github.com/ConductionNL/dossiq/issues/3289). Coordinates with `dossiq/woo-review-triage` (https://github.com/ConductionNL/dossiq/issues/3294), whose batch reviewers it grants. Uses OpenRegister's object-level sharing and private scope, already on openregister `development`.
- Decisions: D1 (dossiq owns the Woo request, so dossiq sets the grant on its assessment record). The plan's owning change, `opencatalogi/woo-request-scoped-access`, was never written and D1 moved the request; this change takes its name in dossiq.
- Build rules: openspec/woo-build-rules.md

## Why

Row 12.28 is `no` in our column. The evidence, from the programme's baseline: OpenRegister's
`EntityRelationMapper::updateDecisionMetadata()` records the acting user, but any caller who may
write a relation's subject may change any occurrence; and the Woo assessment record grants read,
create, update and delete to everyone who reaches its schema, so every reviewer edits every other
reviewer's assessment and nothing bars one from seeing another's.

OpenRegister's `reviewer-owns-their-decisions` (openregister spec PR #4378, issue #4394) closes the
entity relation half and says, in its REQ-ROD-003, that the assessment half is "an authorization
block the owning app ships". After D1 the owning app is dossiq. The plan named
`opencatalogi/woo-request-scoped-access` for it; that change does not exist on opencatalogi
`development` (checked 2026-10-06).

What dossiq does today, read on `development` at 2026-10-06:

- `wooDocumentAssessment` is shipped by `lib/Settings/register.d/83-woo-document-assessment.json`
  with no `authorization` block at all. Any user who may reach dossiq's register reads and writes
  every assessment of every Woo case through the OpenRegister API.
- `WOODocumentAssessmentService::bulkUpsert()` finds the existing assessment for a document and
  overwrites it with the caller as `assessedBy`, whoever made it before. So a second reviewer
  silently replaces the first reviewer's verdict.
- `WOODocumentAssessmentService::saveRedactionProposal()` writes the redaction proposal and its
  review onto the same assessment, with the same lack of ownership.

What OpenRegister ships on `development` that this change builds on (read 2026-10-06 at 7fa4babdd):

- A schema `authorization` block may carry `"scope": "private"`. A private object admits its owner,
  administrators, its owning group (`ownerGroup` in the object's authorization block) and the
  principals it is shared with, and nobody else
  (`OCA\OpenRegister\Service\Rbac\ObjectScopeResolver`, `SCOPE_PRIVATE`, `OWNER_GROUP_KEY`). The
  pattern is in openregister's own `lib/Settings/flow_register.json`: `scope: private` with
  `read`, `create` and `update` for `authenticated`.
- `OCA\OpenRegister\Service\Rbac\ObjectSharingService::grant(ObjectEntity $object, string $type,
  string $shareWith, int $permissions = 1, array $verbs = []): array`, `revoke(ObjectEntity
  $object, string $shareId): void` and `listGrants(ObjectEntity $object): array`. Types are `user`,
  `group`, `remote`, `remote_group`. Permissions are core's bitmask, mapped by
  `OCA\OpenRegister\Support\PermissionBit::forAction('read' | 'update' | ...)`. Every one of them
  throws `NotAuthorizedException` unless the caller is the object's owner, an administrator or a
  member of its owning group.
- `OCA\OpenRegister\Service\Rbac\ObjectOwnershipService::setOwnerGroup(Register $register, Schema
  $schema, ObjectEntity $object, ?string $group): array`, under the same guard.
- The `@creator` principal REQ-ROD-003 names belongs to `access-owner-and-condition-scopes`, which
  is 0 of 5 on openregister `development`. This change does not wait for it: the private scope,
  the owner admit and per-object grants express the same rule with what is built.

## What changes

1. **The assessment is private.** `wooDocumentAssessment` gets the authorization block
   `{"scope": "private", "read": ["authenticated"], "create": ["authenticated"], "update":
   ["authenticated"], "delete": ["dossiq-coordinators"]}`. The private scope narrows the
   `authenticated` rules to the owner, administrators, the owning group and the grantees.
2. **The people assigned to the request get a grant.** One service,
   `OCA\Dossiq\Woo\WooAssessmentAccess::reconcile(string $caseId): array`, makes the grants of every
   assessment of the case match the assignment: owning group `dossiq-coordinators` (the existing
   `CaseRebindGate::COORDINATOR_GROUP`), a read grant for the case `assignee`, and a read grant for
   every reviewer a `wooReviewBatch` of the case names (once `woo-review-triage` has shipped
   batches). It answers `{status, granted, revoked, refused}`.
3. **A reviewer edits only their own.** The author of an assessment (its owner) and the
   coordinators edit it; everyone else holds read only. `bulkUpsert()` and
   `saveRedactionProposal()` refuse, per document, a change to an assessment someone else made,
   with a reason, before OpenRegister would.
4. **A reviewer can be barred from another's verdicts.** A setting, `woo_review_hide_others`, off
   by default. When on, a batch reviewer gets no grant on an assessment another reviewer made; the
   case assignee and the coordinators still read all of them. The case shows such a document as
   assessed by another reviewer, without its classification, grounds or author.
5. **A change of hands moves the grants.** When a Woo case with assessments changes `assignee`,
   the grants move with it. Only a coordinator or an administrator can make that change, because
   only they may change the grants; anyone else is refused before the case is saved.
6. **Existing assessments.** An administrator-only route reconciles every Woo case once after the
   upgrade. Until it has run, an existing assessment is readable by its author and administrators
   only: the safe state.
7. **The redaction half.** Once `openregister/reviewer-owns-their-decisions` is merged, dossiq
   declares `x-openregister-review` on its document schema, so the entity relation decisions on a
   Woo case's documents follow the same rule.

## What does not change

- `CaseAccessGuard` and the case's own authorization. This change scopes the assessment record.
- The decision and publication flow. The handler and the coordinators read every assessment, so
  `WOODecisionService::assembleDecision()` sees what it sees today.
- OpenRegister. Nothing here is built in OpenRegister.

## Consistency with `woo-review-triage`

`woo-review-triage` REQ-WRT-006 builds on this change. OpenRegister's built
`rbac-inherits-to-children` accepts only a hierarchy parent that references the same schema
(`HierarchyAnnotationValidator`, `hierarchy.foreign-reference`), so no Woo record can name its case
or its batch as parent. REQ-WRT-006 therefore extends `WooAssessmentAccess::reconcile()` with a
grant table for the seven other Woo records and calls it from the routes that create them. This
change stays the owner of `wooDocumentAssessment`.

## Dependencies

- Built, openregister `development`: `object-level-sharing-and-private-scope` (private scope,
  per-object grants, owning group).
- Planned, openregister, wave 1: `reviewer-owns-their-decisions` (issue #4394), for item 7 only.
  Items 1 to 6 do not wait for it.
- Planned, dossiq, wave 2: `woo-request-takes-over-from-opencatalogi` (issue #3289), because the
  import it ships writes assessments and has to land first or be reconciled by item 6.
- Planned, dossiq, wave 3: `woo-review-triage` (issue #3294). Before it lands there are no batch
  reviewers, and only the case assignee and the coordinators are granted.

**App absent.** dossiq does not run without OpenRegister. When `ObjectSharingService` does not
resolve (an OpenRegister older than object-level sharing), `reconcile()` answers `status`
`unavailable`, writes nothing, and the Woo case says that access scoping is not active; the schema
stays private, so the assessments are readable by their authors and administrators only. filinq and
opencatalogi play no part.

## Wave and done

Wave 2. Done means merged on `development` with CI green. Row 12.28 reads `yes` (build) only when
this change and `openregister/reviewer-owns-their-decisions` are both merged, and `production` only
once store releases of both carry them.
