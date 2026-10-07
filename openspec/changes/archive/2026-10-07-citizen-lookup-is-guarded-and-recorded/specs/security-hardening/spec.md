## ADDED Requirements

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
