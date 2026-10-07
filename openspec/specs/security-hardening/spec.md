# security-hardening Specification

## Purpose
TBD - created by archiving change dossiq-security-hardening. Update Purpose after archive.

## Requirements

### Requirement: REQ-PSH-001 — Supplier portal endpoints derive scope from the authenticated session, not a client parameter

The supplier portal SHALL resolve the calling supplier's `supplierRef` from the
server-validated portal session (the bearer JWT), and SHALL NOT trust a client-supplied
`supplierRef` request parameter for authorization. Every supplier-scoped endpoint SHALL
fail CLOSED (HTTP 401) when no valid supplier session is present.

#### Scenario: A read endpoint scopes to the session supplier

- **GIVEN** an authenticated supplier session whose validated JWT carries `supplierRef = A`
- **WHEN** the caller requests the tender list and passes `supplierRef = B` in the query
- **THEN** the endpoint SHALL scope the read to supplier `A` (the session value)
- **AND** the client-supplied `supplierRef = B` SHALL be ignored

@e2e exclude Backend authorization invariant (session-derived supplierRef in SupplierPortalController via SupplierSessionService::requireSupplierRef) verified by PHPUnit; not a UI flow exercisable by a dossiq-only Playwright e2e.

#### Scenario: A request without a valid supplier session is denied

- **GIVEN** a request to a supplier portal endpoint with no valid bearer token
- **WHEN** the endpoint is invoked
- **THEN** it SHALL return HTTP 401
- **AND** it SHALL NOT read or write any supplier-scoped object

@e2e exclude Fail-closed 401 on missing bearer (SupplierAuthMiddleware) is a server middleware contract verified by PHPUnit; no UI surface to exercise in a dossiq-only e2e.

#### Scenario: The supplier auth middleware covers the portal controllers

- **GIVEN** the registered `SupplierAuthMiddleware`
- **WHEN** a supplier portal controller method is dispatched
- **THEN** the middleware SHALL validate the bearer token before the controller body runs
- **AND** it SHALL inject the server-trusted `supplierRef` and role into the request

@e2e exclude Middleware registration/coverage over the three portal controllers is a static wiring assertion (Application.php) verified by PHPUnit + the route-auth gate; not UI-exercisable.

### Requirement: REQ-PSH-002 — The citizen portal uses a guard-prefixed session resolver

The `ZaakportaalController` SHALL resolve the citizen subject through a guard-prefixed
resolver (`requireAuthenticatedSubject()`) that fails closed when no authenticated user is
present, so its server-derived scoping is explicit and recognisable as the IDOR guard.

#### Scenario: An unauthenticated citizen request is denied

- **GIVEN** a citizen portal request with no authenticated Nextcloud user
- **WHEN** a `ZaakportaalController` endpoint resolves the subject
- **THEN** the resolver SHALL throw and the endpoint SHALL return an error response
- **AND** no case, message, request or preference SHALL be read or written

@e2e exclude Citizen-portal fail-closed subject resolver (ZaakportaalController::requireAuthenticatedSubject) is a backend IDOR guard verified by PHPUnit; not a dossiq-only UI flow.

### Requirement: REQ-PSH-003 — KvK and logo validators are wired into their write paths

`SupplierMasterDataMutationService::validateKvk()` SHALL be invoked before a KvK
master-data verification is submitted, and `TenantConfigurationService::validateLogoUpload()`
SHALL be invoked before a tenant logo is persisted. Both SHALL fail closed (reject the
write) on invalid input.

#### Scenario: An invalid KvK number is rejected on the verification path

- **GIVEN** a supplier submitting a KvK master-data verification with a malformed KvK number
- **WHEN** the verification is submitted
- **THEN** `validateKvk()` SHALL run and the submission SHALL be rejected
- **AND** no verification case SHALL be created

@e2e exclude KvK validator wiring on the write path (SupplierMasterDataMutationService) is server-side input validation verified by PHPUnit; not UI-exercisable.

#### Scenario: An oversized or wrong-type logo is rejected on the branding path

- **GIVEN** a tenant branding update carrying a logo of a disallowed MIME type or over the size cap
- **WHEN** the branding is sanitised and saved
- **THEN** `validateLogoUpload()` SHALL run and the save SHALL be rejected
- **AND** the offending logo SHALL NOT be persisted

@e2e exclude Logo MIME/size validator wiring on the branding write path (TenantConfigurationService::sanitiseBranding) is server-side validation verified by PHPUnit; not UI-exercisable.

### Requirement: REQ-PSH-004 — Auth/role resolvers fail closed on backend errors

The auth/role resolvers SHALL NOT silently fall open: when the backing lookup throws, the
resolver SHALL NOT return `null` from inside the `catch (\Throwable)` block as if the role
were simply absent. The single caller SHALL treat a resolver failure as DENY.

#### Scenario: A transient backend error denies the role-gated action

- **GIVEN** the role lookup backing `resolveUserRole()` raises a `\Throwable`
- **WHEN** the mandate-matrix validation resolves the caller's role
- **THEN** the action SHALL be denied (`allowed = false`)
- **AND** the failure SHALL be logged as fail-closed

@e2e exclude Fail-closed-on-Throwable in the role resolver (TenantAuthenticationService::resolveUserRole) is a backend auth-resolver contract verified by PHPUnit + the unsafe-auth-resolver gate; not UI-exercisable.

### Requirement: REQ-PSH-005 — DSO dialogs are isolated, not inlined

The DSO omgevingsloket dialogs SHALL live as isolated components under `src/dialogs/`, and
the stale inline-`NcModal` duplicates under `src/views/dso/` SHALL be removed. The only
external consumer SHALL import the canonical isolated dialog.

#### Scenario: The dashboard uses the canonical isolated dialog

- **GIVEN** the VTH dashboard opening a DSO case detail
- **WHEN** the dashboard renders the case-detail dialog
- **THEN** it SHALL import the canonical component from `src/dialogs/`
- **AND** the stale `src/views/dso/` inline-modal duplicates SHALL NOT remain in the tree

@e2e exclude Modal-isolation refactor (canonical src/dialogs/DsoCaseDetail.vue, stale src/views/dso/ duplicates removed) is a static structure assertion verified by the modal-isolation gate + vitest; not a behavioural UI flow.

### Requirement: Sensitive fields are declared behind an extra group (REQ-SEC-SF-1)

Every BSN field and every special-category field on dossiq's schemas SHALL
carry a `row-field-level-security` field rule readable by group
`dossiq-sensitive` only, with the reveal audited by OpenRegister.
`CitizenLookupGuard` SHALL NOT duplicate the field check.

#### Scenario: A handler outside the group does not see the BSN
@e2e tests/e2e/sensitive-fields.spec.ts

- **GIVEN** a handler not in `dossiq-sensitive`
- **WHEN** they open a case with a requester BSN
- **THEN** the BSN field SHALL be hidden

#### Scenario: A reveal is audited
@e2e tests/e2e/sensitive-fields.spec.ts

- **GIVEN** a member of `dossiq-sensitive`
- **WHEN** they reveal the BSN
- **THEN** OpenRegister's audit SHALL hold a field-access row for it

#### Scenario: The guard no longer decides
@e2e exclude structural; a unit test asserts CitizenLookupGuard evaluates no declaration

- **GIVEN** `CitizenLookupGuard`
- **WHEN** its source is read
- **THEN** it SHALL NOT read a schema, a property or an `authorization` block
- **AND** it SHALL NOT grant a field the declaration withholds

SHARPENED 2026-09-18 by `citizen-lookup-is-guarded-and-recorded`. The first
wording was "none of its methods SHALL check a field's group", and that change
adds `redactForCaller()`, which checks exactly one group against one constant
list to take four keys OUT of a payload dossiq composed itself. That is not the
duplication this requirement forbids: the thing to forbid is a SECOND EVALUATOR
of OpenRegister's declaration, which would eventually disagree with it and be
"fixed" in whichever direction was easier. A remove-only list that can never
grant is the opposite failure mode, and the wording above says which one is
meant.

### Requirement: A case type declares which roles keep a field and which lose it (REQ-SEC-FR-1)

A case type SHALL declare `fieldRoleRules` beside its rights matrix. A rule
SHALL name a `field`, a `rule` of `hidden` or `readOnly`, the groups it
`groups` (the roles that lose the field) and the groups that `heldBy` (the
roles that keep it), and MAY carry a `reason`. The two lists SHALL NOT be
derived from one another: the lifecycle reads a deny list and a property
authorization block reads an allow list, and the set of groups on the server
is not something a case type can know. A group named in both lists SHALL lose
the field.

No dossiq code SHALL filter these fields.

#### Scenario: A handler does not receive the field the rule hides
@e2e tests/e2e/field-rules.spec.ts

- **GIVEN** a case type hiding `qualityScore` from `behandelaars` and holding it for `dossiq-quality`
- **WHEN** a member of each group reads the same case
- **THEN** the member of `dossiq-quality` SHALL receive `qualityScore`
- **AND** the member of `behandelaars` SHALL NOT receive it

#### Scenario: A handler is refused the change the rule freezes
@e2e tests/e2e/field-rules.spec.ts

- **GIVEN** the same case type marking `confidentiality` read only for `behandelaars`
- **WHEN** a member of `behandelaars` changes it
- **THEN** the save SHALL be refused, naming the field
- **AND** a member of `dossiq-quality` SHALL still be able to change it

#### Scenario: A rule restricting nobody is not published
- **GIVEN** a rule whose `groups` list normalises to empty
- **WHEN** the case type is published
- **THEN** no lifecycle entry SHALL be written for it
- @e2e exclude {asserted in tests/Unit/Service/Access/FieldRoleRuleDeclarationTest.php::testARuleRestrictingNobodyPublishesNoLifecycleEntry; an entry with no groups applies to everyone including administrators}

#### Scenario: A rule naming no holder is not published
- **GIVEN** a rule whose `heldBy` list normalises to empty
- **WHEN** the case type is published
- **THEN** no property authorization block SHALL be written for it
- @e2e exclude {asserted in tests/Unit/Service/Access/FieldRoleRuleDeclarationTest.php::testARuleNamingNoHolderPublishesNoPropertyBlock; an empty allow list strips the field for every non-administrator}

### Requirement: The declaration is projected onto the case schema at publish (REQ-SEC-FR-2)

Publishing a case type SHALL write its role rules onto the live `case` schema:
into `x-openregister-lifecycle.states.<statusTypeUuid>.fields` for every state
the type declares, and onto `properties.<field>.authorization` for the read and
the write. Both writes SHALL merge and SHALL NOT replace: another case type's
rules, and the authorization the register JSON declares, SHALL survive. A rule
the case type no longer declares SHALL be removed, and a property left with no
grants SHALL lose its `authorization` key rather than keep an empty one.

The property block SHALL be re-applied after the register import, which
rewrites a schema's properties.

#### Scenario: Another case type's grants survive a publish
- **GIVEN** two case types declaring rules on the same case schema
- **WHEN** one of them is published
- **THEN** the other's grants SHALL still stand
- @e2e exclude {asserted in tests/Unit/Service/Access/CaseFieldRoleProjectorTest.php::testAnotherCaseTypesGrantsSurviveAPublish}

#### Scenario: The register's own grant survives a withdrawal
- **GIVEN** `riskAssessment` restricted by the register JSON and a case type withdrawing its own rule
- **WHEN** the case type is published
- **THEN** the register's grant SHALL still stand
- @e2e exclude {asserted in tests/Unit/Service/Access/CaseFieldRoleProjectorTest.php::testTheRegistersOwnGrantSurvivesAWithdrawal}

#### Scenario: A role rule applies in every state the type declares
- **GIVEN** a case type with two statuses and one role rule
- **WHEN** it is published
- **THEN** both states SHALL carry the rule
- @e2e exclude {asserted in tests/Unit/Service/Status/CaseStateFieldRuleProjectorTest.php::testARoleRuleLandsInEveryState; a rule carried by one state only lets a handler read the field by moving the case along}

### Requirement: The access tab names the rule behind a field that is not there (REQ-SEC-FR-3)

The access tab SHALL list the case type's role rules, each with the field, what
the rule does, the groups it affects, the groups that keep the field and the
reason its author wrote. A row SHALL be marked as applying to the reader only
when the case's `@self.fieldRules` names that field under that rule. The tab
SHALL NOT work out for itself whether a rule applies.

#### Scenario: The tab names the rule behind the gap
@e2e tests/e2e/field-rules.spec.ts

- **GIVEN** a handler reading a case whose type hides `qualityScore` from them
- **WHEN** they open the access tab
- **THEN** the tab SHALL name the field, the group that loses it and the group that keeps it

#### Scenario: An instance that answered nothing is not marked as applying
- **GIVEN** a case carrying no `@self.fieldRules` at all
- **WHEN** the rows are built
- **THEN** no row SHALL be marked as applying to the reader
- @e2e exclude {asserted in tests/vitest/fieldRoleRules.spec.js; an older OpenRegister answers nothing, and inventing "this does not apply to you" is the one thing the panel exists to report}

### Requirement: A citizen lookup answers only the fields the caller may read (REQ-SEC-CL-1)

`callerIdentification`, `geidentificeerdeBurgerId`, `summary` and
`transcript` on a contact moment SHALL be readable by the group
`dossiq-sensitive` only. The declaration SHALL be on the `contactmoment`
schema, so a direct read of the object is refused, AND the same four fields
SHALL be absent from the responses of the citizen-lookup endpoints, which
compose a shape of their own out of rows they have already read.

The redaction SHALL only remove. It SHALL NOT decide which fields are
sensitive by any rule other than the one list, and it SHALL NOT grant a field
the declaration withholds.

#### Scenario: A call handler outside the group receives the lookup without the four fields
@e2e tests/e2e/citizen-lookup.spec.ts

- **GIVEN** an account in `kcc` and not in `dossiq-sensitive`
- **WHEN** they fetch the contact moments of an identified citizen
- **THEN** the response SHALL list the contact moments
- **AND** no entry SHALL carry `callerIdentification`, `geidentificeerdeBurgerId`, `summary` or `transcript`

#### Scenario: A member of the group receives them
@e2e tests/e2e/citizen-lookup.spec.ts

- **GIVEN** an account in both `kcc` and `dossiq-sensitive`
- **WHEN** they fetch the same contact moments
- **THEN** the entries SHALL carry the four fields with the values the fixture wrote

#### Scenario: The voorblad is redacted the same way
@e2e exclude {the same redaction over the same constant; asserted in tests/Unit/Service/CitizenLookupGuardTest.php::testTheVoorbladContactMomentsAreRedactedToo}

- **GIVEN** the account outside the group
- **WHEN** they fetch the voorblad of that citizen
- **THEN** the `recenteContactmomenten` entries SHALL carry none of the four fields

### Requirement: A citizen lookup is rate limited per account (REQ-SEC-CL-2)

Every endpoint that resolves a citizen identifier SHALL carry a per-user rate
limit of at most 60 requests per hour, enforced by Nextcloud's own middleware
before the controller runs. A caller over the limit SHALL receive HTTP 429.

The limit SHALL be high enough that a call handler's working hour does not
reach it and low enough that enumerating a citizen register is not a thing
one account can do.

#### Scenario: The limit is declared on every lookup endpoint
@e2e exclude {an attribute on a controller method; asserted in tests/Unit/Controller/CitizenLookupRateLimitTest.php}

- **GIVEN** the controller methods that take a citizen identifier
- **WHEN** their attributes are listed
- **THEN** each SHALL carry `UserRateLimit` with a period of 3600 and a limit of at most 60

#### Scenario: An account over the limit is refused
@e2e tests/e2e/citizen-lookup.spec.ts

- **GIVEN** an account permitted to look a citizen up
- **WHEN** it makes more lookups in an hour than the limit allows
- **THEN** the further requests SHALL answer 429 and SHALL NOT read the citizen

### Requirement: Every citizen lookup is recorded, refusals included (REQ-SEC-CL-3)

Every attempt to resolve a citizen identifier SHALL write one
`sociaalDomeinAuditLog` row naming the account, the moment, the citizen
reference, the fields answered, the ground the caller was allowed on, and the
result. A REFUSED attempt SHALL be recorded too, with result
`geweigerd-none-toegang`.

Recording SHALL NOT be able to fail the lookup: the act has already been
authorised, so the record is evidence and not a gate.

`sociaalDomeinAuditLog` SHALL carry `subjectId`, and SHALL NOT require
`caseId`, because a lookup is about a citizen and may answer no case at all.

#### Scenario: A permitted lookup leaves a row naming the account and the citizen
@e2e tests/e2e/citizen-lookup.spec.ts

- **GIVEN** an account permitted to look a citizen up
- **WHEN** it fetches that citizen's contact moments
- **THEN** a `sociaalDomeinAuditLog` row SHALL name that account, that citizen and result `succes`

#### Scenario: A refused lookup leaves a row too
@e2e tests/e2e/citizen-lookup.spec.ts

- **GIVEN** an account in none of the permitted groups
- **WHEN** it fetches that citizen's contact moments and is refused
- **THEN** a `sociaalDomeinAuditLog` row SHALL name that account with result `geweigerd-none-toegang`

#### Scenario: An audit outage does not become a lookup outage
@e2e exclude {the sink is made to throw, which no instance does on request; asserted in tests/Unit/Service/Kcc/CitizenLookupRecorderTest.php::testAFailedWriteDoesNotReachTheCaller}

- **GIVEN** an OpenRegister that refuses the audit write
- **WHEN** a permitted lookup is made
- **THEN** the lookup SHALL answer as it would have, and the failure SHALL be logged

### Requirement: The guard says what it does (REQ-SEC-CL-4)

`CitizenLookupGuard` SHALL decide endpoint access and nothing else, and its
documentation SHALL say so and name the classes that hold the other two
halves. It SHALL NOT claim to protect a field.

#### Scenario: The claim matches the class
@e2e exclude structural; a unit test asserts the guard's methods and what its documentation claims

- **GIVEN** `CitizenLookupGuard`
- **WHEN** its methods are listed
- **THEN** none SHALL check a field's group
- **AND** its documentation SHALL name the recorder and the rate limit
