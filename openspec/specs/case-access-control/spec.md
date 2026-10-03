---
status: done
---

# case-access-control Specification (dossiq consumer, OpenRegister engine)

**Scope:** dossiq. The permission engine is OpenRegister's.
**Depends on:** OpenRegister grant provenance (`PermissionHandler::provenanceFor()`, openregister#3726), grants that reach a declared hierarchy edge (openregister#3873), access links (openregister#3817), object watchers, and field level property authorization (`PropertyRbacHandler`).
**Capability area:** `access-and-privacy` in `openspec/parity/capabilities.json`.

## Purpose

A handler asks who else can open this dossier, and why. A jurist wants to follow a
case nobody assigned to them. An auditor asks who could read it last March. This
capability is the set of answers dossiq gives, and the line it draws while giving
them.

dossiq evaluates no permission of its own. ADR-022 and decision D22 put the grant on
the object, the object in OpenRegister, and the evaluation there. Two evaluators are
two answers, and the first time they disagree the disagreement is a disclosure.
`tests/Unit/Architecture/NoSecondPermissionEvaluatorTest.php` is what keeps that
true: it scans `lib/` for an effective permission evaluator and fails when one
appears.

What dossiq owns is the zaak shaped half. Which relationship on a case counts, what
a case type declares about departments, roles and confidentiality, which fields a
role keeps, who follows the case, and every surface that shows a person the answer.

This spec is the index of that capability for the features page. Each requirement
names the change that built it rather than restating that change's rules, so there
is one copy of every rule.

## Where the line runs

| Question | Answered by | Rule lives in |
|---|---|---|
| May this account read or change this object | OpenRegister | the grant on the object |
| Is this person named on this case | dossiq | `lib/Service/CaseAccessGuard.php` |
| Which departments and roles does this case type grant, at which confidentiality | dossiq | the case type rights matrix |
| Which fields does a role keep | dossiq declares, OpenRegister enforces | `lib/Service/Access/CaseFieldRoleProjector.php` |
| Which roles a case type knows | dossiq | `roleType` objects, edited on the Roles tab in settings |
| Who belongs to which group | Nextcloud | the platform, not this app |
| Who signed in, with which second factor | Nextcloud | the platform, not this app |

## Requirements

### Requirement: dossiq keeps no permission evaluator of its own (REQ-ACC-01)

dossiq SHALL ask OpenRegister whether an account may act on a case, and SHALL NOT
compute an effective permission, store a copy of a grant, or summarise a provenance
into a value of its own. `lib/Service/Access/OpenRegisterGrantsGateway.php` SHALL
stay the single reader of those answers, and SHALL return `null` rather than an
empty list when OpenRegister cannot answer, so that no caller can read "no answer"
as "granted nothing".

`CaseAccessGuard` SHALL add one question on top: is this account named on this case.
It SHALL fail closed on every branch, including an absent OpenRegister.

Built by `case-grants-name-their-source` and `deelzaken-inherit-the-parent-grants`.

#### Scenario: A colleague with no grant is refused the write
@e2e tests/e2e/deelzaken-inherit-the-parent-grants.spec.ts

- **GIVEN** a case, a deelzaak below it, and an account holding no grant on either
- **WHEN** that account changes a field on the deelzaak
- **THEN** the write SHALL be refused

### Requirement: A grant on a case reaches the cases hanging under it (REQ-ACC-02)

The `case` schema SHALL declare `parentCase` as its hierarchy edge, so a grant on a
case answers for its deelzaken and their deelzaken without a second grant.
`relatedCases` SHALL NOT be an edge: a case somebody linked is not a case somebody
owns. The edge SHALL inherit the read and SHALL NOT inherit the write, so a
colleague let into a parent cannot change what hangs under it.

Built by `deelzaken-inherit-the-parent-grants`, requirement REQ-DZ-20.

#### Scenario: The hierarchy edge reached the instance
@e2e tests/e2e/deelzaken-inherit-the-parent-grants.spec.ts

- **GIVEN** the `case` schema on a running instance
- **WHEN** its configuration is read
- **THEN** it SHALL name `parentCase` as the parent field
- **AND** it SHALL inherit the read verb and no other

### Requirement: A case says who holds which right, and where that right came from (REQ-ACC-03)

A case SHALL be able to list every holder of a right on it with the source of that
holder's grant, read from OpenRegister's provenance. A row SHALL name the rule
behind it rather than only the holder. A reader who may not review access SHALL be
told so, and SHALL NOT be shown an empty panel.

Built by `case-grants-name-their-source`, requirements REQ-CGP-01 and REQ-CGP-05.

#### Scenario: An auditor reads who could open this dossier
@e2e tests/e2e/case-grants-name-their-source.spec.ts

- **GIVEN** a case whose grants come from a role, a group and a share
- **WHEN** an authorised reader opens its access panel
- **THEN** every holder SHALL be listed with the source of their grant

#### Scenario: A reader who may not review access is told so
@e2e tests/e2e/case-grants-history-and-scope.spec.ts

- **GIVEN** a case and a reader without the right to review its access
- **WHEN** that reader opens the access panel
- **THEN** the panel SHALL say they may not review access
- **AND** it SHALL NOT render an empty list of holders

### Requirement: A right may run out, and the row says when (REQ-ACC-04)

A grant that ends SHALL show its last day on the access panel. A case type SHALL be
able to declare that a right it grants ends after a period, so that a stand in
granted access for a holiday loses it when the holiday ends without anybody
remembering to take it away.

Built by `case-grants-name-their-source`, requirements REQ-CGP-07 and REQ-CGP-08.

#### Scenario: A grant that runs out names its last day
@e2e tests/e2e/case-grants-history-and-scope.spec.ts

- **GIVEN** a case carrying a grant with an end date
- **WHEN** a reader opens the access panel
- **THEN** that row SHALL name the day the grant stops

### Requirement: Confidentiality is an axis of the case type, never a role name (REQ-ACC-05)

A case type SHALL declare its rights as a matrix of department by role, held
separately per confidentiality level. Confidentiality SHALL NOT be folded into a
role name, because a vocabulary of `behandelaar-vertrouwelijk` multiplies every
department by every level and no reader can audit the result.

Built by `case-grants-name-their-source`, requirement REQ-CGP-03.

#### Scenario: A case type grants per department, role and confidentiality
@e2e tests/e2e/case-grants-name-their-source.spec.ts

- **GIVEN** a case type granting a department read at the public level only
- **WHEN** the stored rights matrix is read back
- **THEN** the row SHALL carry its confidentiality level as a field of its own
- **AND** no role name SHALL carry a confidentiality level

### Requirement: A case type decides which roles keep a field (REQ-ACC-06)

A case type SHALL declare `fieldRoleRules` naming the field, whether it is hidden or
read only, the roles that lose it and the roles that keep it. dossiq SHALL project
those onto the live `case` schema and SHALL filter no field in its own code, so that
the rule holds on every read path OpenRegister has, including list, search and
export.

The access tab SHALL name the rule behind a field a reader does not get, so that a
gap on the page is explainable rather than a bug report.

Built by `field-rules-declared`, requirements REQ-SEC-FR-1 to REQ-SEC-FR-3.

#### Scenario: A handler does not receive the field the rule hides
@e2e tests/e2e/field-rules.spec.ts

- **GIVEN** a case type hiding a field from handlers and holding it for quality officers
- **WHEN** a member of each group reads the same case
- **THEN** the quality officer SHALL receive the field
- **AND** the handler SHALL NOT receive it

#### Scenario: The access tab names the rule behind the field that is missing
@e2e tests/e2e/field-rules.spec.ts

- **GIVEN** a handler reading a case whose type hides a field from them
- **WHEN** they open the access tab
- **THEN** the tab SHALL name the field, the role that loses it and the role that keeps it

### Requirement: Reading a sensitive field is accountable (REQ-ACC-07)

A field a case type puts behind an extra permission SHALL be readable only by the
roles that hold it, and a read of it SHALL be recorded by the platform. dossiq SHALL
write no audit row of its own for that read.

Built by `sensitive-fields-declared`.

#### Scenario: A reveal leaves a field access row
@e2e tests/e2e/sensitive-fields.spec.ts

- **GIVEN** a person record whose citizen service number is held for one group
- **WHEN** a member of that group reads it
- **AND** the platform carries a field access audit trail
- **THEN** that read SHALL appear in the field access audit trail

### Requirement: Somebody without an account reads a case through a link that can end (REQ-ACC-08)

A case SHALL be shareable with somebody who has no account by minting an
OpenRegister access link over it. OpenRegister SHALL own the expiry, the password,
the revoke and the single not found answer that covers unknown, revoked, paused and
expired alike. dossiq SHALL own only which subject is published and what its holder
may do.

The handler SHALL see the state of every link on the case, so that a link nobody
revoked is visible rather than forgotten.

Built by `case-sharing-mints-access-links`.

#### Scenario: An outsider opens the case without an account
@e2e tests/e2e/case-sharing-mints-access-links.spec.ts

- **GIVEN** a handler who shares a case with somebody outside the organisation
- **WHEN** that person opens the link
- **THEN** they SHALL read the published subject
- **AND** they SHALL NOT need an account

#### Scenario: The handler sees the state of every link on the case
@e2e tests/e2e/case-sharing-mints-access-links.spec.ts

- **GIVEN** a case carrying more than one access link
- **WHEN** the handler opens the sharing tab
- **THEN** each link SHALL show its own state

### Requirement: A colleague follows a case they do not handle (REQ-ACC-09)

A case SHALL offer Follow and Unfollow, taking the platform's subscription of the
signed in user to that case. The Cases page SHALL carry a Followed lens over the
platform's subscribed by me query, and the People tab SHALL list the followers, so
that interest in a case is visible to the person handling it.

Following SHALL grant nothing. It is a subscription, not a right.

Built by `case-followers`, requirement REQ-CM-40.

#### Scenario: Follow survives a reload
@e2e tests/e2e/case-followers.spec.ts

- **GIVEN** a case assigned to somebody else
- **WHEN** you press Follow and reload the page
- **THEN** the case SHALL still be followed

#### Scenario: The People tab names who follows the case
@e2e tests/e2e/case-followers.spec.ts

- **GIVEN** a case you follow
- **WHEN** a handler opens the People tab
- **THEN** you SHALL be listed under the followers

## What this capability does not cover

Stated here rather than left for a reader to discover.

### Answered by a sibling capability

- **Somebody is away, and their work must still be seen.** `handler-vervanging-waarneming`
  registers a vervanging as an object, routes the absent handler's cases and tasks to the
  waarnemer for a bounded period, stamps every act taken in that capacity, and reads leave
  from humaniq (`lib/Service/Substitution/HumaniqLeaveReader.php`). It grants no permission of
  its own, which is why it sits beside this capability rather than inside it.
- **Somebody decides on behalf of somebody else.** The mandaat matrix
  (`openspec/specs/mandaat-matrix/spec.md`, `lib/Service/Mandaat`) resolves who may sign a
  besluit on a given date.
- **A departing handler's caseload.** `CaseReassignmentService` and the coordinator bulk
  reassignment in `handler-vervanging-waarneming` move open work in one previewed, audited
  batch.
- **Which reads were logged, and why.** `avg-verwerkingenlogging` contributes dossiq's
  processing activity catalogue to OpenRegister, which does the logging.

### Not built

- **A locked row for a case you may not open.** A case outside your grants is absent from the
  list, not present and greyed. Nothing says a case exists and is not for you.
- **Sign in and the second factor.** dossiq has no credentials of its own. Two factor
  authentication, password policy and login history are Nextcloud's.
- **A tamper evident audit chain.** `lib/Service/Beschikking/AuditPacketBuilder.php` stamps one
  beschikking export with a digest. No hash links one entry of the trail to the next.
- **A token narrower than the person who issued it.** An API token carries what its issuer
  carries.
- **A right attached to a slot rather than to a person.** Replacing whoever fills a named role
  on a case means editing the grants.
