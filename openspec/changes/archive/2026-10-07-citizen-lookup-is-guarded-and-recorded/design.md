# Design: citizen-lookup-is-guarded-and-recorded

## D-1. Why the redaction is in dossiq at all, when ADR-023 says it should not be

`sensitive-fields-declared` put the BSN behind a declared rule and wrote down
that dossiq must not roll its own data RBAC. This change declares the four
contact-moment fields the same way, and then ALSO removes them from the two
lookup responses. That looks like the second evaluator ADR-023 forbids, so the
reason it is not has to be written down rather than assumed.

A declared rule is enforced when OpenRegister renders an object to a caller.
`CaseVoorbladService::getCaseVoorblad()` does not render an object: it reads
rows and composes a new shape (`burgerId`, `openZaken`, `recenteContactmomenten`,
`suggestedTopic`). `ContactMomentService::listForBurger()` reads rows and
returns them under a key of its own. Whether the fields inside survive depends
on which principal the read was made as, and that is exactly the thing this
change must not leave to a guess.

So the declaration is the rule, and the redaction is a second gate over a
payload dossiq built. The redaction NEVER decides: it takes the field list from
one constant and the membership from one group, and it can only remove. A field
it does not know is removed by nothing here and refused by the declaration
there, which is the failure direction worth having.

## D-2. The refusal is logged, and that is the point

Logging successful reveals is the obvious half. The half that catches the
enumeration is the REFUSAL: an account that is refused four hundred times in an
afternoon is not a handler who mistyped. So `CitizenLookupRecorder` is called
on both branches, and `result` distinguishes them
(`succes` / `geweigerd-none-toegang`, both already in the schema's enum).

A recorder that throws would turn an audit outage into a lookup outage, so it
swallows and logs. That is the fail-open every security review flags, and it is
deliberate here for one reason: the act being recorded has ALREADY been
authorised by the guard above it. The record is evidence, not a gate. Where the
record is the gate (it is not, anywhere in this change) it would have to fail
closed.

## D-3. `caseId` stops being required

`sociaalDomeinAuditLog` requires `caseId`, `action` and `moment`. A lookup is
about a citizen and may answer zero cases, or be refused before it reads any,
so there is no case id to write. The schema gains `subjectId` (the citizen
reference the lookup was made with) and drops `caseId` from `required`.

Dropping a required field is backwards-compatible in both directions: every row
that exists still validates, and every writer that sends `caseId` still works.
Adding `subjectId` is additive. The version moves to 1.1.0 because OpenRegister
fast-skips a schema whose version it already holds, and an unimported schema
refuses every row this change tries to write, on a path that swallows.

## D-4. Sixty an hour, and why not ten

`UserRateLimit` is per account, per period, enforced by Nextcloud's own
middleware, which answers 429 without the controller running. The number has to
be above a call handler's real day and far below a population walk.

A KCC agent handles tens of calls in a shift and looks a citizen up once or
twice per call. Sixty an hour leaves headroom for a busy hour and for the page
that fetches the voorblad and the contact list as two calls. The Dutch BRP holds
about 18 million people; at sixty an hour a walk takes thirty-four years.

Ten an hour would be defensible on paper and would break the desk on a Monday
morning, and a limit that breaks the desk is a limit somebody removes.

## D-5. The probe is the account that should be refused

The e2e provisions three principals, because two of the three findings need a
different one:

- an account in NO group, which the guard must refuse, and whose refusal must
  appear in the log;
- an account in `kcc` but NOT in `dossiq-sensitive`, which must receive the
  lookup WITHOUT the four fields. This is the one the old guard got wrong and
  the one an admin read cannot see, because the admin group short-circuits
  every field check before it looks at a field;
- an account in both, as the control, because an absent field and an empty
  fixture are the same JSON.
